<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityTurnFeed;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationMessageWriter;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentCallTaskOutcome;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallerRequestSentence;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallGoodbyeHangUp;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceQueuedDelegation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSpokenAnswer;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceTaskProgressSchedule;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptAssembler;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptBacklogSentence;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptSentence;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSummary;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Domain\AI\Exceptions\Voice\LiveSessionGoneException;
use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceMessengerTransports;
use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachMessage;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\AI\Services\Voice\AgentCallTasksService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\AgentVoiceSessionContextsService;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Throwable;

/**
 * The worker of ONE GPT-Live call: the process that stays with the call from its start to its end. GPT-Live is
 * server-driven: the voice model starts and ends its own turns, so this worker never forces speech, never runs a tool
 * on the voice model (it holds none), and never replaces instructions (they are append-only). What it does instead:
 *
 * - attach to the session's event channel (the sideband WebSocket) once the session exists (the device's media leg
 *   comes up in parallel — nothing sequences the two, and nothing needs to);
 * - answer `session.delegation.created` by running the agent on the call's conversation through the
 *   {@see AgentCallTasksService} bridge, return the answer as `session.commentary.append`, and say what the agent
 *   does while the turn runs ({@see AgentVoiceTaskProgressSchedule});
 * - assemble the transcript deltas into sentences ({@see LiveTranscriptAssembler}) and write them as conversation rows;
 * - book the billed seconds from the cumulative `session.usage.updated` counter ({@see bookLiveSessionSeconds()});
 * - ask every `admissionRecheckSeconds` whether the call may go on ({@see AgentVoiceCallAdmissionService}), and end
 *   the call when it is refused (an allowance is used up, or the call has lasted the longest a call may last), when
 *   nobody speaks, when the agent or the owner hung up, when the call was ended from outside, or when the event
 *   channel died silently.
 *
 * The audio stays on the device's primary connection; the sideband carries events only. Audio-typed events are still
 * dropped before logging — a call's worth of PCM must never land in the voice log, whatever a future dialect sends.
 *
 * The fields of this service are the state of the ONE call the process accompanies;
 * {@see resetStateOfThePreviousCall()} puts them back at the start of every attach, so a worker process of the
 * transport's pool ({@see AgentVoiceMessengerTransports}) may accompany one call after another. A SIGTERM that
 * arrives during a call does not cut it; it is passed on to the process when the call is over
 * ({@see endDrainModeOfTheCall()}).
 *
 * The worker survives its own death: the attach message is acknowledged only when the call is over, a lease in the
 * shared cache keeps two workers off one session, and a successor books from the stored baseline.
 *
 * Override points for an application's same-path subclass:
 * - a medium of its own (a person the application has not let through to the agent yet, events only that medium
 *   sends) — each does nothing here: {@see prepareApplicationStateOfAttachedCall()},
 *   {@see sendApplicationOpeningAfterSessionStarted()}, {@see answerDelegationWithoutTheAgent()},
 *   {@see applyApplicationSessionEvent()}, {@see keepCompletedSentenceOutOfTheConversation()},
 *   {@see isSpeechSilenceGuardSuspendedByTheApplication()}, {@see finishApplicationPartOfTheCall()},
 *   {@see prepareTracePayload()};
 * - what the voice model is told: {@see buildSpeechSilenceCheckInstruction()},
 *   {@see buildSpeechSilenceGoodbyeInstruction()}, {@see buildRefusedCallGoodbyeInstruction()},
 *   {@see buildKeepTheCallLanguageClause()} (the texts are the prompts of {@see AgentVoiceSessionContextsService});
 * - where the voice log is written: {@see getVoiceLogFilePath()}; the provider's adapter:
 *   {@see getVoiceProviderAdapterOfCall()}; the connector of the sideband:
 *   {@see buildSidebandWebSocketConnector()}; every service through its `get…()` method.
 *
 * Who pays for a call, and how its session is described to the usage recorder, is the admission service's
 * ({@see AgentVoiceCallAdmissionService::buildSessionModelCallOfRunningCall()}: the model call the running-call check
 * asks the gate with), as are the stored names of a session (usage scope, envelope scope, prompt name); the trace
 * event of the agent's first words is {@see AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT}.
 */
class AgentLiveVoiceSessionsService extends Service
{
    /** @var int Seconds between two runs of the watcher ({@see runWatcherTick()}). */
    protected const int WATCHER_TICK_SECONDS = 2;

    /** @var int "Are you still there" nudges per call before the line is given up — a nudge makes the agent speak, which restarts the clock. */
    protected const int SPEECH_SILENT_MAX_NUDGES = 3;

    /**
     * @var int Seconds a delegated task may sit with the conversation IDLE and no answer before it counts as LOST
     * (the turn worker never picked it up: a worker on a stale container can die on the message, and the redelivery
     * guard then drops it). One re-drive, then an honest failure to the caller.
     */
    protected const int DELEGATED_TASK_LOST_AFTER_SECONDS = 75;

    /**
     * @var int Seconds without caller speech after a backend answer that ended in a QUESTION before the model is asked
     * to put the question once more — a short answer spoken into the agent's last syllable is dropped by the
     * transcript, and a caller who did not hear the question is not "gone".
     */
    protected const int UNANSWERED_QUESTION_REASK_SECONDS = 20;

    /** The trace event of an armed goodbye hang-up (`endVoiceCall` on this call). */
    public const string TRACE_EVENT_TYPE_GOODBYE_HANG_UP_ARMED = 'call.goodbye_hang_up_armed';

    /** The trace event of the hang-up after the agent's goodbye. */
    public const string TRACE_EVENT_TYPE_GOODBYE_HANG_UP_DONE = 'call.goodbye_hang_up_done';

    /** The trace event of one progress update sent while a task runs: spoken, silent or bridge. */
    public const string TRACE_EVENT_TYPE_TASK_PROGRESS_SENT = 'task.progress_sent';

    /** The trace event of an agent sentence that was a progress sentence — written instead of a chat row. */
    public const string TRACE_EVENT_TYPE_TASK_PROGRESS_SPOKEN = 'task.progress_spoken';

    /** The trace event of the delegation → first narrated activity line latency, once per task. */
    public const string TRACE_EVENT_TYPE_ACTIVITY_FEED_LATENCY = 'activity.feed_latency';

    /** `kind` of a {@see TRACE_EVENT_TYPE_TASK_PROGRESS_SENT} event: a progress sentence handed to the voice as commentary. */
    public const string TASK_PROGRESS_KIND_SPOKEN = 'spoken';

    /** `kind`: unspoken context (`session.thinking.append`). */
    public const string TASK_PROGRESS_KIND_SILENT = 'silent';

    /** `kind`: the generic "still working" bridge (`session.instructions.append`). */
    public const string TASK_PROGRESS_KIND_BRIDGE = 'bridge';

    /**
     * @var float Seconds between two runs of the fast check ({@see runGoodbyeAndTaskOutcomeCheck()}): the goodbye
     * hang-up's due-check and the delegated task's outcome poll. On the 2 s watcher tick they would add up to 2 s
     * before the answer is spoken and up to 2 s after the goodbye ended. The watcher keeps its own cadence: it counts
     * ticks for the external-end check, the memory log, the greeting request and the lease.
     */
    protected const float GOODBYE_AND_TASK_OUTCOME_CHECK_INTERVAL_SECONDS = 0.5;

    /** @var int Watcher ticks (2s each) between two re-reads of the call row — the external-end backstop. */
    protected const int EXTERNAL_END_CHECK_EVERY_TICKS = 5;

    /** @var int Seconds granted for the agent's goodbye sentence before the session is actually hung up. */
    protected const int FAREWELL_GOODBYE_SECONDS = 6;

    /**
     * @var int Watcher tick at which the model is asked to open the call if it has not spoken yet. GPT-Live
     * decides its own turns, and whether it greets unprompted is not documented — one appended request at ~4s is the
     * safe way to make it open the call.
     */
    protected const int GREETING_NUDGE_AT_TICK = 2;

    /** @var int How many of the caller's completed sentences are kept for composing the next delegation's task. */
    protected const int RECENT_CALLER_SENTENCES_KEPT = 8;

    /**
     * @var int What the provider bills for a WebRTC session creation while it initialises — credited against duration
     * once the session runs, but charged in full when it never does. Booked once when a call ends without any
     * usage event having arrived.
     */
    protected const int SESSION_INITIALISATION_CHARGE_SECONDS = 15;

    /** @var int Sideband connect attempts before the call row is given up — the device leg is up, a lost attach must not end it. */
    protected const int SIDEBAND_CONNECT_ATTEMPTS = 3;

    /**
     * @var int Seconds the attach lease lives without a refresh. The watcher refreshes it every tick (2 s); a
     * worker that died (container restart, fatal) stops refreshing, and after this many seconds its successor —
     * the redelivered attach message — may take the session over.
     */
    protected const int ATTACH_LEASE_TTL_SECONDS = 15;

    /** @var int Seconds a successor waits for a stale lease to expire before it concludes the holder is alive ({@see ATTACH_LEASE_TTL_SECONDS} + 5). */
    protected const int ATTACH_LEASE_WAIT_SECONDS = 20;

    /** @var string|null The owner token of the attach lease this worker holds (`Cache::acquireLock()`), null without one. */
    protected ?string $attachLeaseToken = null;

    /** @var bool The lost lease was logged once for this call — the worker keeps the call, the log line need not repeat every tick. */
    protected bool $attachLeaseLossLogged = false;

    /** @var int Seconds the billed-seconds baseline outlives its last update — longer than any call (expires_at is one hour). */
    protected const int BILLED_SECONDS_BASELINE_TTL_SECONDS = 4 * 3600;

    /** @var int Watcher ticks between two memory lines in the voice log (2 s per tick → one line a minute). */
    protected const int MEMORY_LOG_EVERY_TICKS = 30;

    /** @var int Seconds back from the delegation moment within which the caller's sentences count as the task. */
    protected const int TASK_CONTEXT_WINDOW_SECONDS = 45;

    /** @var AIConversation|null The call's conversation, loaded once per call. */
    protected ?AIConversation $attachedAiConversation = null;

    /** @var int|null Unix time of the LAST sideband event received — the liveness signal. */
    protected ?int $lastSessionEventReceivedAtUnixTime = null;

    /** @var int Ticks counted for the external-end re-read. */
    protected int $externalEndCheckTickCount = 0;

    /** @var int Ticks counted for the admission re-read ({@see endCallWhenItIsRefused()}). */
    protected int $admissionRecheckTickCount = 0;

    /** @var int Ticks since the sideband attached — drives the one-time greeting request. */
    protected int $ticksSinceAttached = 0;

    /** @var bool Whether the agent has produced any output transcript yet — once true, no greeting request is sent. */
    protected bool $agentHasSpoken = false;

    /** @var int Output-transcript characters so far — a grunt ("Mm-hmm.") is not an opening. */
    protected int $agentSpokenCharacterCount = 0;

    /** @var int Output characters that count as the agent having spoken; below it the greeting request still goes out. */
    protected const int AGENT_HAS_SPOKEN_MIN_CHARACTERS = 25;

    /** @var bool The greeting request was sent (at most once per call). */
    protected bool $greetingRequestSent = false;

    /** @var LiveTranscriptAssembler The delta → sentence assembler for this call. */
    protected LiveTranscriptAssembler $liveTranscriptAssembler;

    /**
     * @var AgentVoiceCallerRequestSentence[] The caller's most recent completed sentences — the delegation event
     * carries NO task text, so the task is composed from these.
     */
    protected array $recentCallerSentences = [];

    /** @var string|null The delegation currently being worked on (its result is appended under this id). */
    protected ?string $pendingDelegationId = null;

    /**
     * @var int Unix milliseconds up to which the caller's sentences were already handed to a task — the next
     * delegation takes only what was said AFTER this, so a second request never re-sends the first one.
     */
    protected int $callerSentencesConsumedUntilUnixMs = 0;

    /** @var int|null Index of the pending agent task's USER message; null = no task running. */
    protected ?int $pendingAgentTaskAfterMessageIndex = null;

    /**
     * @var AgentVoiceQueuedDelegation|null A delegation that arrived WHILE a task ran — the caller's answer to
     * the result still being computed, typically. One task at a time, but never dropped: it starts the moment the
     * pending result is spoken, and the request window reaches back to when it was queued.
     */
    protected ?AgentVoiceQueuedDelegation $queuedDelegation = null;

    /** @var int|null Unix time a spoken backend answer ended in a question — cleared by the caller's next word. */
    protected ?int $callerAnswerAwaitedSinceUnixTime = null;

    /** @var bool The one re-ask for the currently awaited answer went out. */
    protected bool $unansweredQuestionReasked = false;

    /**
     * @var LiveTranscriptBacklogSentence[] Transcript sentences held back WHILE an agent task runs (the turn worker
     * owns the message index sequence then). {@see LiveTranscriptBacklogSentence::$isProgress}: an agent sentence
     * classified as a progress sentence when it was pushed ({@see isProgressSentence()}) — flushed as a trace event,
     * never as a chat row.
     */
    protected array $transcriptBacklog = [];

    /**
     * @var int The seconds of the cumulative usage counter whose usage is recorded (or that nobody can be billed for)
     * — increments are billed, never sums, and a second is recorded as usage once.
     */
    protected int $billedSessionSeconds = 0;

    /**
     * @var int Seconds counted in {@see $billedSessionSeconds} that the call has not taken yet: a write of the call
     * that failed. They go with the next write of the call ({@see addOwedBookedSecondsToTheCall()}). Held by this
     * process only: a successor worker does not know them.
     */
    protected int $bookedSecondsOwedToTheCall = 0;

    /** @var bool A farewell went out (a refused call, a silent line) and the hang-up timer is armed. */
    protected bool $farewellHangUpArmed = false;

    /**
     * @var AgentVoiceCallGoodbyeHangUp|null The agent ended this call with `endVoiceCall` while on it: the hang-up waits
     * until the turn's answer (the goodbye) was handed over and spoken ({@see hangUpAfterRequestedGoodbyeWhenDue()}).
     */
    protected ?AgentVoiceCallGoodbyeHangUp $requestedGoodbyeHangUp = null;

    /** @var int|null Unix milliseconds the agent's last output transcript fragment arrived — when the voice last produced speech. */
    protected ?int $lastAgentSpeechAtUnixMs = null;

    /** @var int|null Unix milliseconds a delegated turn's answer was last handed to the voice model (`session.commentary.append`). */
    protected ?int $lastAnswerHandedOverAtUnixMs = null;

    /** @var TimerInterface|null The 0.5 s timer of {@see runGoodbyeAndTaskOutcomeCheck()}; cancelled once the call is finished. */
    protected ?TimerInterface $goodbyeAndTaskOutcomeTimer = null;

    /** @var int|null Unix time of the last transcript delta from either side — the speech-silence clock. */
    protected ?int $lastSpeechAtUnixTime = null;

    /**
     * @var int|null Unix milliseconds of the last transcript delta from either side — the quiet test of the progress
     * schedule. The GPT-Live sideband sends no speaking-state event (no speech start/stop, no output-audio event): the
     * caption deltas are the only sign that somebody speaks.
     */
    protected ?int $lastSpeechAtUnixMs = null;

    /**
     * @var AgentVoiceTaskProgressSchedule|null What the caller hears while the pending task runs — started with every
     * delegated task, dropped at the hand-over; null while no task runs.
     */
    protected ?AgentVoiceTaskProgressSchedule $taskProgressSchedule = null;

    /**
     * @var int|null The transcript clock ({@see LiveTranscriptAssembler::getLastFragmentEndMs()}) when the newest progress
     * commentary of the pending task was sent; null = none outstanding (none sent, or the task's result was handed
     * over since). An agent sentence that STARTED after it while the task runs is a progress sentence.
     */
    protected ?int $progressCommentarySentAtSessionMs = null;

    /** @var bool The one "are you still there" nudge of the speech-silence guard was sent. */
    protected bool $speechSilenceNudgeSent = false;

    /** @var int Nudges sent so far on this call. */
    protected int $speechSilenceNudgeCount = 0;

    /** @var int|null Unix time the pending delegated task was started (or re-driven) — the lost-task clock. */
    protected ?int $pendingAgentTaskStartedAtUnixTime = null;

    /** @var bool The pending task was already re-driven once after being found lost. */
    protected bool $pendingAgentTaskRedriven = false;

    /**
     * @var bool The conversation was last seen RUNNING by a turn this worker did not start (the supervisor, a
     * yield-continue, the chat) — sentences are held back until the run settles, exactly as for an own task.
     */
    protected bool $foreignTurnObservedRunning = false;

