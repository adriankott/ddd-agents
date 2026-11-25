<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentLoopStopReason;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\AI\Exceptions\AIModelCallFailedException;
use DDD\Domain\AI\Exceptions\AIModelCallTransportFailedException;
use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationResultsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationToolRunService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Domain\AI\Services\Conversations\Agent\SupervisorCheckpointRunsService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationSettledOwnerTurnListenersService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * The release of a worker turn: one row per release branch of
 * {@see AIConversationAgentService::resumeConversationIfReady()} and per variant of its condition, with the attempt
 * count on both sides of {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRunRelease::MAX_RESUME_ATTEMPTS}
 * (2 → 3 is the last count under the cap, 3 → 4 the first over it).
 *
 * A row is the ORDERED list of what the resume did, as the stage's doubles recorded it: the eligibility read at the head,
 * the drive, the turn-end event, the token-guarded release (`release <state> attempts=<n|unchanged> owedAt=<none|now>`),
 * the schedule's run-terminal record, the waiting-signal probe (and where it sits against the realtime status), the
 * realtime status, the async re-fire, the promotion of queued children, the follow-ups of an answered turn, the stall
 * give-up reply with its report stamps, the rows the release path writes, and the run end of a delegation child — plus
 * the class the resume rethrows to its handler.
 *
 * The REAL resume runs over {@see RunReleaseAgentServiceDouble} (only the drive and the async re-fire replaced); every
 * service it reaches through the container is a double of this folder, installed once per test. No database.
 */
class AgentRunReleaseOfAWorkerTurnTest extends IntegrationTestCase
{
    protected const int ROOT_CONVERSATION_ID = 5101;

    protected const int CHILD_CONVERSATION_ID = 5102;

    /** The root of a tree the driven conversation sits in without being a delegation child (no delegate call anchors it). */
    protected const int TREE_ROOT_CONVERSATION_ID = 5100;

    protected const int DELEGATE_CALL_MESSAGE_ID = 6101;

    protected const int BATCH_ID = 7101;

    /** The first line of every row whose scope is eligible: the cached eligibility read right after the claim. */
    protected const string HEAD = 'eligibility ELIGIBLE cached';

    protected RunReleaseEventLog $eventLog;

    protected RunReleaseAgentServiceDouble $agentService;

    protected RunReleaseMessagesServiceDouble $messagesService;

    protected RunReleaseRunClaimsServiceDouble $runClaimsService;

    protected RunReleaseConversationsServiceDouble $conversationsService;

    protected RunReleaseDelegationBatchesServiceDouble $delegationBatchesService;

    protected RunReleaseTurnSignalsServiceDouble $turnSignalsService;

    protected RunReleaseDelegationResultsServiceDouble $delegationResultsService;

    protected RunReleaseDelegationToolRunServiceDouble $delegationToolRunService;

    protected RunReleaseSupervisorCheckpointRunsServiceDouble $supervisorCheckpointRunsService;

    protected RunReleaseMemoriesServiceDouble $memoriesService;

    protected RunReleasePurposeRunsServiceDouble $purposeRunsService;

