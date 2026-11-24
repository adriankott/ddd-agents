<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Delegation\ParallelTasks;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentSubAgentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRefusal;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Mcp\Agents\AgentConsoleStream;

/**
 * The spawn service that starts no child: a spawned sub-agent gets its queued record on its row and the answer names
 * it; the task text {@see REFUSED_TASK_TEXT} is refused by the model's guards.
 */
class SpawnServiceThatStartsNoChild extends AIConversationDelegationSpawnService
{
    public const string REFUSED_TASK_TEXT = 'a task the guards refuse';

    public const string REFUSAL_TEXT = 'NOT started: refused by the test guard.';

    /** @var string[] The labels of the spawned sub-agents, in spawn order */
    public array $spawnedLabels = [];

    public function spawnSubAgent(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $parentConversation,
        AgentSubAgentRequest $agentSubAgentRequest,
        int $subAgentNumber,
        AIConversationMessage $delegateCallMessage,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $async,
        bool $admitsAtOnce = true
    ): string {
        $this->spawnedLabels[] = $agentSubAgentRequest->buildRecordLabel();
        $delegationSubAgentRecord = DelegationSubAgentRecord::queued($subAgentNumber, $agentSubAgentRequest, 0, new DateTime());
        $this->attachSubAgentRecord($parentConversation, $delegateCallMessage, $delegationSubAgentRecord);
        return $delegationSubAgentRecord->buildSpawnAnswerText(null);
    }

    protected function findRefusalOfModelRequestedTask(
        AgentRun $agentRun,
        AIConversation $parentConversation,
        AgentSubAgentRequest $agentSubAgentRequest,
        ToolCall $delegateToolCall,
        bool $async
    ): ?DelegationSubAgentRefusal {
        if ($agentSubAgentRequest->task->buildChildSeedText() === self::REFUSED_TASK_TEXT
            || str_contains($agentSubAgentRequest->task->buildChildSeedText(), self::REFUSED_TASK_TEXT)
        ) {
            return DelegationSubAgentRefusal::forFailureReasonAndCorrective(DelegationSubAgentRun::FAILURE_REASON_REFUSED, self::REFUSAL_TEXT);
        }
        return null;
    }
}