    /** @var bool `session.started` was seen on the sideband — application commands may go out from here. */
    protected bool $sessionStartedReceived = false;

    /** @var bool At least one usage event arrived — the initialisation charge is then inside the counter. */
    protected bool $usageEventReceived = false;

    /** @var bool The call row was ended by this worker or read as ended — every exit path checks it once. */
    protected bool $callFinished = false;

    /**
     * @var AIConversationTraceEvent[] Conversation-trace events not written yet — flushed as ONE batched insert per
     * watcher tick and at every exit, never on the event path itself.
     */
    protected array $traceEventBuffer = [];

    /** @var int The sequence of this worker's own trace events — with {@see $traceWorkerToken} their idempotence key. */
    protected int $traceEventSequence = 0;

    /** @var string Minted at every attach, so the sequence of a successor worker, or of a second attach by this process, never collides with an earlier one. */
    protected string $traceWorkerToken = '';

    /** @var bool The agent's first output transcript was traced (the "first word" mark of the call header). */
    protected bool $firstAgentTranscriptTraced = false;

    /** @var AgentVoiceCall|null The call this worker process accompanies — what the event loop's callbacks act on. */
    protected ?AgentVoiceCall $attachedAgentVoiceCall = null;

    /** @var Connector|null Opens the sideband WebSocket; kept for the connect retries. */
    protected ?Connector $sidebandWebSocketConnector = null;

    /** @var WebSocket|null The open sideband of the attached call; null until a connect succeeded. */
    protected ?WebSocket $sidebandWebSocketConnection = null;

    /** @var int Sideband connect attempts made so far for the attached call. */
    protected int $sidebandConnectAttemptCount = 0;

    /** @var Throwable|null The connect failure the worker gave up on; null while connecting or connected. */
    protected ?Throwable $sidebandConnectFailure = null;

    /**
     * @var bool The event loop of the call was told to stop ({@see stopEventLoopOfTheCall()}). The loop forgets a stop
     * it is told before it runs, so the attach reads this before it enters the loop.
     */
    protected bool $eventLoopStopRequested = false;

    /** @var string|null The end reason the call gets once the armed farewell was spoken ({@see armFarewellHangUp()}). */
    protected ?string $farewellHangUpEndReason = null;

    /** @var TimerInterface|null The watcher's timer ({@see runWatcherTickOfAttachedCall()}); cancelled when the call's loop has ended. */
    protected ?TimerInterface $watcherTimer = null;

    /** @var TimerInterface|null The timer of an armed farewell ({@see hangUpAfterFarewellWasSpoken()}). */
    protected ?TimerInterface $farewellHangUpTimer = null;

    /**
     * @var callable|int|null What the process did on SIGTERM before the drain listener of the call took its place (a
     * consumer's own stop handler, or the default); null while no drain listener is installed.
     */
    protected mixed $terminationSignalHandlerBeforeTheCall = null;

    /** @var bool Whether a SIGTERM arrived while the call ran; it is passed on when the call is over ({@see endDrainModeOfTheCall()}). */
    protected bool $terminationSignalDrained = false;

    public function __construct()
    {
        $this->liveTranscriptAssembler = new LiveTranscriptAssembler();
        $this->traceWorkerToken = bin2hex(random_bytes(4));
    }

    /**
     * Accompany one running call for its whole duration.
     *
     * `$async = true` (the path of the route that opens the call) dispatches {@see VoiceLiveSessionAttachMessage} and
     * returns at once — the device is waiting for its SDP answer; `async: false` (the Messenger handler) runs the
     * sideband loop right here and BLOCKS this worker for the call's lifetime. A failed CONNECT ends the call
     * ({@see AgentVoiceCall::END_REASON_FAILED}) and rethrows so Messenger retries visibly; a connect that gave up
     * before the loop ran (a failure the connector answers at once) does not enter the loop. Whatever way the attach
     * ends, the lease, the envelope and what the call put on the event loop (the SIGTERM listener too) are released.
     *
     * @throws InternalErrorException
     */
    public function attachToCallSession(AgentVoiceCall $agentVoiceCall, bool $async = false): void
    {
        if ($async) {
            (new VoiceLiveSessionAttachMessage((string)$agentVoiceCall->providerCallId))->dispatch();

            return;
        }

        $this->resetStateOfThePreviousCall();
        $providerSessionId = (string)$agentVoiceCall->providerCallId;
        if (!$this->acquireAttachLease($providerSessionId)) {
            $this->appendToVoiceLog(
                "LIVE {$providerSessionId} attach skipped — another worker holds this session's lease"
            );

            return;
        }
        $sessionUsageEnvelope = null;
        try {
            $this->billedSessionSeconds = $this->readBilledSecondsBaseline($providerSessionId);
            if ($this->billedSessionSeconds > 0) {
                $this->appendToVoiceLog(
                    "LIVE {$providerSessionId} re-attaching — {$this->billedSessionSeconds}s already billed by a"
                    . ' predecessor worker'
                );
            }
            $this->attachedAgentVoiceCall = $agentVoiceCall;
            $this->prepareApplicationStateOfAttachedCall($agentVoiceCall);
            $eventLoop = Loop::get();
            // DRAIN MODE: a deploy's SIGTERM must not drop the live call.
            if (function_exists('pcntl_signal') && defined('SIGTERM')) {
                $this->terminationSignalHandlerBeforeTheCall = pcntl_signal_get_handler(SIGTERM);
                $eventLoop->addSignal(SIGTERM, [$this, 'logTerminationSignalDrain']);
            }

            $this->sidebandWebSocketConnector = $this->buildSidebandWebSocketConnector($eventLoop);
            $this->sidebandConnectFailure = null;
            $this->sidebandConnectAttemptCount = 0;
            $this->connectToSideband();
            // A connect that failed at once has given up already, and the loop forgets a stop it was told before
            // it runs: the loop is not entered then.
            if (!$this->eventLoopStopRequested) {
                $sessionUsageEnvelope = $this->openSessionEnvelopeIfNoneIsOpen($agentVoiceCall);
                $eventLoop->run();
            }
        } finally {
            $this->flushTraceEvents();
            $this->releaseAttachLease($providerSessionId);
            $this->closeSessionEnvelope($sessionUsageEnvelope);
            $this->releaseEventLoopResourcesOfTheCall();
        }
        if ($this->sidebandConnectFailure !== null) {
            $this->persistCallEndIfStillRunning($agentVoiceCall, AgentVoiceCall::END_REASON_FAILED);
            $realtimeVoiceProviderAdapter = $this->getVoiceProviderAdapterOfCall($agentVoiceCall);
            if ($realtimeVoiceProviderAdapter->isSessionGoneConnectFailure($this->sidebandConnectFailure)) {
                throw new LiveSessionGoneException(
                    'Session ' . $providerSessionId . ' is gone at the provider '
                    . $realtimeVoiceProviderAdapter->getProviderName() . ' — nothing to attach to: '
                    . $this->sidebandConnectFailure->getMessage()
                );
            }
            throw new InternalErrorException(
                'The sideband connect to the provider ' . $realtimeVoiceProviderAdapter->getProviderName()
                . ' failed for session ' . $providerSessionId . ' after ' . $this->sidebandConnectAttemptCount
                . ' attempts: ' . $this->sidebandConnectFailure->getMessage()
            );
        }
    }

    /** The connector the sideband is opened with; a test or an application answers its own here. */
    protected function buildSidebandWebSocketConnector(LoopInterface $eventLoop): Connector
    {
        return new Connector($eventLoop);
    }

    /**
     * Tells the event loop of the call to stop and remembers it: {@see attachToCallSession()} does not enter a loop
     * that was told to stop before it ran.
     */
    protected function stopEventLoopOfTheCall(): void
    {
        $this->eventLoopStopRequested = true;
        Loop::stop();
    }

    /**
     * Puts every per-call field back to its initial value at the start of an attach, so a worker process that
     * accompanies one call after another starts each of them clean. What the previous call left on the event loop goes
     * first ({@see releaseEventLoopResourcesOfTheCall()}). The worker token of the trace keys is minted anew: a second
     * attach of the same call by this process must not repeat the keys of the first. A subclass that keeps per-call
     * fields of its own puts them back here too.
     */
    protected function resetStateOfThePreviousCall(): void
    {
        $this->releaseEventLoopResourcesOfTheCall();
        $this->attachedAgentVoiceCall = null;
        $this->sidebandWebSocketConnector = null;
        $this->sidebandConnectAttemptCount = 0;
        $this->sidebandConnectFailure = null;
        $this->eventLoopStopRequested = false;
        $this->farewellHangUpEndReason = null;
        $this->attachLeaseToken = null;
        $this->attachLeaseLossLogged = false;
        $this->attachedAiConversation = null;
        $this->lastSessionEventReceivedAtUnixTime = null;
        $this->externalEndCheckTickCount = 0;
        $this->admissionRecheckTickCount = 0;
        $this->ticksSinceAttached = 0;
        $this->agentHasSpoken = false;
        $this->agentSpokenCharacterCount = 0;
        $this->greetingRequestSent = false;
        $this->liveTranscriptAssembler = new LiveTranscriptAssembler();
        $this->recentCallerSentences = [];
        $this->pendingDelegationId = null;
        $this->callerSentencesConsumedUntilUnixMs = 0;
        $this->pendingAgentTaskAfterMessageIndex = null;
        $this->queuedDelegation = null;
        $this->callerAnswerAwaitedSinceUnixTime = null;
        $this->unansweredQuestionReasked = false;
        $this->transcriptBacklog = [];
        $this->billedSessionSeconds = 0;
        $this->bookedSecondsOwedToTheCall = 0;
        $this->farewellHangUpArmed = false;
        $this->requestedGoodbyeHangUp = null;
        $this->lastAgentSpeechAtUnixMs = null;
        $this->lastAnswerHandedOverAtUnixMs = null;
        $this->lastSpeechAtUnixTime = null;
        $this->lastSpeechAtUnixMs = null;
        $this->taskProgressSchedule = null;
        $this->progressCommentarySentAtSessionMs = null;
        $this->speechSilenceNudgeSent = false;
        $this->speechSilenceNudgeCount = 0;
        $this->pendingAgentTaskStartedAtUnixTime = null;
        $this->pendingAgentTaskRedriven = false;
        $this->foreignTurnObservedRunning = false;
        $this->sessionStartedReceived = false;
        $this->usageEventReceived = false;
        $this->callFinished = false;
        $this->traceEventBuffer = [];
        $this->traceEventSequence = 0;
        $this->traceWorkerToken = bin2hex(random_bytes(4));
        $this->firstAgentTranscriptTraced = false;
    }

    /**
     * Takes off the event loop what one call put on it: the fast check's timer, the watcher timer, a pending farewell
     * timer, the sideband socket with its handlers, and the drain listener for SIGTERM
     * ({@see endDrainModeOfTheCall()}). Left there, they would act on the next call of this process. Never throws.
     */
    protected function releaseEventLoopResourcesOfTheCall(): void
    {
        try {
            $this->cancelGoodbyeAndTaskOutcomeTimer();
            if ($this->watcherTimer !== null) {
                Loop::get()->cancelTimer($this->watcherTimer);
            }
            if ($this->farewellHangUpTimer !== null) {
                Loop::get()->cancelTimer($this->farewellHangUpTimer);
            }
            if ($this->sidebandWebSocketConnection !== null) {
                $this->sidebandWebSocketConnection->removeAllListeners();
                $this->sidebandWebSocketConnection->close();
            }
        } catch (Throwable) {
            // a socket that is already gone has nothing left to release
        } finally {
            $this->watcherTimer = null;
            $this->farewellHangUpTimer = null;
            $this->sidebandWebSocketConnection = null;
        }
        $this->endDrainModeOfTheCall();
    }

    /**
     * Ends the drain mode of a call: the drain listener leaves the event loop and the process gets back what it did on
     * SIGTERM before the call. A SIGTERM that arrived during the call is then sent to the process again when it had a
     * handler of its own (a consumer's graceful stop), so the worker stops after the call it drained for instead of
     * taking the next one; without such a handler the default applies again and nothing is sent. Never throws.
     */
    protected function endDrainModeOfTheCall(): void
    {
        if ($this->terminationSignalHandlerBeforeTheCall === null) {
            return;
        }
        $terminationSignalHandlerBeforeTheCall = $this->terminationSignalHandlerBeforeTheCall;
        $terminationSignalDrained = $this->terminationSignalDrained;
        $this->terminationSignalHandlerBeforeTheCall = null;
        $this->terminationSignalDrained = false;
        try {
            Loop::get()->removeSignal(SIGTERM, [$this, 'logTerminationSignalDrain']);
            pcntl_signal(SIGTERM, $terminationSignalHandlerBeforeTheCall);
            if ($terminationSignalDrained
                && is_callable($terminationSignalHandlerBeforeTheCall)
                && function_exists('posix_kill')
            ) {
                posix_kill((int)getmypid(), SIGTERM);
            }
        } catch (Throwable) {
            // the process keeps the SIGTERM handling it has
        }
    }

    /** The SIGTERM handler of the drain mode. Public only because the event loop calls it. */
    public function logTerminationSignalDrain(): void
    {
        $this->terminationSignalDrained = true;
        $this->appendToVoiceLog(
            'LIVE ' . ($this->attachedAgentVoiceCall?->providerCallId ?? '?') . ' SIGTERM received — draining: the live'
            . ' call finishes before this worker stops'
        );
    }

    /**
     * ONE sideband connect attempt for the attached call; {@see continueWithConnectedSideband()} or
     * {@see retryOrGiveUpSidebandConnect()} follows. Public only because the event loop calls it for a retry.
     */
    public function connectToSideband(): void
    {
        if ($this->attachedAgentVoiceCall === null || $this->sidebandWebSocketConnector === null) {
            return;
        }
        $providerSessionId = (string)$this->attachedAgentVoiceCall->providerCallId;
        $realtimeVoiceProviderAdapter = $this->getVoiceProviderAdapterOfCall($this->attachedAgentVoiceCall);
        $this->sidebandConnectAttemptCount++;
        ($this->sidebandWebSocketConnector)(
            $realtimeVoiceProviderAdapter->buildSidebandWebSocketUrl($providerSessionId),
            [],
            $realtimeVoiceProviderAdapter->buildSidebandWebSocketHeaders()
        )->then([$this, 'continueWithConnectedSideband'], [$this, 'retryOrGiveUpSidebandConnect']);
    }

    /** The sideband is open: the worker attaches to it. Public only because the connect promise calls it. */
    public function continueWithConnectedSideband(WebSocket $webSocketConnection): void
    {
        if ($this->attachedAgentVoiceCall === null) {
            return;
        }
        $this->runAttachedSideband($this->attachedAgentVoiceCall, $webSocketConnection);
    }

    /**
     * A connect attempt failed. The device's media leg is up regardless of the sideband — a transient connect failure
     * must not end a working call. Retry before giving up — unless the provider says the session itself is gone,
     * which no attempt can change. Public only because the connect promise calls it.
     */
    public function retryOrGiveUpSidebandConnect(Throwable $webSocketConnectThrowable): void
    {
        if ($this->attachedAgentVoiceCall !== null
            && $this->sidebandConnectAttemptCount < static::SIDEBAND_CONNECT_ATTEMPTS
            && !$this->getVoiceProviderAdapterOfCall($this->attachedAgentVoiceCall)
                ->isSessionGoneConnectFailure($webSocketConnectThrowable)
        ) {
            $this->appendToVoiceLog(
                'LIVE ' . ($this->attachedAgentVoiceCall?->providerCallId ?? '?') . ' sideband connect attempt'
                . " {$this->sidebandConnectAttemptCount} failed: " . $webSocketConnectThrowable->getMessage()
                . ' — retrying'
            );
            Loop::get()->addTimer(1.0, [$this, 'connectToSideband']);

            return;
        }
        $this->sidebandConnectFailure = $webSocketConnectThrowable;
        $this->stopEventLoopOfTheCall();
    }

    /**
     * Claim the session for THIS worker. The lease is a Redis lock (ddd `Cache::acquireLock()`, one SET NX EX) the
     * watcher re-arms every tick with its owner token; a successor finding it held waits for it to expire (the holder
     * died and stopped refreshing) and takes over, or gives up when it is still being refreshed (the holder is alive —
     * two workers on one sideband would double every transcript row). Without Redis the lease is skipped, never the
     * call.
     */
    protected function acquireAttachLease(string $providerSessionId): bool
    {
        try {
            $redisCache = Cache::instance(Cache::CACHE_GROUP_REDIS);
            $leaseKey = $this->buildAttachLeaseKey($providerSessionId);
            $waitedSeconds = 0;
            while (($this->attachLeaseToken = $redisCache->acquireLock($leaseKey, static::ATTACH_LEASE_TTL_SECONDS)) === null) {
                if ($waitedSeconds >= static::ATTACH_LEASE_WAIT_SECONDS) {
                    return false;
                }
                if ($waitedSeconds === 0) {
                    $this->appendToVoiceLog(
                        "LIVE {$providerSessionId} attach lease held — waiting up to "
                        . static::ATTACH_LEASE_WAIT_SECONDS . 's for it to expire'
                    );
                }
                sleep(1);
                $waitedSeconds++;
            }

            return true;
        } catch (Throwable $leaseError) {
            $this->appendToVoiceLog(
                "LIVE {$providerSessionId} attach lease unavailable (" . $leaseError->getMessage()
                . ') — attaching without it'
            );

            return true;
        }
    }