    protected RunReleaseSettledOwnerTurnListenersServiceDouble $settledOwnerTurnListenersService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventLog = new RunReleaseEventLog();
        $this->messagesService = new RunReleaseMessagesServiceDouble($this->eventLog);
        $this->agentService = new RunReleaseAgentServiceDouble($this->eventLog, $this->messagesService);
        $this->runClaimsService = new RunReleaseRunClaimsServiceDouble($this->eventLog);
        $this->conversationsService = new RunReleaseConversationsServiceDouble($this->eventLog);
        $this->delegationBatchesService = new RunReleaseDelegationBatchesServiceDouble($this->eventLog);
        $this->turnSignalsService = new RunReleaseTurnSignalsServiceDouble($this->eventLog);
        $this->delegationResultsService = new RunReleaseDelegationResultsServiceDouble($this->eventLog);
        $this->delegationResultsService->rootConversationIdWithFreedSlot = self::ROOT_CONVERSATION_ID;
        $this->delegationToolRunService = new RunReleaseDelegationToolRunServiceDouble($this->eventLog);
        $this->supervisorCheckpointRunsService = new RunReleaseSupervisorCheckpointRunsServiceDouble($this->eventLog);
        $this->memoriesService = new RunReleaseMemoriesServiceDouble($this->eventLog);
        $this->purposeRunsService = new RunReleasePurposeRunsServiceDouble($this->eventLog);
        $this->settledOwnerTurnListenersService = new RunReleaseSettledOwnerTurnListenersServiceDouble($this->eventLog);
        $this->replaceService(AIConversationAgentService::class, $this->agentService);
        $this->replaceService(AIConversationMessagesService::class, $this->messagesService);
        $this->replaceService(AIConversationRunClaimsService::class, $this->runClaimsService);
        $this->replaceService(AIConversationsService::class, $this->conversationsService);
        $this->replaceService(ConversationCompactionNodesService::class, new RunReleaseCompactionNodesServiceDouble());
        $this->replaceService(AIConversationDelegationBatchesService::class, $this->delegationBatchesService);
        $this->replaceService(AIConversationTurnSignalsService::class, $this->turnSignalsService);
        $this->replaceService(AIConversationRealtimeSignalService::class, new RunReleaseRealtimeSignalServiceDouble($this->eventLog));
        $this->replaceService(AIConversationSchedulesService::class, new RunReleaseSchedulesServiceDouble($this->eventLog));
        $this->replaceService(AIConversationDelegationSpawnService::class, new RunReleaseDelegationSpawnServiceDouble($this->eventLog));
        $this->replaceService(AIConversationDelegationResultsService::class, $this->delegationResultsService);
        $this->replaceService(AIConversationDelegationNotificationsService::class, new RunReleaseDelegationNotificationsServiceDouble());
        $this->replaceService(AIConversationDelegationToolRunService::class, $this->delegationToolRunService);
        $this->replaceService(SupervisorCheckpointRunsService::class, $this->supervisorCheckpointRunsService);
        $this->replaceService(AIConversationTurnEventsService::class, new RunReleaseTurnEventsServiceDouble($this->eventLog));
        $this->replaceService(AIConversationOwnerReportDeliveryService::class, new RunReleaseOwnerReportDeliveryServiceDouble($this->eventLog));
        $this->replaceService(AgentMemoriesService::class, $this->memoriesService);
        $this->replaceService(AIConversationPurposeRunsService::class, $this->purposeRunsService);
        $this->replaceService(AIConversationSettledOwnerTurnListenersService::class, $this->settledOwnerTurnListenersService);
        // an entity set memoizes its service per class for the process: emptied so the set of compaction nodes asks the container
        StaticRegistry::$entityServices = [];
    }

    /**
     * The scenario defaults: a root conversation with one unanswered owner message, no attempts, no batch, no purpose,
     * an eligible scope.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected static function scenario(array $overrides): array
    {
        return array_replace([
            'delegationChild' => false,
            'insideATree' => false,
            'delegationBatchId' => null,
            'purposeName' => null,
            'resumeAttempts' => 0,
            'initialRows' => [RunReleaseScriptedRows::OWNER_MESSAGE],
            'turnRows' => [],
            'turnFailure' => null,
            'loopStopReason' => null,
            'eligibilityStates' => [],
            'rowsPersistedByOtherWritersDuringTheTurn' => [],
            'preDriveFailure' => false,
            'childRunEndFails' => false,
            'childYields' => false,
            'unreadBatchIdAnswers' => [],
            'unfinishedBatchAnswers' => [],
            'signalWaiting' => false,
            'waitingSignalsMarkedRead' => true,
            'readMarkFailure' => null,
            'claimHeldByAnotherWorker' => false,
            'curationFails' => false,
            'checkpointDispatchFails' => false,
            'interventionReFireFails' => false,
            'interventionWaits' => false,
            'purposeGoalCloseFails' => false,
            'settledListenersFail' => false,
            'checkpointDispatched' => false,
            'supervisedConversationFound' => false,
            'supervisedConversationLoadFails' => false,
            'beatStillOwed' => false,
            'beatStillOwedReadFails' => false,
            'toolRunTerminalRunState' => AIConversationRunState::COMPLETED,
        ], $overrides);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string[], 2: class-string<Throwable>|null}>
     *     scenario, the ordered events, the class the resume rethrows
     */
    public static function provideReleaseRows(): array
    {
        $head = self::HEAD;
        $owner = RunReleaseScriptedRows::OWNER_MESSAGE;
        $assistant = RunReleaseScriptedRows::ASSISTANT_TEXT;
        $nudge = RunReleaseScriptedRows::LOOP_NUDGE;
        $continuation = RunReleaseScriptedRows::TURN_CONTINUATION;
        $workSignal = RunReleaseScriptedRows::WORK_SIGNAL;
        $repeatedTextSuppressed = RunReleaseScriptedRows::REPEATED_TEXT_SUPPRESSED;
        $unreadOrchestratorMessage = RunReleaseScriptedRows::UNREAD_ORCHESTRATOR_MESSAGE;
        $childInBatch = ['delegationChild' => true, 'delegationBatchId' => self::BATCH_ID];
        $overCap = ['resumeAttempts' => 3];
        $atTheCap = ['resumeAttempts' => 2];
        // a turn that reads unread answers and whose tail owes nothing else: a turn that appends only loop steering
        // leaves no owed tail and reaches the replay step
        $foldTurnWithoutOwedTail = ['initialRows' => [$owner, $assistant], 'unreadBatchIdAnswers' => [self::BATCH_ID]];
        $childRunEndWritten = ['promoteQueuedDelegations root=5101', 'notifyParentAfterRunEnd'];
        $answeredHead = [$head, 'drive', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'probe signalWaitingAbove=0 answer=no', 'realtime idle'];
        $settledFollowUps = [
            'scheduleRunTerminal runCompleted=yes',
            'curateMemory async=yes',
            'dispatchSupervisorCheckpointIfDue answer=no',
            'resumeSupervisedConversationIfAnInterventionWaits answer=no',
            'closeConversationIfPurposeGoalReached allowClose=yes',
            'notifySettledOwnerTurnListeners',
        ];
        $failedRoot = ['turnEnd failed', 'release FAILED attempts=0 owedAt=none', 'scheduleRunTerminal runCompleted=no', 'realtime failed'];
        $failedRootOverCap = ['turnEnd failed', 'release FAILED attempts=4 owedAt=none', 'scheduleRunTerminal runCompleted=no', 'realtime failed'];
        return [
            // ---- a throw before the drive (space, cover, messages load, steer materialization, derive) ----
            'pre-drive throw over the cap, child in a batch, its run end threw: FAILED 4 without a schedule record, rethrown' => [
                self::scenario(['preDriveFailure' => true, 'childRunEndFails' => true] + $overCap + $childInBatch),
                [$head, 'subAgentRunEnd FAILED', 'turnEnd failed', 'release FAILED attempts=4 owedAt=none', 'realtime failed'],
                RuntimeException::class,
            ],
            'pre-drive throw over the cap, child in a batch: its run end owns the release, realtime failed' => [
                self::scenario(['preDriveFailure' => true] + $overCap + $childInBatch),
                array_merge([$head, 'subAgentRunEnd FAILED'], $childRunEndWritten, ['realtime failed']),
                RuntimeException::class,
            ],
            'pre-drive throw over the cap, child in a batch that yielded: realtime running' => [
                self::scenario(['preDriveFailure' => true, 'childYields' => true] + $overCap + $childInBatch),
                [$head, 'subAgentRunEnd FAILED', 'realtime running'],
                RuntimeException::class,
            ],
            'pre-drive throw under the cap: IDLE 1 with the handover marker, realtime running' => [
                self::scenario(['preDriveFailure' => true]),
                [$head, 'release IDLE attempts=1 owedAt=now', 'realtime running'],
                RuntimeException::class,
            ],
            'pre-drive throw under the cap, child in a batch: the same as a root' => [
                self::scenario(['preDriveFailure' => true] + $childInBatch),
                [$head, 'release IDLE attempts=1 owedAt=now', 'realtime running'],
                RuntimeException::class,
            ],
            'pre-drive throw over the cap: FAILED 4 without a marker, the schedule records a failed firing' => [
                self::scenario(['preDriveFailure' => true] + $overCap),
                array_merge([$head], $failedRootOverCap),
                RuntimeException::class,
            ],
            'pre-drive throw over the cap, child without a batch: the same as a root' => [
                self::scenario(['preDriveFailure' => true, 'delegationChild' => true] + $overCap),
                array_merge([$head], $failedRootOverCap),
                RuntimeException::class,
            ],
            'pre-drive throw at attempts 2: 3 is still under the cap, IDLE 3 with the marker' => [
                self::scenario(['preDriveFailure' => true] + $atTheCap),
                [$head, 'release IDLE attempts=3 owedAt=now', 'realtime running'],
                RuntimeException::class,
            ],
            'pre-drive throw at attempts 2, child in a batch: under the cap, no run end' => [
                self::scenario(['preDriveFailure' => true] + $atTheCap + $childInBatch),
                [$head, 'release IDLE attempts=3 owedAt=now', 'realtime running'],
                RuntimeException::class,
            ],

            // ---- the scope is not eligible at the head of the resume ----
            'scope suspended at the head: read cached then fresh, FAILED 0, no drive, no stamp, nothing rethrown' => [
                self::scenario(['eligibilityStates' => [AgentAutomationEligibility::STATE_SUSPENDED]]),
                array_merge(['eligibility SUSPENDED cached', 'eligibility SUSPENDED fresh'], $failedRoot),
                null,
            ],
            'scope erased at the head: the suppression stamped, CANCELLED 0, the turn end cancelled, realtime idle' => [
                self::scenario(['eligibilityStates' => [AgentAutomationEligibility::STATE_ERASED]]),
                ['eligibility ERASED cached', 'eligibility ERASED fresh', 'recordSuppression ACCOUNT_ERASED', 'turnEnd cancelled', 'release CANCELLED attempts=0 owedAt=none', 'realtime idle'],
                null,
            ],
            'scope erased at the head, child in a batch: stamped, then its run end FAILED, realtime failed' => [
                self::scenario(['eligibilityStates' => [AgentAutomationEligibility::STATE_ERASED]] + $childInBatch),
                array_merge(['eligibility ERASED cached', 'eligibility ERASED fresh', 'recordSuppression ACCOUNT_ERASED', 'subAgentRunEnd FAILED'], $childRunEndWritten, ['realtime failed']),
                null,
            ],
            'cached read refused, the fresh read eligible: the turn is driven' => [
                self::scenario(['eligibilityStates' => [AgentAutomationEligibility::STATE_SUSPENDED, AgentAutomationEligibility::STATE_ELIGIBLE], 'turnRows' => [$assistant]]),
                array_merge(['eligibility SUSPENDED cached', 'eligibility ELIGIBLE fresh', 'drive', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'probe signalWaitingAbove=0 answer=no', 'realtime idle'], $settledFollowUps),
                null,
            ],
            'scope suspended while the turn ran: read fresh, FAILED 0, nothing rethrown' => [
                self::scenario(['eligibilityStates' => [AgentAutomationEligibility::STATE_ELIGIBLE, AgentAutomationEligibility::STATE_SUSPENDED], 'turnFailure' => 'scopeNotEligible']),
                array_merge([$head, 'drive', 'eligibility SUSPENDED fresh'], $failedRoot),
                null,
            ],

            // ---- the supervisor tandem ----
            'supervisor tandem: IDLE 0, realtime idle, no supervised conversation found' => [
                self::scenario(['purposeName' => AIConversationPurpose::SUPERVISOR_CHECKPOINT]),
                [$head, 'runSupervisorCheckpointTurn', 'release IDLE attempts=0 owedAt=none', 'realtime idle'],
                null,
            ],
            'supervisor tandem, no beat owed after the release' => [
                self::scenario(['purposeName' => AIConversationPurpose::SUPERVISOR_CHECKPOINT, 'supervisedConversationFound' => true]),
                [$head, 'runSupervisorCheckpointTurn', 'release IDLE attempts=0 owedAt=none', 'realtime idle', 'nextSupervisorBeatOwed answer=none'],
                null,
            ],
            'supervisor tandem, a beat still owed: re-fired after the release' => [
                self::scenario(['purposeName' => AIConversationPurpose::SUPERVISOR_CHECKPOINT, 'supervisedConversationFound' => true, 'beatStillOwed' => true]),
                [$head, 'runSupervisorCheckpointTurn', 'release IDLE attempts=0 owedAt=none', 'realtime idle', 'nextSupervisorBeatOwed answer=owed', 'reFire async'],
                null,
            ],
            'supervisor tandem, the read of the supervised conversation throws: swallowed after the release' => [
                self::scenario(['purposeName' => AIConversationPurpose::SUPERVISOR_CHECKPOINT, 'supervisedConversationLoadFails' => true]),
                [$head, 'runSupervisorCheckpointTurn', 'release IDLE attempts=0 owedAt=none', 'realtime idle', 'loadSupervisedConversationOf threw'],
                null,
            ],
            'supervisor tandem, the read of a still owed beat throws: swallowed after the release' => [
                self::scenario(['purposeName' => AIConversationPurpose::SUPERVISOR_CHECKPOINT, 'supervisedConversationFound' => true, 'beatStillOwedReadFails' => true]),
                [$head, 'runSupervisorCheckpointTurn', 'release IDLE attempts=0 owedAt=none', 'realtime idle', 'nextSupervisorBeatOwed threw'],
                null,
            ],

            // ---- no work at the first derive ----
            'no work: answered late, IDLE with the attempts unchanged, the probe after the release, realtime idle' => [
                self::scenario(['initialRows' => [$owner, $assistant]]),
                [$head, 'turnEnd answered (late)', 'release IDLE attempts=unchanged owedAt=none', 'probe signalWaitingAbove=1 answer=no', 'realtime idle'],
                null,
            ],
            'no work, a signal waits after the release: realtime running, re-fired' => [
                self::scenario(['initialRows' => [$owner, $assistant], 'signalWaiting' => true]),
                [$head, 'turnEnd answered (late)', 'release IDLE attempts=unchanged owedAt=none', 'probe signalWaitingAbove=1 answer=yes', 'realtime running', 'reFire async'],
                null,
            ],
            'no work, an unfinished batch: realtime running, the queue promoted' => [
                self::scenario(['initialRows' => [$owner, $assistant], 'unfinishedBatchAnswers' => [true]]),
                [$head, 'turnEnd answered (late)', 'release IDLE attempts=unchanged owedAt=none', 'probe signalWaitingAbove=1 answer=no', 'realtime running', 'promoteQueuedDelegations root=5101'],
                null,
            ],
            'no work at the first derive, answers became unread at the re-derive: no event, no probe, re-fired' => [
                self::scenario(['initialRows' => [$owner, $assistant], 'unreadBatchIdAnswers' => [null, self::BATCH_ID]]),
                [$head, 'release IDLE attempts=unchanged owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'no work on a purposed root: after the release a waiting intervention is looked for, then the purpose close' => [
                self::scenario(['initialRows' => [$owner, $assistant], 'purposeName' => AIConversationPurpose::SCHEDULE_RUN]),
                [$head, 'turnEnd answered (late)', 'release IDLE attempts=unchanged owedAt=none', 'probe signalWaitingAbove=1 answer=no', 'realtime idle', 'resumeSupervisedConversationIfAnInterventionWaits answer=no', 'closeConversationIfPurposeGoalReached allowClose=yes'],
                null,
            ],
            'no work on a purposed root whose intervention waited: it is resumed, no close' => [
                self::scenario(['initialRows' => [$owner, $assistant], 'purposeName' => AIConversationPurpose::SCHEDULE_RUN, 'interventionWaits' => true]),
                [$head, 'turnEnd answered (late)', 'release IDLE attempts=unchanged owedAt=none', 'probe signalWaitingAbove=1 answer=no', 'realtime idle', 'resumeSupervisedConversationIfAnInterventionWaits answer=yes'],
                null,
            ],
            'no work on a purposed root, the close throws: swallowed' => [
                self::scenario(['initialRows' => [$owner, $assistant], 'purposeName' => AIConversationPurpose::SCHEDULE_RUN, 'purposeGoalCloseFails' => true]),
                [$head, 'turnEnd answered (late)', 'release IDLE attempts=unchanged owedAt=none', 'probe signalWaitingAbove=1 answer=no', 'realtime idle', 'resumeSupervisedConversationIfAnInterventionWaits answer=no', 'closeConversationIfPurposeGoalReached allowClose=yes'],
                null,
            ],

            // ---- the drive started delegations (an unfinished batch after the drive) ----
            'delegations started: IDLE 0, realtime running, the probe after the publish, the queue promoted' => [
                self::scenario(['turnRows' => [$assistant], 'unfinishedBatchAnswers' => [true]]),
                [$head, 'drive', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'probe signalWaitingAbove=0 answer=no', 'promoteQueuedDelegations root=5101'],
                null,
            ],
            'delegations started, answers already unread at release time: re-fired without the probe' => [
                self::scenario(['turnRows' => [$assistant], 'unfinishedBatchAnswers' => [true], 'unreadBatchIdAnswers' => [null, self::BATCH_ID]]),
                [$head, 'drive', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async', 'promoteQueuedDelegations root=5101'],
                null,
            ],
            'delegations started by a conversation inside a tree: the queue of the tree root is promoted' => [
                self::scenario(['turnRows' => [$assistant], 'unfinishedBatchAnswers' => [true], 'insideATree' => true]),
                [$head, 'drive', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'probe signalWaitingAbove=0 answer=no', 'promoteQueuedDelegations root=5100'],
                null,
            ],
            'delegations started, a signal waits: re-fired' => [
                self::scenario(['turnRows' => [$assistant], 'unfinishedBatchAnswers' => [true], 'signalWaiting' => true]),
                [$head, 'drive', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'probe signalWaitingAbove=0 answer=yes', 'reFire async', 'promoteQueuedDelegations root=5101'],
                null,
            ],

            // ---- the turn ended waiting for its sub-agents ----
            'waiting for sub-agents: the outcome, IDLE 0, realtime running, the probe after the publish, the queue promoted, no follow-ups' => [
                self::scenario(['turnRows' => [$assistant], 'loopStopReason' => 'waitingForSubAgents']),
                [$head, 'drive', 'turnEnd waitingForSubAgents', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'probe signalWaitingAbove=0 answer=no', 'promoteQueuedDelegations root=5101'],
                null,
            ],
            'waiting for sub-agents, an owner message landed mid-turn: the note, re-fired at once without the probe' => [
                self::scenario(['turnRows' => [$owner, $assistant], 'loopStopReason' => 'waitingForSubAgents']),
                [$head, 'drive', 'persist SIGNAL MID_TURN_OWNER_INBOUND', 'turnEnd waitingForSubAgents', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async', 'promoteQueuedDelegations root=5101'],
                null,
            ],
            'waiting for sub-agents, an owner message trails the reload: re-fired at once, no note' => [
                self::scenario(['turnRows' => [$assistant], 'loopStopReason' => 'waitingForSubAgents', 'rowsPersistedByOtherWritersDuringTheTurn' => [$owner]]),
                [$head, 'drive', 'turnEnd waitingForSubAgents', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async', 'promoteQueuedDelegations root=5101'],
                null,
            ],

            // ---- a delegation child after the drive ----
            'child yield (the reloaded tail owes a turn): IDLE 0, realtime running, re-fired' => [
                self::scenario(['turnRows' => [$continuation]] + $childInBatch),
                [$head, 'drive', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'child verdict: the run end COMPLETED owns the release, the freed slot promoted, the parent notified, realtime idle' => [
                self::scenario(['turnRows' => [$assistant]] + $childInBatch),
                array_merge([$head, 'drive', 'subAgentRunEnd COMPLETED'], $childRunEndWritten, ['realtime idle']),
                null,
            ],
            'child verdict, the child yielded to an accepted owner input: nothing published' => [
                self::scenario(['turnRows' => [$assistant], 'childYields' => true] + $childInBatch),
                [$head, 'drive', 'subAgentRunEnd COMPLETED'],
                null,
            ],
            'child verdict of a child without a batch: the same run end' => [
                self::scenario(['turnRows' => [$assistant], 'delegationChild' => true]),
                array_merge([$head, 'drive', 'subAgentRunEnd COMPLETED'], $childRunEndWritten, ['realtime idle']),
                null,
            ],
            'child turn cut by a cap with no owed row at the tail: no yield, the run end COMPLETED' => [
                self::scenario(['turnRows' => [$assistant], 'loopStopReason' => 'stepBudgetReached'] + $childInBatch),
                array_merge([$head, 'drive', 'subAgentRunEnd COMPLETED'], $childRunEndWritten, ['realtime idle']),
                null,
            ],
            'child verdict, an unread orchestrator message below its text: a yield, re-fired' => [
                self::scenario(['turnRows' => [$unreadOrchestratorMessage, $assistant]] + $childInBatch),
                [$head, 'drive', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'child turn cut by a cap, an unread orchestrator message below its newest row: no yield, the run end COMPLETED' => [
                self::scenario(['turnRows' => [$unreadOrchestratorMessage, $assistant], 'loopStopReason' => 'stepBudgetReached'] + $childInBatch),
                array_merge([$head, 'drive', 'subAgentRunEnd COMPLETED'], $childRunEndWritten, ['realtime idle']),
                null,
            ],
            'tool-run child: its turn without a model, its terminal state ends the run, realtime idle' => [
                self::scenario(['purposeName' => AIConversationPurpose::BACKGROUND_MECHANICAL_OPERATION, 'toolRunTerminalRunState' => AIConversationRunState::FAILED] + $childInBatch),
                array_merge([$head, 'runToolRunChildTurn answer=FAILED', 'subAgentRunEnd FAILED'], $childRunEndWritten, ['realtime idle']),
                null,
            ],
            'tool-run child that yielded to an accepted owner input: nothing published' => [
                self::scenario(['purposeName' => AIConversationPurpose::BACKGROUND_MECHANICAL_OPERATION, 'childYields' => true] + $childInBatch),
                [$head, 'runToolRunChildTurn answer=COMPLETED', 'subAgentRunEnd COMPLETED'],
                null,
            ],

            // ---- a root turn without real progress while inbound is owed ----
            'stall give-up over the cap: report owed, the give-up reply, delivered, gaveUp, IDLE 0, the probe, realtime idle' => [
                self::scenario(['turnRows' => [$nudge]] + $overCap),
                [$head, 'drive', 'stampReportOwed', 'persist TEXT OWNER_REPORT', 'stampReportDelivered', 'turnEnd gaveUp', 'release IDLE attempts=0 owedAt=none', 'probe signalWaitingAbove=0 answer=no', 'realtime idle'],
                null,
            ],
            'stall give-up, a signal waits after the release: realtime running, re-fired' => [
                self::scenario(['turnRows' => [$nudge], 'signalWaiting' => true] + $overCap),
                [$head, 'drive', 'stampReportOwed', 'persist TEXT OWNER_REPORT', 'stampReportDelivered', 'turnEnd gaveUp', 'release IDLE attempts=0 owedAt=none', 'probe signalWaitingAbove=0 answer=yes', 'realtime running', 'reFire async'],
                null,
            ],
            'no progress under the cap: IDLE 1, realtime running, re-fired' => [
                self::scenario(['turnRows' => [$nudge]]),
                [$head, 'drive', 'release IDLE attempts=1 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'no progress at attempts 2: 3 is still under the cap, IDLE 3, re-fired, no give-up' => [
                self::scenario(['turnRows' => [$nudge]] + $atTheCap),
                [$head, 'drive', 'release IDLE attempts=3 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],

            // ---- a root turn that answered ----
            'answered: IDLE 0, the probe, realtime idle, then the follow-ups of an answered turn' => [
                self::scenario(['turnRows' => [$assistant]]),
                array_merge($answeredHead, $settledFollowUps),
                null,
            ],
            'answered, the checkpoint was dispatched: the close may not close' => [
                self::scenario(['turnRows' => [$assistant], 'checkpointDispatched' => true]),
                array_merge($answeredHead, ['scheduleRunTerminal runCompleted=yes', 'curateMemory async=yes', 'dispatchSupervisorCheckpointIfDue answer=yes', 'resumeSupervisedConversationIfAnInterventionWaits answer=no', 'closeConversationIfPurposeGoalReached allowClose=no', 'notifySettledOwnerTurnListeners']),
                null,
            ],
            'answered, an owner message trails the reload: no probe, no follow-ups, realtime running, re-fired' => [
                self::scenario(['turnRows' => [$assistant], 'rowsPersistedByOtherWritersDuringTheTurn' => [$owner]]),
                [$head, 'drive', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'answered, an owner message landed mid-turn below the answer: the note, then re-fired' => [
                self::scenario(['turnRows' => [$owner, $assistant]]),
                [$head, 'drive', 'persist SIGNAL MID_TURN_OWNER_INBOUND', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'answered, a waiting signal stayed unread (its text was replaced): re-fired' => [
                self::scenario(['turnRows' => [$assistant], 'waitingSignalsMarkedRead' => false]),
                [$head, 'drive', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'answered, a signal waits after the release: realtime running, re-fired, no follow-ups' => [
                self::scenario(['turnRows' => [$assistant], 'signalWaiting' => true]),
                [$head, 'drive', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'probe signalWaitingAbove=0 answer=yes', 'realtime running', 'reFire async'],
                null,
            ],
            'answered a turn that read unread answers: the children-finished signal, the drive, the read mark, then the answered release' => [
                self::scenario(['turnRows' => [$assistant], 'unreadBatchIdAnswers' => [self::BATCH_ID]]),
                array_merge([$head, 'injectChildrenFinishedSignal batch=7101', 'drive', 'markChildResultsReadByParent batch=7101', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'probe signalWaitingAbove=0 answer=no', 'realtime idle'], $settledFollowUps),
                null,
            ],

            // ---- the replay step of an answered turn ----
            'replay: a work signal that landed after the last model call is replayed: answered, re-fired, no probe, no follow-ups' => [
                self::scenario(['turnRows' => [$workSignal, $assistant]]),
                [$head, 'drive', 'persist SIGNAL MID_TURN_SIGNAL_REPLAY', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'replay: the read mark throws after the replay row: swallowed, answered, re-fired' => [
                self::scenario(['turnRows' => [$workSignal, $assistant], 'readMarkFailure' => 'write']),
                [$head, 'drive', 'persist SIGNAL MID_TURN_SIGNAL_REPLAY', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'replay: the read mark throws with nothing to replay: answered, re-fired instead of the probe' => [
                self::scenario(['turnRows' => [$assistant], 'readMarkFailure' => 'write']),
                [$head, 'drive', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'replay of a turn without progress: a read mark that left a signal waiting counts one attempt, re-fired' => [
                self::scenario(['turnRows' => [$repeatedTextSuppressed], 'waitingSignalsMarkedRead' => false] + $foldTurnWithoutOwedTail),
                [$head, 'injectChildrenFinishedSignal batch=7101', 'drive', 'markChildResultsReadByParent batch=7101', 'release IDLE attempts=1 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'replay of a turn without progress: a read mark that threw counts one attempt, re-fired' => [
                self::scenario(['turnRows' => [$repeatedTextSuppressed], 'readMarkFailure' => 'write'] + $foldTurnWithoutOwedTail),
                [$head, 'injectChildrenFinishedSignal batch=7101', 'drive', 'markChildResultsReadByParent batch=7101', 'release IDLE attempts=1 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],
            'replay of a turn without progress over the cap: the give-up reply, gaveUp, IDLE 0' => [
                self::scenario(['turnRows' => [$repeatedTextSuppressed], 'waitingSignalsMarkedRead' => false] + $foldTurnWithoutOwedTail + $overCap),
                [$head, 'injectChildrenFinishedSignal batch=7101', 'drive', 'markChildResultsReadByParent batch=7101', 'stampReportOwed', 'persist TEXT OWNER_REPORT', 'stampReportDelivered', 'turnEnd gaveUp', 'release IDLE attempts=0 owedAt=none', 'probe signalWaitingAbove=1 answer=no', 'realtime idle'],
                null,
            ],
            'a work signal above the pre-turn index is real progress: over the cap, a turn of steering and that signal is answered and re-fired for the signal, never given up' => [
                self::scenario(['turnRows' => [$workSignal, $repeatedTextSuppressed]] + $foldTurnWithoutOwedTail + $overCap),
                [$head, 'injectChildrenFinishedSignal batch=7101', 'drive', 'markChildResultsReadByParent batch=7101', 'turnEnd answered', 'release IDLE attempts=0 owedAt=none', 'realtime running', 'reFire async'],
                null,
            ],

            // ---- the follow-ups of an answered turn: each failure after the schedule record is swallowed ----
            'follow-up: the memory curation throws: swallowed, the rest runs' => [
                self::scenario(['turnRows' => [$assistant], 'curationFails' => true]),
                array_merge($answeredHead, ['scheduleRunTerminal runCompleted=yes', 'curateMemory threw', 'dispatchSupervisorCheckpointIfDue answer=no', 'resumeSupervisedConversationIfAnInterventionWaits answer=no', 'closeConversationIfPurposeGoalReached allowClose=yes', 'notifySettledOwnerTurnListeners']),
                null,
            ],
            'follow-up: the checkpoint dispatch throws: counted as not dispatched, the close may close' => [
                self::scenario(['turnRows' => [$assistant], 'checkpointDispatchFails' => true]),
                array_merge($answeredHead, ['scheduleRunTerminal runCompleted=yes', 'curateMemory async=yes', 'dispatchSupervisorCheckpointIfDue threw', 'resumeSupervisedConversationIfAnInterventionWaits answer=no', 'closeConversationIfPurposeGoalReached allowClose=yes', 'notifySettledOwnerTurnListeners']),
                null,
            ],
            'follow-up: the re-fire of a waiting intervention throws: swallowed, the close still runs' => [
                self::scenario(['turnRows' => [$assistant], 'interventionReFireFails' => true]),
                array_merge($answeredHead, ['scheduleRunTerminal runCompleted=yes', 'curateMemory async=yes', 'dispatchSupervisorCheckpointIfDue answer=no', 'resumeSupervisedConversationIfAnInterventionWaits threw', 'closeConversationIfPurposeGoalReached allowClose=yes', 'notifySettledOwnerTurnListeners']),
                null,
            ],
            'follow-up: the purpose close throws: swallowed, the listeners still run' => [
                self::scenario(['turnRows' => [$assistant], 'purposeGoalCloseFails' => true]),
                array_merge($answeredHead, $settledFollowUps),
                null,
            ],
            'follow-up: the listeners cannot run: swallowed, nothing rethrown' => [
                self::scenario(['turnRows' => [$assistant], 'settledListenersFail' => true]),
                array_merge($answeredHead, ['scheduleRunTerminal runCompleted=yes', 'curateMemory async=yes', 'dispatchSupervisorCheckpointIfDue answer=no', 'resumeSupervisedConversationIfAnInterventionWaits answer=no', 'closeConversationIfPurposeGoalReached allowClose=yes', 'notifySettledOwnerTurnListeners threw']),
                null,
            ],

            // ---- the AI budget is used up ----
            'budget used up: FAILED 0, the schedule records a failed firing, realtime failed, nothing rethrown' => [
                self::scenario(['turnFailure' => 'budget']),
                array_merge([$head, 'drive'], $failedRoot),
                null,
            ],
            'budget used up, child in a batch: its run end FAILED, realtime failed' => [
                self::scenario(['turnFailure' => 'budget'] + $childInBatch),
                array_merge([$head, 'drive', 'subAgentRunEnd FAILED'], $childRunEndWritten, ['realtime failed']),
                null,
            ],
            'budget used up, child in a batch that yielded: nothing published' => [
                self::scenario(['turnFailure' => 'budget', 'childYields' => true] + $childInBatch),
                [$head, 'drive', 'subAgentRunEnd FAILED'],
                null,
            ],
            'budget used up, child without a batch: the same as a root' => [
                self::scenario(['turnFailure' => 'budget', 'delegationChild' => true]),
                array_merge([$head, 'drive'], $failedRoot),
                null,
            ],

            // ---- a provider error ----
            'provider error: FAILED 0 whatever the attempts, nothing rethrown' => [
                self::scenario(['turnFailure' => 'provider', 'resumeAttempts' => 2]),
                array_merge([$head, 'drive'], $failedRoot),
                null,
            ],
            'provider error, child in a batch: its run end FAILED' => [
                self::scenario(['turnFailure' => 'provider'] + $childInBatch),
                array_merge([$head, 'drive', 'subAgentRunEnd FAILED'], $childRunEndWritten, ['realtime failed']),
                null,
            ],
            'provider error, child in a batch that yielded: nothing published' => [
                self::scenario(['turnFailure' => 'provider', 'childYields' => true] + $childInBatch),
                [$head, 'drive', 'subAgentRunEnd FAILED'],
                null,
            ],

            // ---- the message-index lock stayed unavailable ----
            'index lock under the cap: IDLE 1 with the marker, realtime idle, nothing rethrown' => [
                self::scenario(['turnFailure' => 'indexLock']),
                [$head, 'drive', 'release IDLE attempts=1 owedAt=now', 'realtime idle'],
                null,
            ],
            'index lock under the cap, child in a batch: the same as a root' => [
                self::scenario(['turnFailure' => 'indexLock'] + $childInBatch),
                [$head, 'drive', 'release IDLE attempts=1 owedAt=now', 'realtime idle'],
                null,
            ],
            'index lock over the cap: FAILED 4 without a marker, the schedule records a failed firing' => [
                self::scenario(['turnFailure' => 'indexLock'] + $overCap),
                array_merge([$head, 'drive'], $failedRootOverCap),
                null,
            ],
            'index lock over the cap, child in a batch: its run end FAILED' => [
                self::scenario(['turnFailure' => 'indexLock'] + $overCap + $childInBatch),
                array_merge([$head, 'drive', 'subAgentRunEnd FAILED'], $childRunEndWritten, ['realtime failed']),
                null,
            ],
            'index lock over the cap, child in a batch that yielded: nothing published' => [
                self::scenario(['turnFailure' => 'indexLock', 'childYields' => true] + $overCap + $childInBatch),
                [$head, 'drive', 'subAgentRunEnd FAILED'],
                null,
            ],
            'index lock at attempts 2: IDLE 3 with the marker' => [
                self::scenario(['turnFailure' => 'indexLock'] + $atTheCap),
                [$head, 'drive', 'release IDLE attempts=3 owedAt=now', 'realtime idle'],
                null,
            ],
            'index lock at attempts 2, child in a batch: under the cap, no run end' => [
                self::scenario(['turnFailure' => 'indexLock'] + $atTheCap + $childInBatch),
                [$head, 'drive', 'release IDLE attempts=3 owedAt=now', 'realtime idle'],
                null,
            ],

            // ---- the model call's HTTP transfer failed ----
            'transport failure under the cap: IDLE 1 with the marker, realtime running, nothing rethrown' => [
                self::scenario(['turnFailure' => 'transport']),
                [$head, 'drive', 'release IDLE attempts=1 owedAt=now', 'realtime running'],
                null,
            ],
            'transport failure after real progress still counts: IDLE 3 with the marker' => [
                self::scenario(['turnFailure' => 'transport', 'turnRows' => [$assistant]] + $atTheCap),
                [$head, 'drive', 'release IDLE attempts=3 owedAt=now', 'realtime running'],
                null,
            ],
            'transport failure over the cap: FAILED 4 without a marker' => [
                self::scenario(['turnFailure' => 'transport'] + $overCap),
                array_merge([$head, 'drive'], $failedRootOverCap),
                null,
            ],
            'transport failure over the cap, child in a batch: its run end FAILED' => [
                self::scenario(['turnFailure' => 'transport'] + $overCap + $childInBatch),
                array_merge([$head, 'drive', 'subAgentRunEnd FAILED'], $childRunEndWritten, ['realtime failed']),
                null,
            ],
            'transport failure over the cap, child in a batch that yielded: nothing published' => [
                self::scenario(['turnFailure' => 'transport', 'childYields' => true] + $overCap + $childInBatch),
                [$head, 'drive', 'subAgentRunEnd FAILED'],
                null,
            ],

            // ---- any other turn error (rethrown): the turn stays owed under the cap ----
            'turn error without progress under the cap: IDLE 1 with the marker, realtime running, rethrown' => [
                self::scenario(['turnFailure' => 'generic']),
                [$head, 'drive', 'release IDLE attempts=1 owedAt=now', 'realtime running'],
                RuntimeException::class,
            ],
            'turn error after real progress still counts one attempt' => [
                self::scenario(['turnFailure' => 'generic', 'turnRows' => [$assistant], 'resumeAttempts' => 2]),
                [$head, 'drive', 'release IDLE attempts=3 owedAt=now', 'realtime running'],
                RuntimeException::class,
            ],
            'turn error after only a loop nudge: one attempt' => [
                self::scenario(['turnFailure' => 'generic', 'turnRows' => [$nudge], 'resumeAttempts' => 1]),
                [$head, 'drive', 'release IDLE attempts=2 owedAt=now', 'realtime running'],
                RuntimeException::class,
            ],
            'turn error at attempts 2, child in a batch: under the cap, no run end, the marker' => [
                self::scenario(['turnFailure' => 'generic'] + $atTheCap + $childInBatch),
                [$head, 'drive', 'release IDLE attempts=3 owedAt=now', 'realtime running'],
                RuntimeException::class,
            ],
            'turn error over the cap: FAILED 4, the schedule records a failed firing, realtime failed' => [
                self::scenario(['turnFailure' => 'generic'] + $overCap),
                array_merge([$head, 'drive'], $failedRootOverCap),
                RuntimeException::class,
            ],
            'turn error over the cap after real progress: FAILED 4 like without progress' => [
                self::scenario(['turnFailure' => 'generic', 'turnRows' => [$assistant]] + $overCap),
                array_merge([$head, 'drive'], $failedRootOverCap),
                RuntimeException::class,
            ],
            'turn error over the cap, child in a batch: its run end FAILED' => [
                self::scenario(['turnFailure' => 'generic'] + $overCap + $childInBatch),
                array_merge([$head, 'drive', 'subAgentRunEnd FAILED'], $childRunEndWritten, ['realtime failed']),
                RuntimeException::class,
            ],
            'turn error over the cap, child in a batch that yielded: realtime running' => [
                self::scenario(['turnFailure' => 'generic', 'childYields' => true] + $overCap + $childInBatch),
                [$head, 'drive', 'subAgentRunEnd FAILED', 'realtime running'],
                RuntimeException::class,
            ],
            'a claim lost inside the replay write reaches the turn-error release: rethrown, the release writes nothing and publishes nothing' => [
                self::scenario(['turnRows' => [$assistant], 'readMarkFailure' => 'claimLost', 'claimHeldByAnotherWorker' => true]),
                [$head, 'drive', 'release IDLE attempts=1 owedAt=now'],
                AgentRunClaimLostException::class,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $scenario
     * @param string[] $expectedEvents
     * @param class-string<Throwable>|null $expectedRethrownClass
     */
    #[DataProvider('provideReleaseRows')]
    public function testTheReleaseOfEachBranch(array $scenario, array $expectedEvents, ?string $expectedRethrownClass): void
    {
        $conversation = $this->arrangeScenario($scenario);

        $rethrownClass = null;
        try {
            $this->agentService->resumeConversationIfReady($conversation);
        } catch (Throwable $rethrown) {
            $rethrownClass = $rethrown::class;
        }

        self::assertSame($expectedEvents, $this->eventLog->events);
        self::assertSame($expectedRethrownClass, $rethrownClass, 'what the resume rethrows to its handler');
        self::assertSame(1, $this->runClaimsService->claimCount, 'one claim, through the stage\'s run claims');
    }

    /**
     * The replay row carries the ORIGINAL text of the work signal under the replay source, after the answer, so the
     * tail owes a turn again; it is the only row the release path persists for it.
     */
    public function testTheReplayRowCarriesTheOriginalSignalTextAfterTheAnswer(): void
    {
        $conversation = $this->arrangeScenario(self::scenario(['turnRows' => [RunReleaseScriptedRows::WORK_SIGNAL, RunReleaseScriptedRows::ASSISTANT_TEXT]]));

        $this->agentService->resumeConversationIfReady($conversation);

        self::assertSame([RunReleaseScriptedRows::WORK_SIGNAL_TEXT], $this->messagesService->persistedMessageTexts);
        $rowsByIndex = [];
        foreach ($conversation->messages->getElements() as $message) {
            $rowsByIndex[$message->indexInConversation] = [$message->messageType, $message->signalSource ?? null];
        }
        ksort($rowsByIndex);
        self::assertSame([
            0 => ['TEXT', null],
            1 => ['SIGNAL', 'ORCHESTRATOR_MESSAGE'],
            2 => ['TEXT', null],
            3 => ['SIGNAL', 'MID_TURN_SIGNAL_REPLAY'],
        ], $rowsByIndex);
        self::assertTrue($conversation->hasUnansweredInboundMessage(), 'the replay row makes the tail owe a turn');
    }

    /** The stall give-up reply is the run's report: an assistant TEXT marked OWNER_REPORT with the package's words. */
    public function testTheStallGiveUpReplyIsPersistedAsTheRunsReport(): void
    {
        $conversation = $this->arrangeScenario(self::scenario(['turnRows' => [RunReleaseScriptedRows::LOOP_NUDGE], 'resumeAttempts' => 3]));

        $this->agentService->resumeConversationIfReady($conversation);

        self::assertSame([AIConversationAgentService::STALL_GIVE_UP_REPLY], $this->messagesService->persistedMessageTexts);
        self::assertSame(
            "I wasn't able to produce a response to that just now. Could you rephrase it or add a little more detail?",
            AIConversationAgentService::STALL_GIVE_UP_REPLY
        );
        $newestRow = $conversation->messages->getElements()[count($conversation->messages->getElements()) - 1];
        self::assertSame([AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT], [$newestRow->messageType, $newestRow->role, $newestRow->signalSource]);
        self::assertFalse($conversation->hasUnansweredInboundMessage(), 'the reply answers the tail: nothing re-fires for it');
    }

    /** The mid-turn note quotes the owner's row that landed below the answer, and owes the next turn. */
    public function testTheMidTurnNoteQuotesTheOwnersRow(): void
    {
        $conversation = $this->arrangeScenario(self::scenario(['turnRows' => [RunReleaseScriptedRows::OWNER_MESSAGE, RunReleaseScriptedRows::ASSISTANT_TEXT]]));

        $this->agentService->resumeConversationIfReady($conversation);

        self::assertCount(1, $this->messagesService->persistedMessageTexts);
        self::assertStringContainsString('And one more thing.', $this->messagesService->persistedMessageTexts[0]);
        self::assertTrue($conversation->hasUnansweredInboundMessage(), 'the note owes the next turn');
    }

    /** @param array<string, mixed> $scenario */
    protected function arrangeScenario(array $scenario): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $scenario['delegationChild'] ? self::CHILD_CONVERSATION_ID : self::ROOT_CONVERSATION_ID;
        $conversation->messages = new AIConversationMessages();
        $conversation->compactionNodes = new AIConversationCompactionNodes();
        $conversation->runState = AIConversationRunState::IDLE;
        $conversation->resumeAttempts = $scenario['resumeAttempts'];
        $conversation->budgetBypass = false;
        $conversation->purposeName = $scenario['purposeName'];
        $conversation->delegationBatchId = $scenario['delegationBatchId'];
        $conversation->delegatedFromMessageId = $scenario['delegationChild'] ? self::DELEGATE_CALL_MESSAGE_ID : null;
        $conversation->rootConversationId = $scenario['delegationChild'] ? self::ROOT_CONVERSATION_ID : ($scenario['insideATree'] ? self::TREE_ROOT_CONVERSATION_ID : null);
        $conversation->supervisedConversationId = $scenario['purposeName'] === AIConversationPurpose::SUPERVISOR_CHECKPOINT ? self::ROOT_CONVERSATION_ID + 100 : null;
        foreach ($scenario['initialRows'] as $initialRowName) {
            RunReleaseScriptedRows::append($conversation, $initialRowName);
        }

        $this->agentService->turnRows = $scenario['turnRows'];
        $this->agentService->turnFailure = self::buildTurnFailure($scenario['turnFailure']);
        $this->agentService->loopStopReason = self::buildLoopStopReason($scenario['loopStopReason']);
        $this->messagesService->rowsPersistedByOtherWritersDuringTheTurn = $scenario['rowsPersistedByOtherWritersDuringTheTurn'];
        $this->messagesService->firstTailLoadFailure = $scenario['preDriveFailure'] ? new RuntimeException('the tail load failed') : null;
        $this->conversationsService->eligibilityAnswers = [];
        foreach ($scenario['eligibilityStates'] as $eligibilityState) {
            $this->conversationsService->eligibilityAnswers[] = self::buildEligibility($eligibilityState);
        }
        $this->runClaimsService->claimHeldByAnotherWorker = $scenario['claimHeldByAnotherWorker'];
        $this->delegationResultsService->runEndFailure = $scenario['childRunEndFails'] ? new RuntimeException('the run end failed') : null;
        $this->delegationResultsService->childYieldsAnswer = $scenario['childYields'];
        $this->delegationBatchesService->unreadBatchIdAnswers = $scenario['unreadBatchIdAnswers'];
        $this->delegationBatchesService->unfinishedBatchAnswers = $scenario['unfinishedBatchAnswers'];
        $this->delegationToolRunService->terminalRunStateAnswer = $scenario['toolRunTerminalRunState'];
        $this->turnSignalsService->signalWaitingAnswer = $scenario['signalWaiting'];
        $this->turnSignalsService->waitingSignalsMarkedReadAnswer = $scenario['waitingSignalsMarkedRead'];
        $this->turnSignalsService->waitingSignalsMarkReadFailure = match ($scenario['readMarkFailure']) {
            null => null,
            'write' => new RuntimeException('the read mark failed'),
            'claimLost' => new AgentRunClaimLostException('claim lost inside the replay write'),
            default => throw new InvalidArgumentException('unknown read mark failure ' . $scenario['readMarkFailure']),
        };
        $this->memoriesService->curationFailure = $scenario['curationFails'] ? new RuntimeException('the curation failed') : null;
        $this->supervisorCheckpointRunsService->checkpointDispatchedAnswer = $scenario['checkpointDispatched'];
        $this->supervisorCheckpointRunsService->checkpointDispatchFailure = $scenario['checkpointDispatchFails'] ? new RuntimeException('the dispatch failed') : null;
        $this->supervisorCheckpointRunsService->interventionReFireFailure = $scenario['interventionReFireFails'] ? new RuntimeException('the intervention re-fire failed') : null;
        $this->supervisorCheckpointRunsService->interventionWaitsAnswer = $scenario['interventionWaits'];
        $this->supervisorCheckpointRunsService->supervisedConversation = $scenario['supervisedConversationFound'] ? new AIConversation() : null;
        $this->supervisorCheckpointRunsService->supervisedConversationLoadFailure = $scenario['supervisedConversationLoadFails'] ? new RuntimeException('the supervised read failed') : null;
        $this->supervisorCheckpointRunsService->beatStillOwedAnswer = $scenario['beatStillOwed'];
        $this->supervisorCheckpointRunsService->beatStillOwedFailure = $scenario['beatStillOwedReadFails'] ? new RuntimeException('the beat read failed') : null;
        $this->purposeRunsService->closeFailure = $scenario['purposeGoalCloseFails'] ? new RuntimeException('the close failed') : null;
        $this->settledOwnerTurnListenersService->notificationFailure = $scenario['settledListenersFail'] ? new RuntimeException('the listeners failed') : null;
        return $conversation;
    }

    protected static function buildTurnFailure(?string $turnFailureKind): ?Throwable
    {
        return match ($turnFailureKind) {
            null => null,
            'budget' => new AiBudgetExceededException('AI Operation exceeds available AI Budget'),
            'provider' => new AIModelCallFailedException('400 INVALID_ARGUMENT'),
            'indexLock' => new MessageIndexLockUnavailableException(self::ROOT_CONVERSATION_ID),
            'transport' => new AIModelCallTransportFailedException(new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://model.example/v1/chat/completions'))),
            'scopeNotEligible' => new AIScopeNotEligibleException('the scope stopped permitting automated work'),
            'generic' => new RuntimeException('the tool layer failed'),
            default => throw new InvalidArgumentException('unknown turn failure ' . $turnFailureKind),
        };
    }

    protected static function buildLoopStopReason(?string $loopStopReasonName): ?AgentLoopStopReason
    {
        return match ($loopStopReasonName) {
            null => null,
            'waitingForSubAgents' => AgentLoopStopReason::waitingForSubAgents(),
            'stepBudgetReached' => AgentLoopStopReason::stepBudgetReached(),
            default => throw new InvalidArgumentException('unknown loop stop reason ' . $loopStopReasonName),
        };
    }

    protected static function buildEligibility(string $state): AgentAutomationEligibility
    {
        $eligibility = AgentAutomationEligibility::eligible();
        $eligibility->state = $state;
        return $eligibility;
    }
}
