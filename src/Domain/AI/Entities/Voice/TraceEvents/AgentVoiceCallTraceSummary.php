<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationTraceAnomalies;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationTraceAnomaly;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The call header of ONE voice call in the trace view of its conversation: identity, end, and the latency numbers
 * that answer "why did the agent take so long to speak?", computed on read from the call's trace events. Every number
 * is null when its events are missing (a client that does not report its start-up timeline): the view shows "not
 * recorded", never a guessed number.
 *
 * The event types read here are constants read through `static::`, so an application whose stored events carry an
 * earlier spelling keeps it in a subclass at the same path. Two further override points for an application with
 * another provider family: {@see findFirstAgentWordAtUnixMs()} (what counts as the agent's first audio) and
 * {@see findTurnGapsWithoutSpokenSpans()} (the speech rhythm of a session that reports no spoken spans);
 * {@see delegationKeyOf()} names the id that ties the events of one task together.
 */
class AgentVoiceCallTraceSummary extends ValueObject
{
    /** The ring of a call the agent started was written */
    public const string EVENT_TYPE_RING_STARTED = 'call.ring_started';

    /** A client won the answer claim of a ringing call */
    public const string EVENT_TYPE_ANSWER_CLAIMED = 'call.answer_claimed';

    /** The `open` request of the call was answered */
    public const string EVENT_TYPE_OPEN_ANSWERED = 'call.open_answered';

    /** The briefing of the session was composed */
    public const string EVENT_TYPE_BRIEFING_COMPOSED = 'briefing.composed';

    /** The provider created the session from the offer */
    public const string EVENT_TYPE_PROVIDER_SESSION_CREATED = 'provider.session_created';

    /** The session worker attached to the session */
    public const string EVENT_TYPE_WORKER_ATTACHED = 'worker.attached';

    /** The agent's first words arrived on the transcript */
    public const string EVENT_TYPE_FIRST_AGENT_TRANSCRIPT = 'agent.first_transcript';

    /** The client applied the provider's answer */
    public const string EVENT_TYPE_DEVICE_ANSWER_APPLIED = 'device.answer_applied';

    /** The client played the first audio of the call */
    public const string EVENT_TYPE_DEVICE_FIRST_AUDIO_PLAYED = 'device.first_audio_played';

    /** The owner tapped the incoming call on the client */
    public const string EVENT_TYPE_DEVICE_CALL_TAPPED = 'device.call_tapped';

    /** @var int|null */
    public ?int $agentVoiceCallId = null;

    /** @var string|null `INBOUND` (the owner calls the agent) or `OUTBOUND` (the agent calls). */
    public ?string $direction = null;

    /** @var string|null The medium of the call ({@see AgentVoiceCall::getChannelType()}). */
    public ?string $channelType = null;

    /** @var string|null The pinned voice provider of the call. */
    public ?string $voiceProvider = null;

    /** @var string|null */
    public ?string $endReason = null;

    /** @var int|null Seconds from start to end, when both are stamped. */
    public ?int $durationSeconds = null;

    /** @var int|null Ring start → answer claimed (the agent calls), ms. */
    public ?int $ringToAnswerMs = null;

    /** @var int|null The `answer` request itself, ms. */
    public ?int $answerRequestMs = null;

    /** @var int|null The `open` request end to end, ms. */
    public ?int $openRequestMs = null;

    /** @var int|null Of `open`: composing the session briefing, ms. */
    public ?int $briefingMs = null;

    /** @var int|null Of `open`: the provider creating the session from the offer, ms. */
    public ?int $providerSessionMs = null;

    /** @var int|null `open` answered → the session worker attached, ms. */
    public ?int $workerAttachAfterOpenMs = null;

    /**
     * @var int|null The call connected → the agent's first audio, ms (server clock). "Connected" is the answered
     * `open`, or the session worker's attach where no `open` was traced; "first audio" is
     * {@see findFirstAgentWordAtUnixMs()}.
     */
    public ?int $firstAgentWordAfterOpenMs = null;

    /** @var int|null Client: answer applied → first audio played (the client's clock), ms. */
    public ?int $deviceAnswerAppliedToFirstAudioMs = null;

    /** @var int|null Client: call tapped → first audio played (the client's clock), ms. */
    public ?int $deviceTapToFirstAudioMs = null;

    /** @var int|null Median gap between the owner finishing a sentence and the agent starting the next, ms (session clock). */
    public ?int $medianTurnGapMs = null;

    /** @var int[] Each delegation's round trip: task started → answer handed to the voice model, ms. */
    public array $delegationRoundTripsMs = [];

    /** @var bool Whether the call trace carries the completeness marker of a clean final flush. */
    public bool $traceComplete = false;

    /** @var int The number of trace events of this call. */
    public int $traceEventCount = 0;

    /** @var AIConversationTraceAnomalies|null What the read found wrong on this call. */
    public ?AIConversationTraceAnomalies $anomalies = null;

    /**
     * Builds the header of one call from its trace events (time order) and the conversation's turn-end events.
     *
     * @param AgentVoiceCall $agentVoiceCall
     * @param AIConversationTraceEvent[] $callTraceEvents
     * @param AIConversationTurnEvent[] $turnEndEvents
     */
    public static function fromTraceEvents(AgentVoiceCall $agentVoiceCall, array $callTraceEvents, array $turnEndEvents = []): static
    {
        $callSummary = new static();
        $callSummary->agentVoiceCallId = $agentVoiceCall->id !== null ? (int)$agentVoiceCall->id : null;
        $callSummary->direction = $agentVoiceCall->direction ?? null;
        $callSummary->channelType = $agentVoiceCall->getChannelType();
        $callSummary->voiceProvider = $agentVoiceCall->voiceProvider ?? null;
        $callSummary->endReason = $agentVoiceCall->endReason ?? null;
        if (isset($agentVoiceCall->startedAt, $agentVoiceCall->endedAt)) {
            $callSummary->durationSeconds = max(0, $agentVoiceCall->endedAt->getTimestamp() - $agentVoiceCall->startedAt->getTimestamp());
        }
        $callSummary->traceEventCount = count($callTraceEvents);
        $callSummary->anomalies = new AIConversationTraceAnomalies();

        $firstEventOfType = [];
        foreach ($callTraceEvents as $traceEvent) {
            $firstEventOfType[$traceEvent->eventType] ??= $traceEvent;
        }
        $openAnsweredAtUnixMs = static::findOccurredAtUnixMs($firstEventOfType, static::EVENT_TYPE_OPEN_ANSWERED);
        $workerAttachedAtUnixMs = static::findOccurredAtUnixMs($firstEventOfType, static::EVENT_TYPE_WORKER_ATTACHED);
        $deviceFirstAudioPlayedAtUnixMs = static::findOccurredAtUnixMs($firstEventOfType, static::EVENT_TYPE_DEVICE_FIRST_AUDIO_PLAYED);

        $callSummary->ringToAnswerMs = static::getMillisecondsBetween(
            static::findOccurredAtUnixMs($firstEventOfType, static::EVENT_TYPE_RING_STARTED),
            static::findOccurredAtUnixMs($firstEventOfType, static::EVENT_TYPE_ANSWER_CLAIMED)
        );
        $callSummary->answerRequestMs = static::findDurationMs($firstEventOfType, static::EVENT_TYPE_ANSWER_CLAIMED);
        $callSummary->openRequestMs = static::findDurationMs($firstEventOfType, static::EVENT_TYPE_OPEN_ANSWERED);
        $callSummary->briefingMs = static::findDurationMs($firstEventOfType, static::EVENT_TYPE_BRIEFING_COMPOSED);
        $callSummary->providerSessionMs = static::findDurationMs($firstEventOfType, static::EVENT_TYPE_PROVIDER_SESSION_CREATED);
        $callSummary->workerAttachAfterOpenMs = static::getMillisecondsBetween($openAnsweredAtUnixMs, $workerAttachedAtUnixMs);
        $callSummary->firstAgentWordAfterOpenMs = static::getMillisecondsBetween(
            $openAnsweredAtUnixMs ?? $workerAttachedAtUnixMs,
            static::findFirstAgentWordAtUnixMs($firstEventOfType)
        );
        $callSummary->deviceAnswerAppliedToFirstAudioMs = static::getMillisecondsBetween(
            static::findOccurredAtUnixMs($firstEventOfType, static::EVENT_TYPE_DEVICE_ANSWER_APPLIED),
            $deviceFirstAudioPlayedAtUnixMs
        );
        $callSummary->deviceTapToFirstAudioMs = static::getMillisecondsBetween(
            static::findOccurredAtUnixMs($firstEventOfType, static::EVENT_TYPE_DEVICE_CALL_TAPPED),
            $deviceFirstAudioPlayedAtUnixMs
        );
        $callSummary->traceComplete = isset($firstEventOfType[AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED]);

        $callSummary->summarizeSpeechAndDelegations($callTraceEvents, $turnEndEvents);
        if (!$callSummary->traceComplete && isset($agentVoiceCall->endedAt) && isset($firstEventOfType[static::EVENT_TYPE_WORKER_ATTACHED])) {
            $callSummary->addAnomaly(
                AIConversationTraceAnomaly::CODE_TRACE_INCOMPLETE,
                null,
                'the call ended but its worker never wrote the final trace flush: events after the last tick may be missing'
            );
        }

        return $callSummary;
    }

    /**
     * When the first event of `$eventType` occurred, in Unix milliseconds; null when the call has no such event.
     *
     * @param array<string, AIConversationTraceEvent> $firstEventOfType
     */
    protected static function findOccurredAtUnixMs(array $firstEventOfType, string $eventType): ?int
    {
        return isset($firstEventOfType[$eventType]) ? (int)$firstEventOfType[$eventType]->occurredAtUnixMs : null;
    }

    /**
     * The duration the first event of `$eventType` carries, in milliseconds; null when the call has no such event or
     * it carries none.
     *
     * @param array<string, AIConversationTraceEvent> $firstEventOfType
     */
    protected static function findDurationMs(array $firstEventOfType, string $eventType): ?int
    {
        return isset($firstEventOfType[$eventType]) ? ($firstEventOfType[$eventType]->durationMs ?? null) : null;
    }

    /** The milliseconds from `$fromUnixMs` until `$untilUnixMs`; null when either is missing or they are out of order. */
    protected static function getMillisecondsBetween(?int $fromUnixMs, ?int $untilUnixMs): ?int
    {
        return $fromUnixMs !== null && $untilUnixMs !== null && $untilUnixMs >= $fromUnixMs
            ? $untilUnixMs - $fromUnixMs
            : null;
    }

    /**
     * When the agent's first audio of the call began: the first words on the output transcript. A subclass with a
     * provider that reports the start of its audio in another event reads that event here too.
     *
     * @param array<string, AIConversationTraceEvent> $firstEventOfType
     */
    protected static function findFirstAgentWordAtUnixMs(array $firstEventOfType): ?int
    {
        return static::findOccurredAtUnixMs($firstEventOfType, static::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT);
    }

    /** Records one anomaly of this call. */
    protected function addAnomaly(string $code, ?int $messageIndex, string $detail): void
    {
        $traceAnomaly = AIConversationTraceAnomaly::create($code, $this->agentVoiceCallId, $messageIndex, $detail);
        $this->anomalies->add($traceAnomaly);
    }

    /**
     * The speech rhythm (median turn gap), the delegation round trips and the anomaly flags, from the events of the
     * persisted sentences, of the backlog and of the tasks. Every payload is decoded once.
     *
     * @param AIConversationTraceEvent[] $callTraceEvents
     * @param AIConversationTurnEvent[] $turnEndEvents
     */
    protected function summarizeSpeechAndDelegations(array $callTraceEvents, array $turnEndEvents): void
    {
        $spokenSentences = [];
        $taskStartedEvents = [];
        $backlogPushes = [];
        $persistedRows = [];
        $matchedTaskStartKeys = [];
        foreach ($callTraceEvents as $traceEvent) {
            $tracePayload = $traceEvent->getPayloadArray();
            switch ($traceEvent->eventType) {
                case AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED:
                    $persistedRows[] = ['event' => $traceEvent, 'payload' => $tracePayload];
                    if (isset($tracePayload['spokenFromMs'], $tracePayload['spokenUntilMs'])) {
                        $spokenSentences[] = AgentVoiceCallTraceSpokenSentence::create(
                            (string)($tracePayload['role'] ?? ''),
                            (int)$tracePayload['spokenFromMs'],
                            (int)$tracePayload['spokenUntilMs'],
                            (int)$traceEvent->occurredAtUnixMs
                        );
                    }
                    if (($tracePayload['role'] ?? null) === AIConversationMessage::ROLE_USER && (int)($tracePayload['chars'] ?? 99) < 3) {
                        $this->addAnomaly(
                            AIConversationTraceAnomaly::CODE_NOISE_ROW,
                            $traceEvent->messageIndex,
                            'a caller row of ' . (int)($tracePayload['chars'] ?? 0) . ' characters, most likely transcription noise'
                        );
                    }
                    break;
                case AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_BACKLOG_PUSHED:
                    if (($tracePayload['role'] ?? null) === AIConversationMessage::ROLE_ASSISTANT) {
                        $backlogPushes[] = AgentVoiceCallTraceBacklogPush::create(
                            (int)$traceEvent->occurredAtUnixMs,
                            isset($tracePayload['startMs']) ? (int)$tracePayload['startMs'] : null,
                            isset($tracePayload['endMs']) ? (int)$tracePayload['endMs'] : null
                        );
                    }
                    break;
                case AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED:
                    $taskStartedEvents[] = ['event' => $traceEvent, 'delegationKey' => static::delegationKeyOf($tracePayload)];
                    break;
                case AIConversationTraceEvent::EVENT_TYPE_TASK_OUTCOME:
                    $outcomeDelegationKey = static::delegationKeyOf($tracePayload);
                    foreach (array_reverse($taskStartedEvents, true) as $taskStartNumber => $taskStarted) {
                        if (isset($matchedTaskStartKeys[$taskStartNumber])) {
                            continue;
                        }
                        if ($outcomeDelegationKey === null || $taskStarted['delegationKey'] === $outcomeDelegationKey) {
                            $matchedTaskStartKeys[$taskStartNumber] = true;
                            $this->delegationRoundTripsMs[] = (int)$traceEvent->occurredAtUnixMs - (int)$taskStarted['event']->occurredAtUnixMs;
                            break;
                        }
                    }
                    if (($tracePayload['widgetInCommentary'] ?? false) === true) {
                        $this->addAnomaly(
                            AIConversationTraceAnomaly::CODE_WIDGET_IN_COMMENTARY,
                            $traceEvent->messageIndex,
                            'the answer handed to the voice model carried a widget block'
                        );
                    }
                    if (isset($tracePayload['answerChars'], $tracePayload['commentaryChars'])
                        && (int)$tracePayload['answerChars'] > (int)$tracePayload['commentaryChars']
                    ) {
                        $this->addAnomaly(
                            AIConversationTraceAnomaly::CODE_ANSWER_CUT,
                            $traceEvent->messageIndex,
                            'the agent answered ' . (int)$tracePayload['answerChars'] . ' characters, the voice model got '
                                . (int)$tracePayload['commentaryChars']
                        );
                    }
                    break;
            }
        }

        // The rhythm: the owner finishes → the agent starts, on the provider's session clock (one clock, no skew).
        usort(
            $spokenSentences,
            static fn (AgentVoiceCallTraceSpokenSentence $left, AgentVoiceCallTraceSpokenSentence $right): int => $left->fromMs <=> $right->fromMs
        );
        $turnGapsMs = [];
        for ($sentenceNumber = 1; $sentenceNumber < count($spokenSentences); $sentenceNumber++) {
            $previousSentence = $spokenSentences[$sentenceNumber - 1];
            $currentSentence = $spokenSentences[$sentenceNumber];
            if ($previousSentence->role === AIConversationMessage::ROLE_USER
                && $currentSentence->role === AIConversationMessage::ROLE_ASSISTANT
            ) {
                $turnGapsMs[] = max(0, $currentSentence->fromMs - $previousSentence->untilMs);
            }
        }
        if ($turnGapsMs === []) {
            $turnGapsMs = $this->findTurnGapsWithoutSpokenSpans($callTraceEvents);
        }
        if ($turnGapsMs !== []) {
            sort($turnGapsMs);
            $middle = intdiv(count($turnGapsMs), 2);
            $this->medianTurnGapMs = count($turnGapsMs) % 2 === 1
                ? $turnGapsMs[$middle]
                : intdiv($turnGapsMs[$middle - 1] + $turnGapsMs[$middle], 2);
        }

        foreach ($taskStartedEvents as $taskStarted) {
            $this->flagReadBackAndPickup($taskStarted['event'], $spokenSentences, $backlogPushes, $turnEndEvents);
        }

        // Row order: a row written from the BACKLOG whose speech started before the row written just above it.
        usort(
            $persistedRows,
            static fn (array $left, array $right): int => ($left['event']->messageIndex ?? 0) <=> ($right['event']->messageIndex ?? 0)
        );
        $previousSpokenFromMs = null;
        foreach ($persistedRows as $persistedRow) {
            $spokenFromMs = $persistedRow['payload']['spokenFromMs'] ?? null;
            if ($spokenFromMs === null) {
                continue;
            }
            if (($persistedRow['payload']['fromBacklog'] ?? false) === true
                && $previousSpokenFromMs !== null && (int)$spokenFromMs < $previousSpokenFromMs
            ) {
                $this->addAnomaly(
                    AIConversationTraceAnomaly::CODE_ROW_ORDER_INVERTED,
                    $persistedRow['event']->messageIndex,
                    'this row came from the backlog and was spoken before the row above it'
                );
            }
            $previousSpokenFromMs = max($previousSpokenFromMs ?? 0, (int)$spokenFromMs);
        }
    }

    /**
     * The turn gaps of a call whose persisted sentences carry no spoken spans, in milliseconds. The base has none: its
     * provider reports the spans. A subclass with a provider that does not reads the gaps from that provider's own
     * events here.
     *
     * @param AIConversationTraceEvent[] $callTraceEvents
     * @return int[]
     */
    protected function findTurnGapsWithoutSpokenSpans(array $callTraceEvents): array
    {
        return [];
    }

    /**
     * The read-back and pickup flags of ONE delegation: did the agent say a sentence between the owner's last words
     * and the task start; was a read-back that STARTED before the task held in the backlog (its start on the wall
     * clock = when it was pushed minus its spoken length); did the turn worker pick the turn up late?
     *
     * @param AgentVoiceCallTraceSpokenSentence[] $spokenSentences
     * @param AgentVoiceCallTraceBacklogPush[] $backlogPushes the agent's sentences held back in the backlog
     * @param AIConversationTurnEvent[] $turnEndEvents
     */
    protected function flagReadBackAndPickup(
        AIConversationTraceEvent $taskStartedEvent,
        array $spokenSentences,
        array $backlogPushes,
        array $turnEndEvents
    ): void {
        $taskStartedAtUnixMs = (int)$taskStartedEvent->occurredAtUnixMs;
        $lastCallerSentenceAt = null;
        $agentSpokeAfterCaller = false;
        foreach ($spokenSentences as $spokenSentence) {
            if ($spokenSentence->occurredAtUnixMs > $taskStartedAtUnixMs) {
                continue;
            }
            if ($spokenSentence->role === AIConversationMessage::ROLE_USER) {
                $lastCallerSentenceAt = $spokenSentence->occurredAtUnixMs;
                $agentSpokeAfterCaller = false;
            } elseif ($lastCallerSentenceAt !== null) {
                $agentSpokeAfterCaller = true;
            }
        }
        $readBackHeldInBacklog = false;
        foreach ($backlogPushes as $backlogPush) {
            $pushedAtUnixMs = $backlogPush->pushedAtUnixMs;
            $spokenLengthMs = $backlogPush->getSpokenLengthMs();
            if ($spokenLengthMs !== null && $pushedAtUnixMs >= $taskStartedAtUnixMs
                && $pushedAtUnixMs - $spokenLengthMs < $taskStartedAtUnixMs
            ) {
                $readBackHeldInBacklog = true;
                break;
            }
        }
        if ($readBackHeldInBacklog) {
            $this->addAnomaly(
                AIConversationTraceAnomaly::CODE_READ_BACK_IN_BACKLOG,
                $taskStartedEvent->messageIndex,
                'the read-back of ' . static::getAgentLabel() . ' began before the delegation and was held in the backlog while the turn ran'
            );
        } elseif ($lastCallerSentenceAt !== null && !$agentSpokeAfterCaller) {
            $this->addAnomaly(
                AIConversationTraceAnomaly::CODE_NO_READ_BACK,
                $taskStartedEvent->messageIndex,
                'the delegation started without ' . static::getAgentLabel() . ' reading the request back'
            );
        }
        foreach ($turnEndEvents as $turnEndEvent) {
            if (($turnEndEvent->initiatorMessageIndex ?? null) !== $taskStartedEvent->messageIndex) {
                continue;
            }
            $sendToJobMs = $turnEndEvent->getPayloadArray()['turnStartMs']['sendToJob'] ?? null;
            if ($sendToJobMs !== null && (int)$sendToJobMs > 1000) {
                $this->addAnomaly(
                    AIConversationTraceAnomaly::CODE_SLOW_PICKUP,
                    $taskStartedEvent->messageIndex,
                    'the turn worker picked the turn up ' . (int)$sendToJobMs . ' ms after its dispatch'
                );
            }
        }
    }

    /**
     * The id that ties a task's events together: the delegation id of the session. A subclass with a provider that
     * names a task by another id reads that id here too.
     *
     * @param array<string, mixed> $tracePayload
     */
    protected static function delegationKeyOf(array $tracePayload): ?string
    {
        $correlationIds = is_array($tracePayload['correlationIds'] ?? null) ? $tracePayload['correlationIds'] : [];
        $delegationKey = $correlationIds['delegationId'] ?? null;

        return is_scalar($delegationKey) ? (string)$delegationKey : null;
    }

    /** What the agent is called in an anomaly text: the persona's agent name, else {@see AgentVoiceCall::DEFAULT_AGENT_LABEL}. */
    protected static function getAgentLabel(): string
    {
        $agentName = AgentVoiceConfiguration::current()->agentName;

        return $agentName !== '' ? $agentName : AgentVoiceCall::DEFAULT_AGENT_LABEL;
    }
}