    /**
     * Re-arm the lease's TTL — called every watcher tick, only while this worker's token still owns it. A refresh that
     * finds the lease gone or taken over is logged once per tick (the worker keeps the call: the device's media leg is
     * up regardless, and a successor that took over attaches on its own).
     */
    protected function refreshAttachLease(string $providerSessionId): void
    {
        if ($this->attachLeaseToken === null) {
            return;
        }
        try {
            $leaseStillOwned = Cache::instance(Cache::CACHE_GROUP_REDIS)
                ->refreshLock($this->buildAttachLeaseKey($providerSessionId), $this->attachLeaseToken, static::ATTACH_LEASE_TTL_SECONDS);
            if (!$leaseStillOwned && !$this->attachLeaseLossLogged) {
                $this->attachLeaseLossLogged = true;
                $this->appendToVoiceLog("LIVE {$providerSessionId} attach lease lost — expired or taken over");
            }
        } catch (Throwable) {
            // A missed refresh only shortens the lease; the next tick tries again.
        }
    }

    protected function releaseAttachLease(string $providerSessionId): void
    {
        if ($this->attachLeaseToken === null) {
            return;
        }
        try {
            Cache::instance(Cache::CACHE_GROUP_REDIS)->releaseLock($this->buildAttachLeaseKey($providerSessionId), $this->attachLeaseToken);
        } catch (Throwable) {
            // An unreleased lease expires by itself within ATTACH_LEASE_TTL_SECONDS.
        } finally {
            $this->attachLeaseToken = null;
        }
    }

    protected function buildAttachLeaseKey(string $providerSessionId): string
    {
        return 'voice-live-attach-lease:' . $providerSessionId;
    }

    /**
     * The seconds already booked for this session by ANY worker — `session.usage.updated` reports the session's
     * cumulative seconds, so a successor worker starting from zero would bill its predecessor's minutes again.
     */
    protected function readBilledSecondsBaseline(string $providerSessionId): int
    {
        try {
            return Cache::instance(Cache::CACHE_GROUP_REDIS)
                ->getCounter($this->buildBilledSecondsBaselineKey($providerSessionId));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function advanceBilledSecondsBaseline(string $providerSessionId, int $secondsBilled): void
    {
        try {
            Cache::instance(Cache::CACHE_GROUP_REDIS)->increment(
                $this->buildBilledSecondsBaselineKey($providerSessionId),
                $secondsBilled,
                static::BILLED_SECONDS_BASELINE_TTL_SECONDS
            );
        } catch (Throwable) {
            // Without the baseline a successor after a worker death may bill the predecessor's seconds again —
            // the in-process counter still keeps THIS worker's bookings exact.
        }
    }

    protected function buildBilledSecondsBaselineKey(string $providerSessionId): string
    {
        return 'voice-live-billed-seconds:' . $providerSessionId;
    }

    /** The attached sideband: the fast check's timer, the watcher timer, the event handler, the close handler. */
    protected function runAttachedSideband(AgentVoiceCall $agentVoiceCall, WebSocket $webSocketConnection): void
    {
        $this->attachedAgentVoiceCall = $agentVoiceCall;
        $this->sidebandWebSocketConnection = $webSocketConnection;
        $this->appendToVoiceLog(
            "LIVE {$agentVoiceCall->providerCallId} sideband connected — session worker attached"
        );
        $this->lastSessionEventReceivedAtUnixTime = time();
        $this->bufferTraceEvent(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            'worker.attached',
            'sideband worker attached',
            discriminator: $this->traceWorkerToken
        );
        $this->flushTraceEvents();

        $this->goodbyeAndTaskOutcomeTimer = Loop::get()->addPeriodicTimer(
            static::GOODBYE_AND_TASK_OUTCOME_CHECK_INTERVAL_SECONDS,
            [$this, 'runGoodbyeAndTaskOutcomeCheckOfAttachedCall']
        );
        $this->watcherTimer = Loop::get()->addPeriodicTimer(
            (float)static::WATCHER_TICK_SECONDS,
            [$this, 'runWatcherTickOfAttachedCall']
        );
        $webSocketConnection->on('message', [$this, 'applyReceivedSidebandMessage']);
        $webSocketConnection->on('close', [$this, 'finishCallAfterSidebandClosed']);
    }

    /** The fast check's timer: {@see runGoodbyeAndTaskOutcomeCheck()} for the attached call. Public only because the event loop calls it. */
    public function runGoodbyeAndTaskOutcomeCheckOfAttachedCall(): void
    {
        if ($this->attachedAgentVoiceCall === null || $this->sidebandWebSocketConnection === null) {
            return;
        }
        $this->runGoodbyeAndTaskOutcomeCheck($this->attachedAgentVoiceCall, $this->sidebandWebSocketConnection);
    }

    /**
     * The watcher timer: {@see runWatcherTick()} for the attached call; an error never reaches the loop. Public only
     * because the event loop calls it.
     */
    public function runWatcherTickOfAttachedCall(): void
    {
        if ($this->attachedAgentVoiceCall === null || $this->sidebandWebSocketConnection === null) {
            return;
        }
        try {
            $this->runWatcherTick($this->attachedAgentVoiceCall, $this->sidebandWebSocketConnection);
        } catch (Throwable $watcherError) {
            $watcherFailureLine = "LIVE {$this->attachedAgentVoiceCall->providerCallId} watcher tick failed: "
                . $watcherError->getMessage();
            $this->appendToVoiceLog($watcherFailureLine);
            DDDService::instance()->getLogger()->error($watcherFailureLine);
        }
    }

    /**
     * One message of the sideband: {@see applyLiveSessionEvent()}; an error never reaches the loop. Public only
     * because the socket calls it.
     */
    public function applyReceivedSidebandMessage(MessageInterface $rawSessionEvent): void
    {
        if ($this->attachedAgentVoiceCall === null || $this->sidebandWebSocketConnection === null) {
            return;
        }
        $this->lastSessionEventReceivedAtUnixTime = time();
        try {
            $this->applyLiveSessionEvent(
                $this->attachedAgentVoiceCall,
                $this->sidebandWebSocketConnection,
                (string)$rawSessionEvent
            );
        } catch (Throwable $sessionEventError) {
            $sessionEventFailureLine = "LIVE {$this->attachedAgentVoiceCall->providerCallId} event handling"
                . ' failed: ' . $sessionEventError->getMessage()
                . ' at ' . $sessionEventError->getFile() . ':' . $sessionEventError->getLine();
            $this->appendToVoiceLog($sessionEventFailureLine);
            DDDService::instance()->getLogger()->error($sessionEventFailureLine);
        }
    }

    /**
     * The sideband closed. That does NOT end the device's session — the provider is asked to hang up, so a call
     * nobody books any more does not keep billing. Public only because the socket calls it.
     */
    public function finishCallAfterSidebandClosed(mixed $closeCode = null): void
    {
        if ($this->attachedAgentVoiceCall === null) {
            return;
        }
        $this->appendToVoiceLog(
            "LIVE {$this->attachedAgentVoiceCall->providerCallId} sideband closed (code "
            . ($closeCode ?? '?') . ')'
        );
        $this->hangUpSessionBestEffort($this->attachedAgentVoiceCall);
        $this->finishCall($this->attachedAgentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);
        $this->flushTraceEvents();
        $this->stopEventLoopOfTheCall();
    }

    /**
     * HANG UP a running session by its id — for a caller that holds no call. The adapter is the one of the call that
     * carries the session id (the attached call, else read through the store); a session id no call carries is hung
     * up on the default provider.
     */
    public function hangUpSession(string $providerSessionId): bool
    {
        $agentVoiceCall = $this->attachedAgentVoiceCall !== null
            && (string)$this->attachedAgentVoiceCall->providerCallId === $providerSessionId
            ? $this->attachedAgentVoiceCall
            : $this->getAgentVoiceCallStoreService()->findCallByProviderCallId($providerSessionId);
        $realtimeVoiceProviderAdapter = $agentVoiceCall !== null
            ? $this->getVoiceProviderAdapterOfCall($agentVoiceCall)
            : RealtimeVoiceProviderAdapter::forProviderName(null);

        return $realtimeVoiceProviderAdapter->hangUpSession($providerSessionId);
    }

    /**
     * ONE event from the sideband. The lifecycle events end the loop, the usage counter is booked, the transcript
     * deltas go through the assembler, and a delegation starts an agent task. Everything else is logged by type.
     */
    protected function applyLiveSessionEvent(
        AgentVoiceCall $agentVoiceCall,
        WebSocket $webSocketConnection,
        string $rawSessionEvent
    ): void {
        $sessionEvent = json_decode($rawSessionEvent, true);
        if (!is_array($sessionEvent)) {
            return;
        }
        $sessionEventType = (string)($sessionEvent['type'] ?? '');
        $this->logLiveSessionTraffic($agentVoiceCall, 'received', $sessionEventType, $rawSessionEvent);
        $this->bufferReceivedProviderEvent($agentVoiceCall, $sessionEventType, $sessionEvent);

        switch ($sessionEventType) {
            case 'error':
                $providerErrorLine = "LIVE {$agentVoiceCall->providerCallId} provider error event: "
                    . mb_substr($rawSessionEvent, 0, 2000);
                $this->appendToVoiceLog($providerErrorLine);
                DDDService::instance()->getLogger()->error($providerErrorLine);

                return;

            case 'session.started':
                $this->sessionStartedReceived = true;
                $this->sendApplicationOpeningAfterSessionStarted($agentVoiceCall, $webSocketConnection);

                return;

            case 'session.usage.updated':
                $this->bookLiveSessionUsage($agentVoiceCall, $sessionEvent);

                return;

            case 'session.closed':
                $this->bookLiveSessionUsage($agentVoiceCall, $sessionEvent, final: true);
                $closeReason = (string)($sessionEvent['reason'] ?? '');
                // The reason on its own line: the full event carries the whole session object and is cut in the
                // log before the reason field.
                $this->appendToVoiceLog("LIVE {$agentVoiceCall->providerCallId} session closed reason={$closeReason}");
                $realtimeVoiceProviderAdapter = $this->getVoiceProviderAdapterOfCall($agentVoiceCall);
                $this->finishCall(
                    $agentVoiceCall,
                    $realtimeVoiceProviderAdapter->mapSessionCloseReasonToEndReason($closeReason)
                );
                $this->flushTraceEvents();
                $this->stopEventLoopOfTheCall();

                return;

            case 'session.input_transcript.delta':
                $this->appendTranscriptFragment($agentVoiceCall, AIConversationMessage::ROLE_USER, $sessionEvent);

                return;

            case 'session.output_transcript.delta':
                if (!$this->firstAgentTranscriptTraced && (string)($sessionEvent['delta'] ?? '') !== '') {
                    // The call header's "first word" mark: captions trail the audio by a few hundred ms, the
                    // provider offset of the fragment is the exact start on the session clock.
                    $this->firstAgentTranscriptTraced = true;
                    $this->bufferTraceEvent(
                        $agentVoiceCall,
                        AIConversationTraceEvent::SOURCE_VOICE_PROVIDER,
                        AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT,
                        $this->getAgentLabel() . '\'s first words on the transcript',
                        providerOffsetMs: isset($sessionEvent['start_ms']) ? (int)$sessionEvent['start_ms'] : null
                    );
                    $this->flushTraceEvents();
                }
                if ((string)($sessionEvent['delta'] ?? '') !== '') {
                    $this->lastAgentSpeechAtUnixMs = AIConversationTraceEvent::nowUnixMs();
                }
                $this->agentSpokenCharacterCount += mb_strlen((string)($sessionEvent['delta'] ?? ''));
                if ($this->agentSpokenCharacterCount >= static::AGENT_HAS_SPOKEN_MIN_CHARACTERS) {
                    $this->agentHasSpoken = true;
                }
                $this->appendTranscriptFragment($agentVoiceCall, AIConversationMessage::ROLE_ASSISTANT, $sessionEvent);

                return;

            case 'session.delegation.created':
                if ($this->answerDelegationWithoutTheAgent($agentVoiceCall, $webSocketConnection, $sessionEvent)) {
                    return;
                }
                $this->startDelegatedAgentTask($agentVoiceCall, $webSocketConnection, $sessionEvent);

                return;

            default:
                $this->applyApplicationSessionEvent($agentVoiceCall, $webSocketConnection, $sessionEventType, $sessionEvent);

                return;
        }
    }

    /**
     * The 2-second watcher: liveness, external end, admission, the one-time greeting request, idle transcript
     * flush, and the delegation result once the agent task settled.
     */
    protected function runWatcherTick(AgentVoiceCall $agentVoiceCall, WebSocket $webSocketConnection): void
    {
        if ($this->callFinished) {
            // the call was finished by another path (the goodbye hang-up, a farewell, `session.closed`) — no second
            // hang-up, no external-end stop, no silent-sideband stop on a call that is already over
            return;
        }
        $this->ticksSinceAttached++;
        $this->flushTraceEvents();
        $this->refreshAttachLease((string)$agentVoiceCall->providerCallId);
        $this->markActivityReaderPresentForTheCallConversation($agentVoiceCall);
        if (($this->ticksSinceAttached % static::MEMORY_LOG_EVERY_TICKS) === 0) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} worker memory "
                . round(memory_get_usage(true) / 1048576) . ' MB (peak '
                . round(memory_get_peak_usage(true) / 1048576) . ' MB) after ' . ($this->ticksSinceAttached * static::WATCHER_TICK_SECONDS) . 's'
            );
        }

