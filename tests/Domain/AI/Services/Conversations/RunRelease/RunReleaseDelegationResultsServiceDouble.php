<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentSubAgentRunEnd;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationResultsService;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use Throwable;

/**
 * The delegation results of the release stage. The children-finished signal before a turn that reads unread answers,
 * the run end of a sub-agent (with its terminal state) and the notification of its parent are recorded; the run end
 * answers that the child yielded to an input accepted after its last look, or that its end was written and freed a slot
 * of its tree, or throws, as scripted. The background note, the repair of settled delegate calls and the adoption of a
 * delegation anchor do nothing.
 */
class RunReleaseDelegationResultsServiceDouble extends AIConversationDelegationResultsService
{
    public RunReleaseEventLog $eventLog;

    /** Whether the run end answers that the child yielded to an input accepted after its last look. */
    public bool $childYieldsAnswer = false;

    /** Thrown by the run end (the child's terminal write failed). */
    public ?Throwable $runEndFailure = null;

    /** The tree root whose slot a written run end frees. */
    public int $rootConversationIdWithFreedSlot = 0;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function injectChildrenFinishedSignal(AIConversation $conversation, int $batchId, AIConversationDelegationBatchesService $batchesService): void
    {
        $this->eventLog->record('injectChildrenFinishedSignal batch=' . $batchId);
    }

    public function injectBackgroundWorkFinishedNote(AIConversation $conversation, AIConversationDelegationBatchesService $batchesService): void
    {
    }

    public function repairDelegateCallsOfSettledBatches(AIConversation $conversation, AIConversationDelegationBatchesService $batchesService): void
    {
    }

    public function adoptDelegationAnchorJoinedMidTurn(AIConversation $conversation): void
    {
    }

    public function recordSubAgentRunEnd(
        AgentRun $agentRun,
        AIConversation $childConversation,
        string $runToken,
        string $terminalRunState,
        AIConversationDelegationBatchesService $batchesService
    ): AgentSubAgentRunEnd {
        $this->eventLog->record('subAgentRunEnd ' . $terminalRunState);
        if ($this->runEndFailure !== null) {
            throw $this->runEndFailure;
        }
        if ($this->childYieldsAnswer) {
            return AgentSubAgentRunEnd::yieldedForAcceptedInput();
        }
        return AgentSubAgentRunEnd::decided(true, $this->rootConversationIdWithFreedSlot, $childConversation->delegatedFromMessageId ?? null, 1);
    }

    public function notifyParentAfterRunEnd(AgentSubAgentRunEnd $agentSubAgentRunEnd, AIConversationDelegationBatchesService $batchesService): void
    {
        $this->eventLog->record('notifyParentAfterRunEnd');
    }
}