        if ($this->lastSessionEventReceivedAtUnixTime !== null
            && (time() - $this->lastSessionEventReceivedAtUnixTime) > AgentVoiceConfiguration::current()->sessionSilentSeconds
        ) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} sideband silent for more than "
                . AgentVoiceConfiguration::current()->sessionSilentSeconds . 's — hanging up and ending the loop cleanly'
            );
            $this->hangUpSessionBestEffort($agentVoiceCall, $webSocketConnection);
            $this->finishCall($agentVoiceCall, AgentVoiceCall::END_REASON_FAILED);
            $this->flushTraceEvents();
            $this->stopEventLoopOfTheCall();

            return;
        }

        $this->externalEndCheckTickCount++;
        if (($this->externalEndCheckTickCount % static::EXTERNAL_END_CHECK_EVERY_TICKS) === 0) {
            $freshAgentVoiceCall = $this->getAgentVoiceCallStoreService()->findCall((int)$agentVoiceCall->id);
            if ($freshAgentVoiceCall !== null && $freshAgentVoiceCall->endedAt === null) {
                $this->adoptSwitchedConversation($agentVoiceCall, $freshAgentVoiceCall, $webSocketConnection);
            }
            if ($freshAgentVoiceCall !== null && $freshAgentVoiceCall->endedAt !== null) {
                $this->appendToVoiceLog(
                    "LIVE {$agentVoiceCall->providerCallId} call row ended externally ("
                    . ($freshAgentVoiceCall->endReason ?? '?') . ') — hanging up the session'
                );
                $this->hangUpSessionBestEffort($agentVoiceCall, $webSocketConnection);
                // The call is ALREADY ended (by the route that hangs up, an operator, the clean-up of stale calls) —
                // flush and stop, never stamp it a second time: that would write a second call-ended marker and
                // curate memory twice.
                $this->callFinished = true;
                $this->pendingAgentTaskAfterMessageIndex = null;
                $this->queuedDelegation = null;
                $this->flushTranscriptBacklog($agentVoiceCall);
                $this->persistCompletedSentences($agentVoiceCall, $this->liveTranscriptAssembler->closeAllSentences());
                $this->finishApplicationPartOfTheCall($agentVoiceCall);
                $this->bookInitialisationChargeWhenNothingWasBooked($agentVoiceCall);
                // The seconds are booked as usage already; the row is ended, but what it has not taken is still owed.
                $this->addOwedBookedSecondsToTheCallAtTheTeardown($agentVoiceCall);
                $this->bufferTraceFlushedMarker($agentVoiceCall);
                $this->flushTraceEvents();
                $this->stopEventLoopOfTheCall();

                return;
            }
        }

        if ($this->hangUpAfterRequestedGoodbyeWhenDue($agentVoiceCall, $webSocketConnection)) {
            return;
        }
        if ($this->endCallWhenItIsRefused($agentVoiceCall, $webSocketConnection)) {
            return;
        }
        if ($this->endCallWhenNobodySpeaks($agentVoiceCall, $webSocketConnection)) {
            return;
        }

        // The documented way to make the model speak first: ONE `session.instructions.append` (delegation_id
        // null) after `session.started` — commentary may be paraphrased, instructions are followed. Sent only when
        // no output transcript has arrived; captions lag the audio, so the tick is deliberately late. Worded
        // conditionally: a short opening (under AGENT_HAS_SPOKEN_MIN_CHARACTERS) or one spoken before this worker
        // attached leaves `agentHasSpoken` false although the call is already open — that must not greet twice.
        if (!$this->greetingRequestSent && !$this->agentHasSpoken && $this->sessionStartedReceived
            && $this->ticksSinceAttached >= static::GREETING_NUDGE_AT_TICK
        ) {
            $this->greetingRequestSent = true;
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.instructions.append',
                'delegation_id' => null,
                'content' => 'If you have NOT opened the call yet, do it now: open it exactly as your instructions say'
                    . ' — briefly, in the call language — then listen. If you already opened it, say nothing and'
                    . ' keep listening.',
            ]);
        }

        $this->persistCompletedSentences(
            $agentVoiceCall,
            $this->liveTranscriptAssembler->closeIdleSentences((int)floor(microtime(true) * 1000))
        );

        // Sentences held back behind a turn this worker did not start: released the tick the run settled.
        if ($this->transcriptBacklog !== [] && $this->pendingAgentTaskAfterMessageIndex === null
            && $this->foreignTurnObservedRunning && !$this->isAttachedConversationRunning($agentVoiceCall)
        ) {
            $this->flushTranscriptBacklog($agentVoiceCall);
        }

        $this->appendDelegatedTaskResultWhenFinished($agentVoiceCall, $webSocketConnection);
    }

    /**
     * The call was RE-POINTED at another conversation while this worker runs (a tool started a purposed conversation
     * for the call and wrote its id on the call). The worker adopts it: the cached conversation is dropped (the next
     * transcript write, backlog flush and delegation land in the new one), and the voice model is told how the call
     * works from here on — the adopted purpose's spoken call task
     * ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::getSpokenCallTask()}), session-wide,
     * in append-sized pieces. Sentences held back in the backlog belong to the old conversation's turn but are
     * flushed into the new one on the next tick — the caller's request is thereby the new conversation's first
     * transcript line.
     */
    protected function adoptSwitchedConversation(
        AgentVoiceCall $agentVoiceCall,
        AgentVoiceCall $freshAgentVoiceCall,
        WebSocket $webSocketConnection
    ): void {
        $freshConversationId = $freshAgentVoiceCall->aiConversationId;
        if ($freshConversationId === null || $freshConversationId === $agentVoiceCall->aiConversationId) {
            return;
        }
        $this->appendToVoiceLog(
            "LIVE {$agentVoiceCall->providerCallId} call row re-pointed from conversation "
            . ($agentVoiceCall->aiConversationId ?? 'none') . " to {$freshConversationId} — adopting it"
        );
        $agentVoiceCall->aiConversationId = $freshConversationId;
        $this->attachedAiConversation = null;
        $this->linkTraceEventsToConversation($agentVoiceCall);
        $adoptedConversation = $this->findAttachedAiConversation($agentVoiceCall);
        $spokenCallTask = $adoptedConversation?->findPurpose()?->getSpokenCallTask();
        if ($spokenCallTask === null) {
            return;
        }
        $realtimeVoiceProviderAdapter = $this->getVoiceProviderAdapterOfCall($agentVoiceCall);
        foreach ($realtimeVoiceProviderAdapter->splitIntoAppendPieces($spokenCallTask) as $appendPiece) {
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.instructions.append',
                'delegation_id' => null,
                'content' => $appendPiece,
            ]);
        }
    }

    /** One transcript delta into the assembler; whatever it completed is persisted right away. */
    protected function appendTranscriptFragment(AgentVoiceCall $agentVoiceCall, string $role, array $sessionEvent): void
    {
        $fragmentText = (string)($sessionEvent['delta'] ?? '');
        if ($fragmentText === '') {
            return;
        }
        $this->lastSpeechAtUnixTime = time();
        $this->lastSpeechAtUnixMs = AIConversationTraceEvent::nowUnixMs();
        $this->speechSilenceNudgeSent = false;
        if ($role === AIConversationMessage::ROLE_USER) {
            $this->callerAnswerAwaitedSinceUnixTime = null;
        }
        $completedSentences = $this->liveTranscriptAssembler->appendFragment(
            $role,
            $fragmentText,
            (int)($sessionEvent['start_ms'] ?? 0),
            (int)($sessionEvent['end_ms'] ?? 0),
            (int)floor(microtime(true) * 1000)
        );
        $this->persistCompletedSentences($agentVoiceCall, $completedSentences);
    }

    /**
     * Completed sentences → the call's conversation (USER for the caller, ASSISTANT for the agent), or held back while
     * an agent task owns the message index. The caller's sentences are also remembered for the next delegation.
     *
     * @param LiveTranscriptSentence[] $completedSentences
     */
    protected function persistCompletedSentences(AgentVoiceCall $agentVoiceCall, array $completedSentences): void
    {
        foreach ($completedSentences as $completedSentence) {
            if ($this->keepCompletedSentenceOutOfTheConversation($agentVoiceCall, $completedSentence)) {
                continue;
            }
            $this->rememberCallerSentence($completedSentence);
            // The turn worker owns the index sequence while ANY turn runs — an own task, or one started by the
            // supervisor, a yield-continue or the chat. A row written now would land on the turn's index and
            // the upsert would MERGE the two (a request overwritten by the agent's text, a tool call carrying a
            // caller sentence — measured). Hold back; the watcher releases the backlog once the run settled.
            // The timing line: role + provider offsets + length, never the text — the transcript is in the
            // conversation, the voice log carries no speech.
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} transcript {$completedSentence->role}"
                . " start={$completedSentence->startMs} end={$completedSentence->endMs}"
                . ' chars=' . mb_strlen($completedSentence->text)
            );
            if ($this->pendingAgentTaskAfterMessageIndex !== null || $this->isAttachedConversationRunning($agentVoiceCall)) {
                $this->transcriptBacklog[] = LiveTranscriptBacklogSentence::create(
                    $completedSentence->role,
                    $completedSentence->text,
                    $completedSentence->startMs,
                    $completedSentence->endMs,
                    // classified NOW, while the commentary it answers is known to be outstanding — the class
                    // travels with the entry through the backlog and the flush
                    $this->isProgressSentence($completedSentence->role, $completedSentence->startMs)
                );
                $this->bufferTraceEvent(
                    $agentVoiceCall,
                    AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                    AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_BACKLOG_PUSHED,
                    ($completedSentence->role === AIConversationMessage::ROLE_USER ? 'caller' : $this->getAgentLabel())
                        . ' sentence held back while a turn runs (' . mb_strlen($completedSentence->text) . ' chars)',
                    payload: ['role' => $completedSentence->role, 'startMs' => $completedSentence->startMs, 'endMs' => $completedSentence->endMs],
                    providerOffsetMs: $completedSentence->startMs
                );
                continue;
            }
            $this->persistTranscriptSentenceNow(
                $agentVoiceCall,
                $completedSentence->role,
                $completedSentence->text,
                $completedSentence->startMs,
                $completedSentence->endMs
            );
        }
    }

    /**
     * One sentence into the conversation with the message index taken from a FRESH read of the rows — the
     * worker's cached message set goes stale the moment anyone else appends, and a stale index is exactly the
     * merged-row defect. One small query per sentence is the price of an index that is right.
     */
    protected function persistTranscriptSentenceNow(
        AgentVoiceCall $agentVoiceCall,
        string $role,
        string $spokenSentence,
        ?int $spokenFromMs = null,
        ?int $spokenUntilMs = null
    ): void {
        $aiConversation = $this->findAttachedAiConversation($agentVoiceCall);
        if (!$aiConversation) {
            return;
        }
        // the bounded tail above the compaction cover — the insert re-anchors its index anyway
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = $this->getAIConversationMessagesService();
        $aiConversation->messages = $aIConversationMessagesService->findContextTailMessages($aiConversation);
        /** @var AgentVoiceCallsService $agentVoiceCallsService */
        $agentVoiceCallsService = $this->getAgentVoiceCallsService();
        try {
            $transcriptMessage = $agentVoiceCallsService->persistTranscriptMessage($aiConversation, $role, $spokenSentence, $agentVoiceCall->getChannelType());
        } catch (MessageIndexLockUnavailableException $lockUnavailable) {
            // the index lock stayed busy (a turn writing the same conversation): the sentence waits in the backlog
            // for the next flush instead of being lost
            $this->transcriptBacklog[] = LiveTranscriptBacklogSentence::create($role, $spokenSentence, $spokenFromMs, $spokenUntilMs);
            return;
        }
        $this->recordTranscriptProvenance($agentVoiceCall, $transcriptMessage, $role, $spokenSentence, $spokenFromMs, $spokenUntilMs);
    }

    /**
     * The provenance of ONE persisted transcript row: which side spoke it, on which call, the spoken
     * span on the session clock. Written right after the row and outside the buffer — the trace view's writer label
     * depends on it; fail-open, a lost provenance row shows as "provenance missing", never as a broken call.
     */
    protected function recordTranscriptProvenance(
        AgentVoiceCall $agentVoiceCall,
        AIConversationMessage $transcriptMessage,
        string $role,
        string $spokenSentence,
        ?int $spokenFromMs,
        ?int $spokenUntilMs,
        bool $fromBacklog = false
    ): void {
        $messageIndex = $transcriptMessage->indexInConversation ?? null;
        $speaker = $role === AIConversationMessage::ROLE_USER ? 'caller' : $this->getAgentLabel();
        $spanLabel = $spokenFromMs !== null && $spokenUntilMs !== null
            ? ' ' . round($spokenFromMs / 1000, 1) . '–' . round($spokenUntilMs / 1000, 1) . ' s'
            : '';
        /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
        $aIConversationTraceEventsService = $this->getAIConversationTraceEventsService();
        $aIConversationTraceEventsService->recordEvent(AIConversationTraceEvent::create(
            source: AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            eventType: AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED,
            eventKey: 'call:' . $agentVoiceCall->id . ':transcript:' . ($agentVoiceCall->aiConversationId ?? 'none')
                . ':' . ($messageIndex ?? ('w' . $this->traceWorkerToken . ':' . (++$this->traceEventSequence))),
            aiConversationId: $agentVoiceCall->aiConversationId !== null ? (int)$agentVoiceCall->aiConversationId : null,
            agentVoiceCallId: $agentVoiceCall->id !== null ? (int)$agentVoiceCall->id : null,
            summary: $speaker . ' sentence persisted' . $spanLabel . ($fromBacklog ? ' (from the backlog)' : ''),
            payload: [
                'writer' => $role === AIConversationMessage::ROLE_USER ? AIConversationMessageWriter::WRITER_VOICE_CALLER_TRANSCRIPT : AIConversationMessageWriter::WRITER_VOICE_AGENT_TRANSCRIPT,
                'role' => $role,
                'spokenFromMs' => $spokenFromMs,
                'spokenUntilMs' => $spokenUntilMs,
                'chars' => mb_strlen($spokenSentence),
                'fromBacklog' => $fromBacklog,
            ],
            durationMs: $spokenFromMs !== null && $spokenUntilMs !== null ? $spokenUntilMs - $spokenFromMs : null,
            messageIndex: $messageIndex !== null ? (int)$messageIndex : null,
            providerOffsetMs: $spokenFromMs
        ));
    }

    /**
     * Whether a turn is running on the call's conversation right now — read fresh, whoever started it. Remembered
     * so the watcher knows to release the backlog when the run has settled.
     */
    protected function isAttachedConversationRunning(AgentVoiceCall $agentVoiceCall): bool
    {
        if ($agentVoiceCall->aiConversationId === null) {
            return false;
        }
        try {
            $freshConversation = $this->getAIConversationsService()->find($agentVoiceCall->aiConversationId, false);
            $this->foreignTurnObservedRunning = $freshConversation instanceof AIConversation
                && $freshConversation->getRunState()->isNonTerminal();
        } catch (Throwable) {
            $this->foreignTurnObservedRunning = false;
        }

        return $this->foreignTurnObservedRunning;
    }

    /**
     * `session.delegation.created` — the model wants the backend. The event carries NO task text, and the
     * caller's words are ALREADY the transcript: the open sentence is closed and persisted right now, and the
     * agent turn is driven by a turn-owing signal on that transcript ({@see AgentCallTasksService::startAgentTaskOnTranscript()})
     * — never by a copy of the sentences, which doubled every request in the chat. One task at a time (the
     * bridge's busy rule); a second delegation while one runs gets a `thinking` note instead of a second task.
     * A delegation with nothing said since the last one asks the caller to repeat.
     *
     * @param array<string, mixed> $sessionEvent
     */
    protected function startDelegatedAgentTask(
        AgentVoiceCall $agentVoiceCall,
        WebSocket $webSocketConnection,
        array $sessionEvent,
        bool $startedFromQueue = false
    ): void {
        $delegationObject = is_array($sessionEvent['delegation'] ?? null) ? $sessionEvent['delegation'] : [];
        $delegationId = (string)($delegationObject['id'] ?? $sessionEvent['delegation_id'] ?? '');
        if ($delegationId === '') {
            return;
        }
        if ($this->pendingAgentTaskAfterMessageIndex !== null) {
            // One task at a time — but what the caller just said is NOT lost: it is on the transcript already,
            // and this delegation starts the moment the pending result is spoken (a "wait" note alone would let
            // what the caller said expire from the request window).
            $this->queuedDelegation = AgentVoiceQueuedDelegation::create(
                $delegationId,
                $this->queuedDelegation->queuedAtUnixMs ?? (int)floor(microtime(true) * 1000)
            );
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} delegation {$delegationId} queued behind the running task"
            );
            $this->bufferTraceEvent(
                $agentVoiceCall,
                AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                'delegation.queued',
                'delegation queued behind the running task',
                correlationIds: ['delegationId' => $delegationId]
            );
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.thinking.append',
                'delegation_id' => $delegationId,
                'content' => 'The backend is still working on the previous request; what the caller just said goes'
                    . ' to it right after that result. Bridge briefly, do not start over and do not guess.',
            ]);

            return;
        }

        // What the caller said since the last hand-over is the request — composing it consumes the watermark,
        // and the text itself is only the emptiness check: the transcript carries it to the agent.
        $callerRequestText = $this->composeCallerRequestText();
        $this->persistCompletedSentences($agentVoiceCall, $this->liveTranscriptAssembler->closeOpenCallerSentence());
        if ($callerRequestText === '') {
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.thinking.append',
                'delegation_id' => $delegationId,
                'content' => $startedFromQueue
                    ? 'The caller said nothing new since the previous request — the result just spoken covers it;'
                        . ' continue from there.'
                    : 'The caller\'s request was not captured. Ask them briefly to repeat what they need.',
            ]);

            return;
        }

        $aiConversation = $this->findAttachedAiConversation($agentVoiceCall);
        if (!$aiConversation || $aiConversation->id === null) {
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.thinking.append',
                'delegation_id' => $delegationId,
                'content' => 'No backend conversation is attached to this call — take the request down as a'
                    . ' message and say so honestly.',
            ]);

            return;
        }

        /** @var AgentCallTasksService $agentCallTasksService */
        $agentCallTasksService = $this->getAgentCallTasksService();
        $agentTaskStart = $agentCallTasksService->startAgentTaskOnTranscript($aiConversation);
        if ($agentTaskStart->isBusy()) {
            // A turn is already running — started by the supervisor, a yield-continue of the previous task, or
            // the chat. The caller's request is in the transcript (just closed above; the backlog carries the
            // rest), so that turn's answer IS the answer to this delegation: attach to it instead of telling the
            // caller the backend is busy, and speak whatever it produces after the rows written so far.
            // the persisted newest index — one scalar read, never a hydration of the history
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = $this->getAIConversationMessagesService();
            $persistedNewestIndex = $aIConversationMessagesService->findNewestMessageIndex($aiConversation);
            $this->pendingDelegationId = $delegationId;
            $this->pendingAgentTaskAfterMessageIndex = max(0, $persistedNewestIndex ?? -1);
            $this->pendingAgentTaskStartedAtUnixTime = time();
            $this->pendingAgentTaskRedriven = false;
            $this->startTaskProgressSchedule();
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} delegation {$delegationId} attached to the running turn"
                . " (after message index {$this->pendingAgentTaskAfterMessageIndex})"
            );
            $this->bufferTraceEvent(
                $agentVoiceCall,
                AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                'delegation.attached',
                'delegation attached to the turn already running',
                correlationIds: ['delegationId' => $delegationId],
                messageIndex: $this->pendingAgentTaskAfterMessageIndex
            );
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.thinking.append',
                'delegation_id' => $delegationId,
                'content' => 'The backend is already working on it; the result follows as spoken commentary.'
                    . ' Bridge briefly, do not guess.',
            ]);

            return;
        }
        if (!$agentTaskStart->started) {
            $agentTaskStartRefusal = $agentTaskStart->error ?? $agentTaskStart->status ?? 'unknown';
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.thinking.append',
                'delegation_id' => $delegationId,
                'content' => 'The backend could not take the request right now (' . $agentTaskStartRefusal
                    . '). Tell the caller honestly and say what you can do instead.',
            ]);

            return;
        }
        $this->pendingDelegationId = $delegationId;
        $this->pendingAgentTaskAfterMessageIndex = (int)$agentTaskStart->taskUserMessageIndex;
        $this->pendingAgentTaskStartedAtUnixTime = time();
        $this->pendingAgentTaskRedriven = false;
        $this->startTaskProgressSchedule();
        $this->appendToVoiceLog(
            "LIVE {$agentVoiceCall->providerCallId} delegation {$delegationId} → agent task on the transcript"
            . " (after message index {$this->pendingAgentTaskAfterMessageIndex}, "
            . mb_strlen($callerRequestText) . ' chars of caller speech)'
        );
        $this->bufferTraceEvent(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED,
            'agent turn started on the transcript (' . mb_strlen($callerRequestText) . ' chars of caller speech)'
                . ($startedFromQueue ? ', from the queue' : ''),
            payload: ['requestChars' => mb_strlen($callerRequestText), 'startedFromQueue' => $startedFromQueue],
            correlationIds: ['delegationId' => $delegationId],
            messageIndex: $this->pendingAgentTaskAfterMessageIndex
        );
        $this->flushTraceEvents();
    }

    /**
     * The pending task's outcome, read fresh each tick; once it settled, the answer goes back as spoken
     * COMMENTARY under the delegation's id — cut to the append cap, with the chat named as the place for the
     * rest. The transcript backlog is released in the same step.
     */
    protected function appendDelegatedTaskResultWhenFinished(
        AgentVoiceCall $agentVoiceCall,
        WebSocket $webSocketConnection
    ): void {
        if ($this->pendingAgentTaskAfterMessageIndex === null) {
            return;
        }
        $aiConversation = $this->findAttachedAiConversation($agentVoiceCall);
        if (!$aiConversation) {
            return;
        }
        /** @var AgentCallTasksService $agentCallTasksService */
        $agentCallTasksService = $this->getAgentCallTasksService();
        $agentTaskOutcome = $agentCallTasksService->findAgentTaskOutcome(
            $aiConversation,
            (int)$this->pendingAgentTaskAfterMessageIndex
        );
        if ($agentTaskOutcome->isWorking()) {
            // LOST-TASK guard: "working" with the conversation IDLE means nobody is working — the turn message
            // never reached a healthy worker. Re-drive once (the request is still in the transcript); the second
            // time, tell the caller honestly instead of waiting for the rest of the call.
            $taskAgeSeconds = $this->pendingAgentTaskStartedAtUnixTime !== null
                ? time() - $this->pendingAgentTaskStartedAtUnixTime
                : 0;
            if ($taskAgeSeconds < static::DELEGATED_TASK_LOST_AFTER_SECONDS || $this->isAttachedConversationRunning($agentVoiceCall)) {
                return;
            }
            if (!$this->pendingAgentTaskRedriven) {
                $this->pendingAgentTaskRedriven = true;
                $this->pendingAgentTaskStartedAtUnixTime = time();
                $redrive = $agentCallTasksService->startAgentTaskOnTranscript($aiConversation);
                $this->appendToVoiceLog(
                    "LIVE {$agentVoiceCall->providerCallId} delegated task lost after {$taskAgeSeconds}s (conversation idle,"
                    . ' no answer) — re-driven once: ' . ($redrive->started ? 'ok' : (string)($redrive->status ?? $redrive->error ?? 'refused'))
                );
                if ($redrive->started && $redrive->taskUserMessageIndex !== null) {
                    $this->pendingAgentTaskAfterMessageIndex = $redrive->taskUserMessageIndex;
                }
                $this->bufferTraceEvent(
                    $agentVoiceCall,
                    AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                    'task.redriven',
                    "task lost after {$taskAgeSeconds}s with the conversation idle — re-driven once",
                    correlationIds: ['delegationId' => $this->pendingDelegationId],
                    messageIndex: $this->pendingAgentTaskAfterMessageIndex
                );

                return;
            }
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} delegated task lost twice — reporting the failure to the caller"
            );
            $agentTaskOutcome = AgentCallTaskOutcome::forFailed('the backend did not pick the request up');
        }
        $taskRunSeconds = $this->pendingAgentTaskStartedAtUnixTime !== null
            ? time() - $this->pendingAgentTaskStartedAtUnixTime
            : null;
        $taskUserMessageIndex = $this->pendingAgentTaskAfterMessageIndex;
        $this->pendingAgentTaskStartedAtUnixTime = null;
        $this->pendingAgentTaskRedriven = false;

        $delegationId = $this->pendingDelegationId;
        $this->pendingDelegationId = null;
        $this->pendingAgentTaskAfterMessageIndex = null;
        // the hand-over: the task's progress ends here — sentences from now on answer the result, never a progress note
        $this->taskProgressSchedule = null;
        $this->progressCommentarySentAtSessionMs = null;
        $this->flushTranscriptBacklog($agentVoiceCall);

        $realtimeVoiceProviderAdapter = $this->getVoiceProviderAdapterOfCall($agentVoiceCall);
        $spokenAnswerText = null;
        if ($agentTaskOutcome->isCompleted()) {
            // a widget block is never handed to the voice (the stored chat row keeps it); `widgetInCommentary`
            // below still reports that the answer carried one. Nothing or only headings left once the blocks are
            // gone → it says where the content is instead
            $spokenAnswerText = AgentVoiceSpokenAnswer::fromAnswerText(
                (string)$agentTaskOutcome->answerText,
                (string)$agentVoiceCall->getChannelType()
            )->spokenText;
            $commentaryText = $realtimeVoiceProviderAdapter->cutToAppendCap($spokenAnswerText);
        } else {
            $commentaryText = 'The backend task failed'
                . ($agentTaskOutcome->answerText !== null ? ': ' . mb_substr($agentTaskOutcome->answerText, 0, 400) : '')
                . '. Tell the caller honestly and say what you can do instead.';
        }
        $this->bufferTraceEvent(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            AIConversationTraceEvent::EVENT_TYPE_TASK_OUTCOME,
            'agent turn ' . $agentTaskOutcome->status . ($taskRunSeconds !== null ? " after ~{$taskRunSeconds} s" : '')
                . ' — answer handed to the voice model (' . mb_strlen($commentaryText) . ' chars'
                . (mb_strlen($spokenAnswerText ?? (string)$agentTaskOutcome->answerText) > mb_strlen($commentaryText) ? ', cut' : '') . ')',
            payload: [
                'status' => $agentTaskOutcome->status,
                // the spoken answer (widget blocks removed, or the "shown in the chat" sentence of a widget-only
                // answer): a stripped widget is no cut (ANSWER_CUT reads these two)
                'answerChars' => mb_strlen($spokenAnswerText ?? (string)$agentTaskOutcome->answerText),
                'commentaryChars' => mb_strlen($commentaryText),
                // a widget block with JSON, any syntax (telemetry, not a gate): an empty fence no longer
                // counts, a block with invalid JSON still does
                'widgetInCommentary' => WidgetBlocks::fromMarkdown((string)$agentTaskOutcome->answerText)->getWidgetJsons() !== [],
            ],
            correlationIds: ['delegationId' => $delegationId],
            durationMs: $taskRunSeconds !== null ? $taskRunSeconds * 1000 : null,
            messageIndex: $taskUserMessageIndex
        );
        $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
            'type' => 'session.commentary.append',
            'delegation_id' => $delegationId,
            'content' => $commentaryText,
        ]);
        $this->lastAnswerHandedOverAtUnixMs = AIConversationTraceEvent::nowUnixMs();
        $this->callerAnswerAwaitedSinceUnixTime = str_ends_with(rtrim($commentaryText), '?') ? time() : null;
        $this->unansweredQuestionReasked = false;

        if ($this->queuedDelegation !== null) {
            // Started while the queue mark still stands (the request window reads it), cleared right after.
            $queuedDelegationId = $this->queuedDelegation->delegationId;
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} starting the queued delegation {$queuedDelegationId}"
            );
            $this->startDelegatedAgentTask(
                $agentVoiceCall,
                $webSocketConnection,
                ['delegation' => ['id' => $queuedDelegationId]],
                startedFromQueue: true
            );
            $this->queuedDelegation = null;
        }
    }

    /**
     * Keep one completed caller sentence as material for the next hand-over (a delegation's request). The agent's
     * sentences are never material.
     */
    protected function rememberCallerSentence(LiveTranscriptSentence $completedSentence): void
    {
        if ($completedSentence->role !== AIConversationMessage::ROLE_USER) {
            return;
        }
        $this->recentCallerSentences[] = AgentVoiceCallerRequestSentence::create(
            $completedSentence->text,
            (int)floor(microtime(true) * 1000)
        );
        $this->recentCallerSentences = array_slice($this->recentCallerSentences, -static::RECENT_CALLER_SENTENCES_KEPT);
    }

    /**
     * What the caller said since the last hand-over — the completed sentences of the last
     * {@see TASK_CONTEXT_WINDOW_SECONDS} not yet consumed, plus the not yet consumed part of the one still open.
     * The delegation event carries no text, so this is the request.
     * Composing CONSUMES: the watermark moves to now and the open sentence is marked handed over, so a second
     * delegation never re-sends (or re-checks) the same words.
     */
    protected function composeCallerRequestText(): string
    {
        $requestSentenceTexts = [];
        $nowUnixMs = (int)floor(microtime(true) * 1000);
        // A queued delegation's request was spoken BEFORE the queue time — the window reaches back to it.
        $windowStartUnixMs = min(
            $nowUnixMs - static::TASK_CONTEXT_WINDOW_SECONDS * 1000,
            ($this->queuedDelegation->queuedAtUnixMs ?? $nowUnixMs) - static::TASK_CONTEXT_WINDOW_SECONDS * 1000
        );
        foreach ($this->recentCallerSentences as $recentCallerSentence) {
            if ($recentCallerSentence->completedAtUnixMs >= $windowStartUnixMs
                && $recentCallerSentence->completedAtUnixMs > $this->callerSentencesConsumedUntilUnixMs
            ) {
                $requestSentenceTexts[] = $recentCallerSentence->text;
            }
        }
        $openCallerSentenceText = $this->liveTranscriptAssembler->getOpenCallerSentenceText();
        if ($openCallerSentenceText !== '') {
            $requestSentenceTexts[] = $openCallerSentenceText;
        }
        $this->callerSentencesConsumedUntilUnixMs = $nowUnixMs;
        $this->liveTranscriptAssembler->consumeOpenCallerSentence();

        return trim(implode(' ', $requestSentenceTexts));
    }

    /**
     * Say ONE last thing, then hang up: the farewell goes out as a session-wide instruction (the one append
     * documented without a delegation — a farewell has none), the session is ended after
     * {@see FAREWELL_GOODBYE_SECONDS}. Used by the stop of a refused call and by the silence stop. Armed once.
     */
    protected function armFarewellHangUp(
        AgentVoiceCall $agentVoiceCall,
        WebSocket $webSocketConnection,
        string $farewellInstruction,
        string $endReason,
        ?string $delegationId = null
    ): void {
        if ($this->farewellHangUpArmed) {
            return;
        }
        $this->farewellHangUpArmed = true;
        $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
            'type' => 'session.instructions.append',
            'delegation_id' => $delegationId,
            'content' => $farewellInstruction,
        ]);
        $this->attachedAgentVoiceCall = $agentVoiceCall;
        $this->sidebandWebSocketConnection = $webSocketConnection;
        $this->farewellHangUpEndReason = $endReason;
        $this->farewellHangUpTimer = Loop::get()->addTimer(
            static::FAREWELL_GOODBYE_SECONDS,
            [$this, 'hangUpAfterFarewellWasSpoken']
        );
    }

    /**
     * The farewell timer: hang up and end the call with the armed reason. Public only because the event loop calls it.
     */
    public function hangUpAfterFarewellWasSpoken(): void
    {
        if ($this->attachedAgentVoiceCall === null) {
            return;
        }
        $this->hangUpSessionBestEffort($this->attachedAgentVoiceCall, $this->sidebandWebSocketConnection);
        $this->finishCall(
            $this->attachedAgentVoiceCall,
            $this->farewellHangUpEndReason ?? AgentVoiceCall::END_REASON_COMPLETED
        );
        $this->flushTraceEvents();
        $this->stopEventLoopOfTheCall();
    }

    /**
     * `endVoiceCall` on THIS call ({@see AgentVoiceCallsService::requestCallEndAfterGoodbye()}): read every watcher tick
     * and, while a task runs, every fast check (one Redis GET — no row reload), armed when the request appears; once armed
     * the fast check ({@see runGoodbyeAndTaskOutcomeCheck()}) asks every 0.5 s, and the session is hung up once
     * {@see AgentVoiceCallGoodbyeHangUp::isDueAt()} says the goodbye was spoken: the delegated turn's answer was handed to
     * the voice model, the agent's voice produced output after it and has been quiet for 1.5 s (bounded, see the value object). Then
     * the call ends with the requested reason through the shared teardown ({@see finishCall()}: transcript flushed,
     * call-end marker written). A farewell another guard armed (a refused call, a silent line) wins — it ends the call anyway.
     * True when the call was hung up and the tick must stop.
     */
    protected function hangUpAfterRequestedGoodbyeWhenDue(AgentVoiceCall $agentVoiceCall, WebSocket $webSocketConnection): bool
    {
        if ($this->callFinished || $this->farewellHangUpArmed) {
            return false;
        }
        if ($this->requestedGoodbyeHangUp === null) {
            $requestedEndReason = $this->findRequestedCallEndAfterGoodbye($agentVoiceCall);
            if ($requestedEndReason === null) {
                return false;
            }
            $this->requestedGoodbyeHangUp = AgentVoiceCallGoodbyeHangUp::forLiveSession(
                AIConversationTraceEvent::nowUnixMs(),
                $requestedEndReason
            );
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} end requested by {$this->getAgentLabel()} ({$requestedEndReason}) — hanging up after the goodbye"
            );
            $this->bufferTraceEvent(
                $agentVoiceCall,
                AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                static::TRACE_EVENT_TYPE_GOODBYE_HANG_UP_ARMED,
                $this->getAgentLabel() . ' ended the call — the hang-up waits for the goodbye to be spoken',
                payload: ['endReason' => $requestedEndReason]
            );
        }
        $nowUnixMs = AIConversationTraceEvent::nowUnixMs();
        if (!$this->requestedGoodbyeHangUp->isDueAt(
            $nowUnixMs,
            $this->lastAgentSpeechAtUnixMs,
            $this->lastAnswerHandedOverAtUnixMs,
            $this->pendingAgentTaskAfterMessageIndex !== null
        )) {
            return false;
        }
        $millisecondsWaited = $nowUnixMs - $this->requestedGoodbyeHangUp->armedAtUnixMs;
        $secondsWaited = round($millisecondsWaited / 1000, 1);
        $millisecondsSinceLastAgentSpeech = $this->lastAgentSpeechAtUnixMs !== null ? $nowUnixMs - $this->lastAgentSpeechAtUnixMs : null;
        $this->appendToVoiceLog(
            "LIVE {$agentVoiceCall->providerCallId} goodbye spoken — hanging up ({$secondsWaited}s after the request was read, "
            . ($millisecondsSinceLastAgentSpeech !== null ? "{$millisecondsSinceLastAgentSpeech}ms after the last output of {$this->getAgentLabel()}" : "no output from {$this->getAgentLabel()}") . ')'
        );
        $this->bufferTraceEvent(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            static::TRACE_EVENT_TYPE_GOODBYE_HANG_UP_DONE,
            "hung up after the goodbye of {$this->getAgentLabel()} ({$secondsWaited} s after the end request)",
            payload: [
                'endReason' => $this->requestedGoodbyeHangUp->endReason,
                'secondsWaited' => $secondsWaited,
                'millisecondsSinceLastAgentSpeech' => $millisecondsSinceLastAgentSpeech,
            ],
            durationMs: $millisecondsWaited
        );
        $this->hangUpSessionBestEffort($agentVoiceCall, $webSocketConnection);
        $this->finishCall($agentVoiceCall, $this->requestedGoodbyeHangUp->endReason);
        $this->clearRequestedCallEndAfterGoodbye($agentVoiceCall);
        $this->flushTraceEvents();
        $this->stopEventLoopOfTheCall();

        return true;
    }

    /**
     * The fast check, every {@see GOODBYE_AND_TASK_OUTCOME_CHECK_INTERVAL_SECONDS} s, armed only while there is something
     * to wait for: a delegated task (its outcome is handed to the voice model the moment the turn settled) or a goodbye
     * hang-up (the line drops {@see AgentVoiceCallGoodbyeHangUp::LIVE_QUIET_MILLISECONDS} after the agent's goodbye, not on the
     * next 2 s tick). While a task runs it also reads the goodbye request, so a request written by that turn is armed
     * before its answer is handed over. Idle otherwise; cancelled once the call is finished. Errors never reach the loop.
     */
    protected function runGoodbyeAndTaskOutcomeCheck(AgentVoiceCall $agentVoiceCall, WebSocket $webSocketConnection): void
    {
        if ($this->callFinished) {
            $this->cancelGoodbyeAndTaskOutcomeTimer();

            return;
        }
        if ($this->requestedGoodbyeHangUp === null && $this->pendingAgentTaskAfterMessageIndex === null) {
            return;
        }
        try {
            $this->appendDelegatedTaskResultWhenFinished($agentVoiceCall, $webSocketConnection);
            $this->hangUpAfterRequestedGoodbyeWhenDue($agentVoiceCall, $webSocketConnection);
            // after the outcome poll (the hand-over tick sends no progress) and after the goodbye read (an armed
            // goodbye suppresses it the tick it appears)
            $this->sendPendingTaskProgressWhenDue($agentVoiceCall, $webSocketConnection);
        } catch (Throwable $fastCheckError) {
            $fastCheckFailureLine = "LIVE {$agentVoiceCall->providerCallId} goodbye/task-outcome check failed: "
                . $fastCheckError->getMessage();
            $this->appendToVoiceLog($fastCheckFailureLine);
            DDDService::instance()->getLogger()->error($fastCheckFailureLine);
        }
    }

    /**
     * The voice worker is a reader of the call conversation's activity lines: stamped on every watcher
     * tick while the call has a conversation, so the turn's steps are narrated while the call runs. The conversation is
     * the one the worker already holds — no extra read per tick. Fail-soft.
     */
    protected function markActivityReaderPresentForTheCallConversation(AgentVoiceCall $agentVoiceCall): void
    {
        if ($agentVoiceCall->aiConversationId === null) {
            return;
        }
        try {
            $attachedAiConversation = $this->findAttachedAiConversation($agentVoiceCall);
            if ($attachedAiConversation === null) {
                return;
            }
            $this->markActivityReaderPresent($attachedAiConversation->getTreeRootConversationId());
        } catch (Throwable) {
            // no stamp only means no narration until the next tick
        }
    }

    /** {@see AgentActivityLinesService::markReaderPresent()}; overridable for tests. */
    protected function markActivityReaderPresent(int $rootConversationId): void
    {
        /** @var AgentActivityLinesService $agentActivityLinesService */
        $agentActivityLinesService = $this->getAgentActivityLinesService();
        $agentActivityLinesService->markReaderPresent($rootConversationId);
    }

    /** {@see AgentActivityLinesService::findTurnFeedForTaskAtOrAfterIndex()} — two cache reads; overridable for tests. */
    protected function findActivityTurnFeed(int $rootConversationId, int $taskMessageIndex): ?AgentActivityTurnFeed
    {
        /** @var AgentActivityLinesService $agentActivityLinesService */
        $agentActivityLinesService = $this->getAgentActivityLinesService();
        return $agentActivityLinesService->findTurnFeedForTaskAtOrAfterIndex($rootConversationId, $taskMessageIndex);
    }

    /** A new delegated task: its own progress schedule, no progress commentary outstanding. */
    protected function startTaskProgressSchedule(): void
    {
        $this->taskProgressSchedule = AgentVoiceTaskProgressSchedule::startForDelegationAt(AIConversationTraceEvent::nowUnixMs());
        $this->progressCommentarySentAtSessionMs = null;
    }

    /**
     * What the caller hears while the pending task runs: the turn's activity feed (two cache reads) goes
     * to the task's {@see AgentVoiceTaskProgressSchedule}, and its decision is sent — the newest line as a spoken
     * progress commentary under the pending delegation, other new lines and the steps without a line as unspoken
     * thinking, the generic bridge as an instruction. Runs on the fast check AFTER the outcome poll, so the hand-over
     * tick sends nothing; never after an armed goodbye or a farewell. Errors stay in the fast check's catch.
     */
    protected function sendPendingTaskProgressWhenDue(AgentVoiceCall $agentVoiceCall, WebSocket $webSocketConnection): void
    {
        if ($this->callFinished || $this->pendingAgentTaskAfterMessageIndex === null || $this->taskProgressSchedule === null
            || $this->requestedGoodbyeHangUp !== null || $this->farewellHangUpArmed || $this->pendingDelegationId === null
        ) {
            return;
        }
        $attachedAiConversation = $this->findAttachedAiConversation($agentVoiceCall);
        if ($attachedAiConversation === null) {
            return;
        }
        $nowUnixMs = AIConversationTraceEvent::nowUnixMs();
        $turnFeed = $this->findActivityTurnFeed(
            $attachedAiConversation->getTreeRootConversationId(),
            (int)$this->pendingAgentTaskAfterMessageIndex
        );
        $progressDecision = $this->taskProgressSchedule->decideUpdateAt($turnFeed, $nowUnixMs, $this->lastSpeechAtUnixMs);
        $secondsSinceDelegation = round(($nowUnixMs - $this->taskProgressSchedule->delegatedAtUnixMs) / 1000, 1);

        if ($progressDecision->firstLineSeenAfterMilliseconds !== null) {
            $this->bufferTraceEvent(
                $agentVoiceCall,
                AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                static::TRACE_EVENT_TYPE_ACTIVITY_FEED_LATENCY,
                'first activity line seen ' . round($progressDecision->firstLineSeenAfterMilliseconds / 1000, 1)
                    . ' s after the delegation',
                payload: ['millisecondsSinceDelegation' => $progressDecision->firstLineSeenAfterMilliseconds],
                correlationIds: ['delegationId' => $this->pendingDelegationId],
                durationMs: $progressDecision->firstLineSeenAfterMilliseconds,
                messageIndex: $this->pendingAgentTaskAfterMessageIndex
            );
        }
        if ($progressDecision->spokenProgressLine !== null) {
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.commentary.append',
                'delegation_id' => $this->pendingDelegationId,
                'content' => 'Progress, not the result: ' . rtrim(trim($progressDecision->spokenProgressLine), '.')
                    . '. Tell the caller in one short sentence what you are doing now; state no result — it follows.'
                    . $this->buildKeepTheCallLanguageClause($agentVoiceCall),
            ]);
            $this->progressCommentarySentAtSessionMs = $this->liveTranscriptAssembler->getLastFragmentEndMs();
            $this->bufferTaskProgressSentTraceEvent(
                $agentVoiceCall,
                static::TASK_PROGRESS_KIND_SPOKEN,
                $progressDecision->spokenProgressLine,
                $secondsSinceDelegation
            );
        }
        if ($progressDecision->silentContextLines !== []) {
            $silentContextText = implode(' ', array_map(
                static fn (string $silentContextLine): string => rtrim(trim($silentContextLine), '.') . '.',
                $progressDecision->silentContextLines
            ));
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.thinking.append',
                'delegation_id' => $this->pendingDelegationId,
                'content' => 'What the backend is doing (do not read it out; use it only if the caller asks what you are'
                    . ' doing; no result yet): ' . $silentContextText,
            ]);
            $this->bufferTaskProgressSentTraceEvent(
                $agentVoiceCall,
                static::TASK_PROGRESS_KIND_SILENT,
                $silentContextText,
                $secondsSinceDelegation
            );
        }
        if ($progressDecision->bridgeDue) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} no new activity line {$secondsSinceDelegation}s into the task — asking for a bridge line"
            );
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.instructions.append',
                'delegation_id' => null,
                'content' => 'The backend is still working on the request. Say so in one short sentence — no'
                    . ' question, do not ask whether the caller is there — then keep listening.'
                    . $this->buildKeepTheCallLanguageClause($agentVoiceCall),
            ]);
            $this->bufferTaskProgressSentTraceEvent($agentVoiceCall, static::TASK_PROGRESS_KIND_BRIDGE, null, $secondsSinceDelegation);
        }
    }

    /**
     * Whether a completed sentence is a progress sentence — decided when it is pushed to the backlog: the agent's, while a
     * progress commentary of the pending task is outstanding, and STARTED after that commentary went out (a sentence
     * already under way then — the acknowledgement — is an ordinary one). Everything else stays an ordinary row.
     */
    protected function isProgressSentence(string $role, ?int $sentenceStartMs): bool
    {
        return $role === AIConversationMessage::ROLE_ASSISTANT
            && $this->pendingAgentTaskAfterMessageIndex !== null
            && $this->progressCommentarySentAtSessionMs !== null
            && $sentenceStartMs !== null
            && $sentenceStartMs > $this->progressCommentarySentAtSessionMs;
    }

    /**
     * One progress sentence the agent spoke, into the call's trace instead of the chat.
     */
    protected function bufferProgressSentenceTraceEvent(AgentVoiceCall $agentVoiceCall, LiveTranscriptBacklogSentence $heldBackTranscript): void
    {
        $spokenFromMs = $heldBackTranscript->startMs;
        $spokenUntilMs = $heldBackTranscript->endMs;
        $this->bufferTraceEvent(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            static::TRACE_EVENT_TYPE_TASK_PROGRESS_SPOKEN,
            $this->getAgentLabel() . ' said a progress sentence: ' . $heldBackTranscript->text,
            payload: [
                'text' => $heldBackTranscript->text,
                'spokenFromMs' => $spokenFromMs,
                'spokenUntilMs' => $spokenUntilMs,
            ],
            durationMs: $spokenFromMs !== null && $spokenUntilMs !== null ? $spokenUntilMs - $spokenFromMs : null,
            providerOffsetMs: $spokenFromMs
        );
    }

    /** One {@see TRACE_EVENT_TYPE_TASK_PROGRESS_SENT} event: the line (none for the bridge), the kind, the task's age. */
    protected function bufferTaskProgressSentTraceEvent(
        AgentVoiceCall $agentVoiceCall,
        string $progressKind,
        ?string $progressLine,
        float $secondsSinceDelegation
    ): void {
        $this->bufferTraceEvent(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            static::TRACE_EVENT_TYPE_TASK_PROGRESS_SENT,
            "{$progressKind} progress {$secondsSinceDelegation} s after the delegation"
                . ($progressLine !== null ? ': ' . $progressLine : ''),
            payload: ['kind' => $progressKind, 'line' => $progressLine, 'secondsSinceDelegation' => $secondsSinceDelegation],
            correlationIds: ['delegationId' => $this->pendingDelegationId],
            messageIndex: $this->pendingAgentTaskAfterMessageIndex
        );
    }

    /** Stop the fast check — the call is over. */
    protected function cancelGoodbyeAndTaskOutcomeTimer(): void
    {
        if ($this->goodbyeAndTaskOutcomeTimer === null) {
            return;
        }
        Loop::get()->cancelTimer($this->goodbyeAndTaskOutcomeTimer);
        $this->goodbyeAndTaskOutcomeTimer = null;
    }

    /** {@see AgentVoiceCallsService::findRequestedCallEndAfterGoodbye()}; overridable for tests. */
    protected function findRequestedCallEndAfterGoodbye(AgentVoiceCall $agentVoiceCall): ?string
    {
        /** @var AgentVoiceCallsService $agentVoiceCallsService */
        $agentVoiceCallsService = $this->getAgentVoiceCallsService();
        return $agentVoiceCallsService->findRequestedCallEndAfterGoodbye($agentVoiceCall);
    }

    /** {@see AgentVoiceCallsService::clearRequestedCallEndAfterGoodbye()}; overridable for tests. */
    protected function clearRequestedCallEndAfterGoodbye(AgentVoiceCall $agentVoiceCall): void
    {
        /** @var AgentVoiceCallsService $agentVoiceCallsService */
        $agentVoiceCallsService = $this->getAgentVoiceCallsService();
        $agentVoiceCallsService->clearRequestedCallEndAfterGoodbye($agentVoiceCall);
    }

    /**
     * Book the seconds since the last booking. The counter is CUMULATIVE — every update replaces the previous
     * snapshot — so only the increment is booked, and only once it reaches `usageBookingMinimumSeconds` (one usage
     * record per event is noise). Best-effort: accounting must never break the session loop.
     *
     * @param array<string, mixed> $sessionEvent
     */
    protected function bookLiveSessionUsage(
        AgentVoiceCall $agentVoiceCall,
        array $sessionEvent,
        bool $final = false
    ): void {
        try {
            $usageObject = is_array($sessionEvent['usage'] ?? null) ? $sessionEvent['usage'] : [];
            if ($usageObject === []) {
                return;
            }
            $this->usageEventReceived = true;
            $cumulativeSessionSeconds = (int)($usageObject['seconds'] ?? 0);
            $secondsToBill = $cumulativeSessionSeconds - $this->billedSessionSeconds;
            if ($secondsToBill <= 0 || (!$final && $secondsToBill < AgentVoiceConfiguration::current()->usageBookingMinimumSeconds)) {
                return;
            }
            $this->bookLiveSessionSeconds($agentVoiceCall, $secondsToBill, $cumulativeSessionSeconds);
        } catch (Throwable $usageAccountingError) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} usage accounting failed: " . $usageAccountingError->getMessage()
            );
        }
    }

    /**
     * Book ONE increment of seconds, each side once:
     * 1. the usage, through {@see AISessionUsageService::recordSessionSeconds()} on the session model call of the
     *    running call ({@see AgentVoiceCallAdmissionService::buildSessionModelCallOfRunningCall()}, the one the
     *    running-call check asks the gate with; the application's usage recorder writes its ledger there, and without
     *    a catalog price of the model nothing is booked on that side). A call nobody can be billed for records no
     *    usage. A record that fails leaves everything as it was: the increment comes again with the next report;
     * 2. once the usage is recorded, the billed counter and the shared baseline advance: these seconds are never
     *    recorded as usage again, whatever the write of the call does;
     * 3. the same seconds onto the call ({@see addOwedBookedSecondsToTheCall()}), also for a call nobody can be billed
     *    for: the daily limit and the list of calls read them there. A write that fails keeps the seconds owed.
     *
     * @throws Throwable
     */
    protected function bookLiveSessionSeconds(
        AgentVoiceCall $agentVoiceCall,
        int $secondsToBill,
        int $cumulativeSessionSecondsAfterBooking
    ): void {
        $sessionModelCall = $this->getAgentVoiceCallAdmissionService()->buildSessionModelCallOfRunningCall($agentVoiceCall);
        if ($sessionModelCall !== null) {
            $this->getAISessionUsageService()->recordSessionSeconds($sessionModelCall, (float)$secondsToBill);
        }
        $this->billedSessionSeconds = $cumulativeSessionSecondsAfterBooking;
        $this->bookedSecondsOwedToTheCall += $secondsToBill;
        $this->advanceBilledSecondsBaseline((string)$agentVoiceCall->providerCallId, $secondsToBill);
        $this->addOwedBookedSecondsToTheCall($agentVoiceCall);
    }

    /**
     * Writes the seconds the call has not taken yet onto it ({@see AgentVoiceCallStoreService::addBookedSeconds()}):
     * the increment just booked and what an earlier write left. A write that fails throws and keeps them owed, for the
     * next booking or for the teardown ({@see addOwedBookedSecondsToTheCallAtTheTeardown()}).
     *
     * @throws Throwable
     */
    protected function addOwedBookedSecondsToTheCall(AgentVoiceCall $agentVoiceCall): void
    {
        if ($this->bookedSecondsOwedToTheCall <= 0) {
            return;
        }
        $bookedSecondsOwedToTheCall = $this->bookedSecondsOwedToTheCall;
        $this->getAgentVoiceCallStoreService()->addBookedSeconds($agentVoiceCall, $bookedSecondsOwedToTheCall);
        $this->bookedSecondsOwedToTheCall -= $bookedSecondsOwedToTheCall;
    }

    /** The last try for seconds the call still has not taken, when the call ends; a failure is logged. Never throws. */
    protected function addOwedBookedSecondsToTheCallAtTheTeardown(AgentVoiceCall $agentVoiceCall): void
    {
        try {
            $this->addOwedBookedSecondsToTheCall($agentVoiceCall);
        } catch (Throwable $usageAccountingError) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} {$this->bookedSecondsOwedToTheCall}s still owed to the call were"
                . ' not written onto it: ' . $usageAccountingError->getMessage()
            );
        }
    }

    /**
     * Opens the usage envelope of the session, unless the worker already runs inside one (an application's bus
     * middleware). Fail-soft: a call must not end because its envelope could not be opened.
     */
    protected function openSessionEnvelopeIfNoneIsOpen(AgentVoiceCall $agentVoiceCall): ?AIUsageEnvelopeContext
    {
        try {
            $aIUsageEnvelopesService = $this->getAIUsageEnvelopesService();
            if ($aIUsageEnvelopesService->hasActiveEnvelope()) {
                return null;
            }

            return $aIUsageEnvelopesService->openEnvelope(
                $agentVoiceCall->accountId !== null ? (int)$agentVoiceCall->accountId : null,
                $this->getAgentVoiceCallAdmissionService()->getSessionEnvelopeScope(),
                AIUsageInitiator::MESSENGER
            );
        } catch (Throwable $usageEnvelopeError) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} usage envelope not opened: " . $usageEnvelopeError->getMessage()
            );

            return null;
        }
    }

    /** Closes the envelope {@see openSessionEnvelopeIfNoneIsOpen()} opened; nothing when it opened none. Never throws. */
    protected function closeSessionEnvelope(?AIUsageEnvelopeContext $sessionUsageEnvelope): void
    {
        if ($sessionUsageEnvelope === null) {
            return;
        }
        try {
            $this->getAIUsageEnvelopesService()->closeEnvelope($sessionUsageEnvelope);
        } catch (Throwable) {
            // an envelope left open is dropped by the envelope service before the worker's next job
        }
    }

    /**
     * A session that was created but never reported usage still cost the 15-second initialisation charge
     * (credited only once the session runs). Booked once at teardown, so a failed or abandoned open is not free
     * on the application's books while the provider bills it.
     */
    protected function bookInitialisationChargeWhenNothingWasBooked(AgentVoiceCall $agentVoiceCall): void
    {
        if ($this->usageEventReceived || $this->billedSessionSeconds > 0) {
            return;
        }
        try {
            $this->bookLiveSessionSeconds(
                $agentVoiceCall,
                static::SESSION_INITIALISATION_CHARGE_SECONDS,
                static::SESSION_INITIALISATION_CHARGE_SECONDS
            );
        } catch (Throwable $usageAccountingError) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} initialisation-charge booking failed: "
                . $usageAccountingError->getMessage()
            );
        }
    }

    /**
     * The speech-silence guard: when NEITHER side has said a word for `speechSilentNudgeSeconds`, the model is asked
     * once to check whether the caller is still there; when the silence reaches `speechSilentHangUpSeconds`, the call
     * ends with a short goodbye. The clock starts at the first spoken word (a call nobody has spoken into yet is the
     * greeting's business). True when the hang-up was armed — the tick returns.
     */
    protected function endCallWhenNobodySpeaks(AgentVoiceCall $agentVoiceCall, WebSocket $webSocketConnection): bool
    {
        if ($this->farewellHangUpArmed || $this->callFinished || $this->lastSpeechAtUnixTime === null
            || $this->requestedGoodbyeHangUp !== null
        ) {
            return false; // a goodbye hang-up the agent asked for runs its own clock
        }
        if ($this->isSpeechSilenceGuardSuspendedByTheApplication()) {
            return false;
        }
        $silentSeconds = time() - $this->lastSpeechAtUnixTime;
        if ($this->callerAnswerAwaitedSinceUnixTime !== null && !$this->unansweredQuestionReasked
            && $this->pendingAgentTaskAfterMessageIndex === null
            && (time() - $this->callerAnswerAwaitedSinceUnixTime) >= static::UNANSWERED_QUESTION_REASK_SECONDS
        ) {
            // The backend asked and nothing came back: ask once more before anything else — the caller may have
            // answered into the agent's own speech (dropped) or simply not heard the question.
            $this->unansweredQuestionReasked = true;
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} no answer to the last question for "
                . (time() - $this->callerAnswerAwaitedSinceUnixTime) . 's — asking it once more'
            );
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.instructions.append',
                'delegation_id' => null,
                'content' => $this->voiceSessionContextsService()->buildQuestionRepeatInstruction($agentVoiceCall),
            ]);

            return false;
        }
        if ($this->pendingAgentTaskAfterMessageIndex !== null) {
            // The backend is the slow one, not the caller: no hang-up clock (a stuck task is the lost-task guard's
            // business) and no "are you still there?" — the caller waits for an answer. What the caller hears
            // meanwhile (progress, the bridge line) is the task's progress schedule ({@see sendPendingTaskProgressWhenDue()}).
            return false;
        }
        if ($silentSeconds >= AgentVoiceConfiguration::current()->speechSilentHangUpSeconds) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} nobody spoke for {$silentSeconds}s — saying goodbye and hanging up"
            );
            $this->armFarewellHangUp(
                $agentVoiceCall,
                $webSocketConnection,
                $this->buildSpeechSilenceGoodbyeInstruction($agentVoiceCall, afterNudges: false),
                AgentVoiceCall::END_REASON_COMPLETED
            );

            return true;
        }
        if (!$this->speechSilenceNudgeSent && $silentSeconds >= AgentVoiceConfiguration::current()->speechSilentNudgeSeconds) {
            if ($this->speechSilenceNudgeCount >= static::SPEECH_SILENT_MAX_NUDGES) {
                $this->appendToVoiceLog(
                    "LIVE {$agentVoiceCall->providerCallId} nobody spoke for {$silentSeconds}s after "
                    . static::SPEECH_SILENT_MAX_NUDGES . ' nudges — saying goodbye and hanging up'
                );
                $this->armFarewellHangUp(
                    $agentVoiceCall,
                    $webSocketConnection,
                    $this->buildSpeechSilenceGoodbyeInstruction($agentVoiceCall, afterNudges: true),
                    AgentVoiceCall::END_REASON_COMPLETED
                );

                return true;
            }
            $this->speechSilenceNudgeSent = true;
            $this->speechSilenceNudgeCount++;
            $this->appendToVoiceLog("LIVE {$agentVoiceCall->providerCallId} nobody spoke for {$silentSeconds}s — nudging once");
            $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, [
                'type' => 'session.instructions.append',
                'delegation_id' => null,
                'content' => $this->buildSpeechSilenceCheckInstruction($agentVoiceCall),
            ]);
        }

        return false;
    }

    /**
     * Ask the provider to end the session, both documented ways: the REST `hangup` (documented for SIP; whether
     * it applies to a WebRTC session is not documented) and, when a sideband is open, a `session.close` client event
     * (the documented WebRTC close, sent on the primary connection by the device — here on the sideband as a best
     * effort the documentation neither allows nor forbids). Never throws.
     */
    protected function hangUpSessionBestEffort(AgentVoiceCall $agentVoiceCall, ?WebSocket $webSocketConnection = null): void
    {
        try {
            $hangUpAccepted = $this->getVoiceProviderAdapterOfCall($agentVoiceCall)
                ->hangUpSession((string)$agentVoiceCall->providerCallId);
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} REST hangup " . ($hangUpAccepted ? 'accepted' : 'refused')
            );
        } catch (Throwable $hangUpError) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} REST hangup failed: " . $hangUpError->getMessage()
            );
        }
        if ($webSocketConnection !== null) {
            try {
                $this->sendLiveClientEvent($agentVoiceCall, $webSocketConnection, ['type' => 'session.close']);
            } catch (Throwable) {
                // The socket may already be gone — nothing left to close.
            }
        }
    }

    /**
     * Ends a RUNNING call that may not go on ({@see AgentVoiceCallAdmissionService::findRefusalOfRunningCall()}). The
     * caller is TOLD first — one appended farewell — then the session is hung up after
     * {@see FAREWELL_GOODBYE_SECONDS} with the refusal's end reason. Asked every `admissionRecheckSeconds`.
     */
    protected function endCallWhenItIsRefused(AgentVoiceCall $agentVoiceCall, WebSocket $webSocketConnection): bool
    {
        if ($this->farewellHangUpArmed) {
            return true;
        }
        $this->admissionRecheckTickCount++;
        if (($this->admissionRecheckTickCount % $this->getAdmissionRecheckEveryTicks()) !== 0) {
            return false;
        }
        $agentVoiceCallRefusal = $this->findRefusalOfRunningCall($agentVoiceCall);
        if ($agentVoiceCallRefusal === null) {
            return false;
        }

        $this->appendToVoiceLog(
            "LIVE {$agentVoiceCall->providerCallId} call refused mid-call ({$agentVoiceCallRefusal->code}) — saying"
            . ' goodbye and hanging up'
        );
        $this->armFarewellHangUp(
            $agentVoiceCall,
            $webSocketConnection,
            $this->buildRefusedCallGoodbyeInstruction($agentVoiceCall, $agentVoiceCallRefusal),
            $agentVoiceCallRefusal->endReason
        );

        return true;
    }

    /** Watcher ticks between two admission reads: `admissionRecheckSeconds` in ticks, at least one. */
    protected function getAdmissionRecheckEveryTicks(): int
    {
        return max(
            1,
            intdiv(AgentVoiceConfiguration::current()->admissionRecheckSeconds, static::WATCHER_TICK_SECONDS)
        );
    }

    /** {@see AgentVoiceCallAdmissionService::findRefusalOfRunningCall()}; overridable for tests. */
    protected function findRefusalOfRunningCall(AgentVoiceCall $agentVoiceCall): ?AgentVoiceCallRefusal
    {
        return $this->getAgentVoiceCallAdmissionService()->findRefusalOfRunningCall($agentVoiceCall);
    }

    /** What the voice model is told when the line has gone quiet: ask once whether the caller is still there. */
    protected function buildSpeechSilenceCheckInstruction(AgentVoiceCall $agentVoiceCall): string
    {
        return $this->voiceSessionContextsService()->buildSilenceCheckInstruction($agentVoiceCall);
    }

    /** What the voice model is told before a silent call is hung up; `$afterNudges`: the silence checks are used up. */
    protected function buildSpeechSilenceGoodbyeInstruction(AgentVoiceCall $agentVoiceCall, bool $afterNudges): string
    {
        return $this->voiceSessionContextsService()->buildGoodbyeInstruction($agentVoiceCall, $afterNudges);
    }

    /**
     * What the voice model is told before a refused running call is hung up: a used-up allowance is told as such, any
     * other refusal ends the call without a reason
     * ({@see AgentVoiceSessionContextsService::buildRefusedCallGoodbyeInstruction()}).
     */
    protected function buildRefusedCallGoodbyeInstruction(
        AgentVoiceCall $agentVoiceCall,
        AgentVoiceCallRefusal $agentVoiceCallRefusal
    ): string {
        return $this->voiceSessionContextsService()->buildRefusedCallGoodbyeInstruction($agentVoiceCall, $agentVoiceCallRefusal);
    }

    /**
     * The teardown shared by every exit — runs ONCE: close open sentences, release the backlog, book the
     * initialisation charge if nothing else was, write the seconds the call still has not taken, stamp the row. A
     * second call (a `session.closed` event followed by the socket's close frame is the normal sequence) is a no-op.
     */
    protected function finishCall(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        if ($this->callFinished) {
            return;
        }
        $this->callFinished = true;
        $this->cancelGoodbyeAndTaskOutcomeTimer();
        $this->pendingAgentTaskAfterMessageIndex = null;
        $this->pendingDelegationId = null;
        // Held-back sentences were spoken BEFORE the ones still open — flush them first so the transcript's
        // index order is the spoken order.
        $this->flushTranscriptBacklog($agentVoiceCall);
        $this->persistCompletedSentences($agentVoiceCall, $this->liveTranscriptAssembler->closeAllSentences());
        $this->finishApplicationPartOfTheCall($agentVoiceCall);
        $this->bookInitialisationChargeWhenNothingWasBooked($agentVoiceCall);
        $this->addOwedBookedSecondsToTheCallAtTheTeardown($agentVoiceCall);
        $this->persistCallEndIfStillRunning($agentVoiceCall, $endReason);
        $this->bufferTraceFlushedMarker($agentVoiceCall);
    }

    /**
     * Persist the sentences held back while a task ran — with the messages RELOADED first, so the index
     * sequence continues after the rows the turn worker wrote in the meantime.
     */
    protected function flushTranscriptBacklog(AgentVoiceCall $agentVoiceCall): void
    {
        if ($this->transcriptBacklog === []) {
            return;
        }
        $heldBackCount = count($this->transcriptBacklog);
        try {
            $aiConversation = $this->findAttachedAiConversation($agentVoiceCall);
            if (!$aiConversation) {
                // No conversation to write into — the held-back lines can never land; drop them.
                $this->transcriptBacklog = [];

                return;
            }
            /** @var AgentVoiceCallsService|null $agentVoiceCallsService */
            $agentVoiceCallsService = null;
            // An entry leaves the backlog only once its row is written — a failed write keeps it and every
            // entry after it (spoken order) for the next flush, instead of losing them all.
            while ($this->transcriptBacklog !== []) {
                $heldBackTranscript = $this->transcriptBacklog[0];
                if ($heldBackTranscript->isProgress) {
                    // A progress sentence is no chat row: the activity stack already showed the step, and written
                    // after the turn's answer it would read out of order — it goes to the call's trace instead.
                    array_shift($this->transcriptBacklog);
                    $this->bufferProgressSentenceTraceEvent($agentVoiceCall, $heldBackTranscript);
                    continue;
                }
                if ($agentVoiceCallsService === null) {
                    // before the first row: the bounded tail above the compaction cover — the insert
                    // re-anchors its index anyway
                    /** @var AIConversationMessagesService $aIConversationMessagesService */
                    $aIConversationMessagesService = $this->getAIConversationMessagesService();
                    $aiConversation->messages = $aIConversationMessagesService->findContextTailMessages($aiConversation);
                    /** @var AgentVoiceCallsService $agentVoiceCallsService */
                    $agentVoiceCallsService = $this->getAgentVoiceCallsService();
                }
                $transcriptMessage = $agentVoiceCallsService->persistTranscriptMessage(
                    $aiConversation,
                    $heldBackTranscript->role,
                    $heldBackTranscript->text,
                    $agentVoiceCall->getChannelType()
                );
                array_shift($this->transcriptBacklog);
                $this->recordTranscriptProvenance(
                    $agentVoiceCall,
                    $transcriptMessage,
                    $heldBackTranscript->role,
                    $heldBackTranscript->text,
                    $heldBackTranscript->startMs,
                    $heldBackTranscript->endMs,
                    fromBacklog: true
                );
            }
            $this->bufferTraceEvent(
                $agentVoiceCall,
                AIConversationTraceEvent::SOURCE_VOICE_WORKER,
                'transcript.backlog_flushed',
                "{$heldBackCount} held-back sentences written"
            );
        } catch (Throwable $transcriptBacklogError) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} transcript backlog flush failed ("
                . count($this->transcriptBacklog) . ' sentences kept for the next flush): '
                . $transcriptBacklogError->getMessage()
            );
        }
    }

    /** The call's conversation, loaded from the repo ONCE and held for the call's lifetime. */
    protected function findAttachedAiConversation(AgentVoiceCall $agentVoiceCall): ?AIConversation
    {
        if ($this->attachedAiConversation !== null) {
            return $this->attachedAiConversation;
        }
        if ($agentVoiceCall->aiConversationId === null) {
            return null;
        }
        $this->attachedAiConversation = $this->getAIConversationsService()->find($agentVoiceCall->aiConversationId);
        if ($this->attachedAiConversation !== null) {
            // From here on this process acts for the caller's account — see the method's reasoning.
            /** @var AgentCallTasksService $agentCallTasksService */
            $agentCallTasksService = $this->getAgentCallTasksService();
            $agentCallTasksService->ensureAuthAccountForConversation($this->attachedAiConversation);
        }

        return $this->attachedAiConversation;
    }

    /**
     * The closing clause of the mid-call instructions this worker words itself (progress, the bridge line): the call's
     * language named, read fresh ({@see AgentVoiceSessionContextsService::buildCallLanguageClause()}).
     */
    protected function buildKeepTheCallLanguageClause(AgentVoiceCall $agentVoiceCall): string
    {
        return $this->voiceSessionContextsService()->buildCallLanguageClause($agentVoiceCall);
    }

    /** Stamp the call ended — only when it still counts as running — and run the post-call memory curation once. */
    protected function persistCallEndIfStillRunning(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        if (!$agentVoiceCall->isRunning()) {
            return;
        }
        // The call in memory can be stale: the route that hangs up or the clean-up of stale calls may have ended it
        // meanwhile. Read it fresh — a second end stamp would write a second call-ended marker into the chat.
        $freshAgentVoiceCall = $this->getAgentVoiceCallStoreService()->findCall((int)$agentVoiceCall->id);
        if ($freshAgentVoiceCall !== null && $freshAgentVoiceCall->endedAt !== null) {
            $agentVoiceCall->endedAt = $freshAgentVoiceCall->endedAt;
            $agentVoiceCall->endReason = $freshAgentVoiceCall->endReason;

            return;
        }
        $this->getAgentVoiceCallsService()->persistCallEnd($agentVoiceCall, $endReason);

        try {
            $attachedAiConversation = $this->findAttachedAiConversation($agentVoiceCall);
            if ($attachedAiConversation !== null) {
                $this->getAgentMemoriesService()->curateConversationAfterTurn($attachedAiConversation, true);
            }
        } catch (Throwable $memoryCurateDispatchError) {
            $this->appendToVoiceLog(
                "LIVE {$agentVoiceCall->providerCallId} post-call memory-curate dispatch failed: "
                . $memoryCurateDispatchError->getMessage()
            );
        }
    }

    /**
     * One RECEIVED provider event into the trace buffer — everything but audio and the per-word transcript deltas
     * (the sentences carry those). The provider's own `event_id` is the idempotence key when it sends one.
     *
     * @param array<string, mixed> $sessionEvent
     */
    protected function bufferReceivedProviderEvent(AgentVoiceCall $agentVoiceCall, string $sessionEventType, array $sessionEvent): void
    {
        if ($sessionEventType === '' || str_contains($sessionEventType, 'audio') || str_ends_with($sessionEventType, 'transcript.delta')) {
            return;
        }
        $delegationObject = is_array($sessionEvent['delegation'] ?? null) ? $sessionEvent['delegation'] : [];
        $this->bufferTraceEvent(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_PROVIDER,
            $sessionEventType,
            'received ' . $sessionEventType . match ($sessionEventType) {
                'session.closed' => ' (' . (string)($sessionEvent['reason'] ?? '?') . ')',
                'session.usage.updated' => ' (' . (int)(is_array($sessionEvent['usage'] ?? null) ? ($sessionEvent['usage']['seconds'] ?? 0) : 0) . ' s)',
                default => '',
            },
            payload: $this->prepareTracePayload($sessionEventType, $sessionEvent),
            correlationIds: [
                'delegationId' => $delegationObject['id'] ?? $sessionEvent['delegation_id'] ?? null,
                'clientEventId' => $sessionEvent['client_event_id'] ?? null,
            ],
            discriminator: isset($sessionEvent['event_id']) ? 'p' . (string)$sessionEvent['event_id'] : null
        );
    }

    /**
     * The call now has a conversation (an application created it during the call, or the call was re-pointed): the events written or
     * buffered before that moment are linked to it, so the conversation's trace starts with the call's first event.
     */
    protected function linkTraceEventsToConversation(AgentVoiceCall $agentVoiceCall): void
    {
        if ($agentVoiceCall->aiConversationId === null || $agentVoiceCall->id === null) {
            return;
        }
        foreach ($this->traceEventBuffer as $bufferedTraceEvent) {
            $bufferedTraceEvent->aiConversationId ??= (int)$agentVoiceCall->aiConversationId;
        }
        $this->flushTraceEvents();
        try {
            /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
            $aIConversationTraceEventsService = $this->getAIConversationTraceEventsService();
            $aIConversationTraceEventsService->attachCallEventsToConversation((int)$agentVoiceCall->id, (int)$agentVoiceCall->aiConversationId);
        } catch (Throwable) {
            // attachCallEventsToConversation() is fail-open itself; this guards only the service lookup.
        }
    }

    /**
     * Override point: what of an event may enter the trace. Here everything; an application whose medium carries a
     * credential in an event redacts it in its subclass ({@see AIConversationTraceEvent::redactCredentials()}).
     *
     * @param array<string, mixed> $tracePayload
     * @return array<string, mixed>
     */
    protected function prepareTracePayload(string $sessionEventType, array $tracePayload): array
    {
        return $tracePayload;
    }

    /**
     * Queue ONE trace event. Nothing is written here — {@see flushTraceEvents()} writes the buffer as one
     * statement per tick, so the event path pays no database round trip.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string|int|null> $correlationIds
     */
    protected function bufferTraceEvent(
        AgentVoiceCall $agentVoiceCall,
        string $source,
        string $eventType,
        string $summary,
        array $payload = [],
        array $correlationIds = [],
        ?int $durationMs = null,
        ?int $messageIndex = null,
        ?int $providerOffsetMs = null,
        ?string $discriminator = null
    ): void {
        $this->traceEventBuffer[] = AIConversationTraceEvent::create(
            source: $source,
            eventType: $eventType,
            eventKey: 'call:' . $agentVoiceCall->id . ':' . $eventType . ':'
                . ($discriminator ?? ('w' . $this->traceWorkerToken . ':' . (++$this->traceEventSequence))),
            aiConversationId: $agentVoiceCall->aiConversationId !== null ? (int)$agentVoiceCall->aiConversationId : null,
            agentVoiceCallId: $agentVoiceCall->id !== null ? (int)$agentVoiceCall->id : null,
            summary: $summary,
            payload: $payload,
            correlationIds: $correlationIds,
            durationMs: $durationMs,
            messageIndex: $messageIndex,
            providerOffsetMs: $providerOffsetMs
        );
    }

    /** The completeness marker of a cleanly finished call — a trace without it was cut off (a crashed worker). */
    protected function bufferTraceFlushedMarker(AgentVoiceCall $agentVoiceCall): void
    {
        $this->bufferTraceEvent(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED,
            'call trace complete',
            discriminator: $this->traceWorkerToken
        );
    }

    /** Write the buffered trace events as one batched, fail-open insert; the buffer is cleared either way. */
    protected function flushTraceEvents(): void
    {
        if ($this->traceEventBuffer === []) {
            return;
        }
        $bufferedTraceEvents = $this->traceEventBuffer;
        $this->traceEventBuffer = [];
        try {
            /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
            $aIConversationTraceEventsService = $this->getAIConversationTraceEventsService();
            $aIConversationTraceEventsService->recordEvents($bufferedTraceEvents);
        } catch (Throwable) {
            // recordEvents() is fail-open itself; this guards only the service lookup.
        }
    }

    /**
     * Send ONE client event on the sideband with a fresh `event_id` (GPT-Live acknowledges appends by echoing it
     * as `client_event_id`), and log it.
     *
     * @param array<string, mixed> $clientEvent
     */
    protected function sendLiveClientEvent(
        AgentVoiceCall $agentVoiceCall,
        WebSocket $webSocketConnection,
        array $clientEvent
    ): void {
        $clientEvent['event_id'] = 'evt_' . bin2hex(random_bytes(8));
        $clientEventJson = (string)json_encode($clientEvent);
        $this->logLiveSessionTraffic($agentVoiceCall, 'sent', (string)$clientEvent['type'], $clientEventJson);
        $webSocketConnection->send($clientEventJson);
        $this->bufferTraceEvent(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            (string)$clientEvent['type'],
            'sent ' . $clientEvent['type'] . (isset($clientEvent['content']) ? ': ' . (string)$clientEvent['content'] : ''),
            payload: $this->prepareTracePayload((string)$clientEvent['type'], $clientEvent),
            correlationIds: ['delegationId' => $clientEvent['delegation_id'] ?? null],
            discriminator: (string)$clientEvent['event_id']
        );
    }

    /**
     * One log line per sideband event, except the per-chunk noise — and the REFLECTED AUDIO above all: the
     * sideband carries the call's PCM, which must never reach a log file.
     */
    protected function logLiveSessionTraffic(
        AgentVoiceCall $agentVoiceCall,
        string $trafficDirection,
        string $sessionEventType,
        ?string $rawSessionEvent
    ): void {
        if (str_contains($sessionEventType, 'audio') || str_ends_with($sessionEventType, 'transcript.delta')) {
            return;
        }
        $eventTypesLoggedWithFullPayload = [
            'session.started',
            'session.closed',
            'session.usage.updated',
            'session.delegation.created',
            'session.commentary.append',
            'session.thinking.append',
            'session.instructions.append',
            'error',
        ];
        $logDetail = in_array($sessionEventType, $eventTypesLoggedWithFullPayload, true) && $rawSessionEvent !== null
            ? ' ' . mb_substr($rawSessionEvent, 0, 4000)
            : '';
        $this->appendToVoiceLog(
            "LIVE {$agentVoiceCall->providerCallId} {$trafficDirection} {$sessionEventType}{$logDetail}"
        );
    }

    /**
     * Now, in the server's time zone, with REAL microseconds — `date('…u')` always prints `.000000`, which made
     * every sub-second latency in the voice log unmeasurable.
     */
    public static function formatVoiceLogTimestamp(): string
    {
        $microsecondNow = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        if (!$microsecondNow) {
            return date('Y-m-d\TH:i:s');
        }

        return $microsecondNow->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:s.u');
    }

    /** The voice channel's own log file ({@see getVoiceLogFilePath()}). */
    protected function appendToVoiceLog(string $voiceLogLine): void
    {
        try {
            file_put_contents(
                $this->getVoiceLogFilePath(),
                '[' . static::formatVoiceLogTimestamp() . '] ' . $voiceLogLine . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (Throwable) {
            // A failed log write must never affect the call.
        }
    }

    /** Where today's voice log is written: `voice-<date>.log` in the kernel's log directory. */
    protected function getVoiceLogFilePath(): string
    {
        return DDDService::instance()->getKernel()->getLogDir() . '/voice-' . date('Y-m-d') . '.log';
    }

    /**
     * What the agent is called in a log line or a trace summary a person reads: the persona's agent name, else
     * {@see AgentVoiceCall::DEFAULT_AGENT_LABEL}.
     */
    protected function getAgentLabel(): string
    {
        $agentName = AgentVoiceConfiguration::current()->agentName;

        return $agentName !== '' ? $agentName : AgentVoiceCall::DEFAULT_AGENT_LABEL;
    }

    /**
     * Override point: what an application decides ONCE at attach for a medium of its own (a person it has not let
     * through to the agent yet). The window call needs nothing.
     */
    protected function prepareApplicationStateOfAttachedCall(AgentVoiceCall $agentVoiceCall): void
    {
    }

    /**
     * Override point: what an application's medium must send right after `session.started`. The window call sends
     * nothing: its briefing rides the session's creation.
     */
    protected function sendApplicationOpeningAfterSessionStarted(
        AgentVoiceCall $agentVoiceCall,
        WebSocket $webSocketConnection
    ): void {
    }

    /**
     * Override point: true when an application's subclass answered the delegation itself (a person it has not let
     * through to the agent yet). Here every delegation goes to the agent.
     *
     * @param array<string, mixed> $sessionEvent
     */
    protected function answerDelegationWithoutTheAgent(
        AgentVoiceCall $agentVoiceCall,
        WebSocket $webSocketConnection,
        array $sessionEvent
    ): bool {
        return false;
    }

    /**
     * Override point: a sideband event this worker does not know (an event only an application's medium sends).
     *
     * @param array<string, mixed> $sessionEvent
     */
    protected function applyApplicationSessionEvent(
        AgentVoiceCall $agentVoiceCall,
        WebSocket $webSocketConnection,
        string $sessionEventType,
        array $sessionEvent
    ): void {
    }

    /**
     * Override point: true when an application's subclass took the completed sentence and it must NOT become a
     * conversation row (said before the application let the person through). Here every sentence is written.
     */
    protected function keepCompletedSentenceOutOfTheConversation(
        AgentVoiceCall $agentVoiceCall,
        LiveTranscriptSentence $completedSentence
    ): bool {
        return false;
    }

    /** Override point: true while an application's medium runs idle rules of its own and the silence guard must wait. */
    protected function isSpeechSilenceGuardSuspendedByTheApplication(): bool
    {
        return false;
    }

    /**
     * Override point: an application's own step of the teardown, after the transcript is written and before the
     * call's end is stored.
     */
    protected function finishApplicationPartOfTheCall(AgentVoiceCall $agentVoiceCall): void
    {
    }

    /** The voice session contexts (the briefing and the call language), resolved from the container. */
    protected function voiceSessionContextsService(): AgentVoiceSessionContextsService
    {
        /** @var AgentVoiceSessionContextsService $agentVoiceSessionContextsService */
        $agentVoiceSessionContextsService = DDDService::instance()->getService(AgentVoiceSessionContextsService::class);

        return $agentVoiceSessionContextsService;
    }

    /** The container's {@see AgentVoiceCallsService}; a test replaces it here. */
    protected function getAgentVoiceCallsService(): AgentVoiceCallsService
    {
        /** @var AgentVoiceCallsService $agentVoiceCallsService */
        $agentVoiceCallsService = DDDService::instance()->getService(AgentVoiceCallsService::class);

        return $agentVoiceCallsService;
    }

    /** The container's {@see AgentVoiceCallStoreService}; a test replaces it here. */
    protected function getAgentVoiceCallStoreService(): AgentVoiceCallStoreService
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);

        return $agentVoiceCallStoreService;
    }

    /** The container's {@see AgentVoiceCallAdmissionService}; a test replaces it here. */
    protected function getAgentVoiceCallAdmissionService(): AgentVoiceCallAdmissionService
    {
        /** @var AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService */
        $agentVoiceCallAdmissionService = DDDService::instance()->getService(AgentVoiceCallAdmissionService::class);

        return $agentVoiceCallAdmissionService;
    }

    /** The container's {@see AgentCallTasksService}; a test replaces it here. */
    protected function getAgentCallTasksService(): AgentCallTasksService
    {
        /** @var AgentCallTasksService $agentCallTasksService */
        $agentCallTasksService = DDDService::instance()->getService(AgentCallTasksService::class);

        return $agentCallTasksService;
    }

    /** The container's {@see AIConversationMessagesService}; a test replaces it here. */
    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);

        return $aIConversationMessagesService;
    }

    /** The container's {@see AIConversationTraceEventsService}; a test replaces it here. */
    protected function getAIConversationTraceEventsService(): AIConversationTraceEventsService
    {
        /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
        $aIConversationTraceEventsService = DDDService::instance()->getService(AIConversationTraceEventsService::class);

        return $aIConversationTraceEventsService;
    }

    /** The container's {@see AgentActivityLinesService}; a test replaces it here. */
    protected function getAgentActivityLinesService(): AgentActivityLinesService
    {
        /** @var AgentActivityLinesService $agentActivityLinesService */
        $agentActivityLinesService = DDDService::instance()->getService(AgentActivityLinesService::class);

        return $agentActivityLinesService;
    }

    /** The container's {@see AgentMemoriesService}; a test replaces it here. */
    protected function getAgentMemoriesService(): AgentMemoriesService
    {
        /** @var AgentMemoriesService $agentMemoriesService */
        $agentMemoriesService = DDDService::instance()->getService(AgentMemoriesService::class);

        return $agentMemoriesService;
    }

    /** The container's {@see AISessionUsageService}; a test replaces it here. */
    protected function getAISessionUsageService(): AISessionUsageService
    {
        /** @var AISessionUsageService $aISessionUsageService */
        $aISessionUsageService = DDDService::instance()->getService(AISessionUsageService::class);

        return $aISessionUsageService;
    }

    /** The container's {@see AIUsageEnvelopesService}; a test replaces it here. */
    protected function getAIUsageEnvelopesService(): AIUsageEnvelopesService
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);

        return $aIUsageEnvelopesService;
    }

    /**
     * The adapter of the provider the call runs on, through the seam ({@see RealtimeVoiceProviderAdapter::forCall()}:
     * the registry of `AI.agentVoice.provider.adapters`, by the name pinned on the call); a test replaces it here.
     */
    protected function getVoiceProviderAdapterOfCall(AgentVoiceCall $agentVoiceCall): RealtimeVoiceProviderAdapter
    {
        return RealtimeVoiceProviderAdapter::forCall($agentVoiceCall);
    }

    /** The container's {@see AIConversationsService}; a test replaces it here. */
    protected function getAIConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);

        return $aIConversationsService;
    }
}
