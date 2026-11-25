<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService;
use Throwable;

/**
 * The real {@see AgentToolCallDispatchService::dispatchToolCall()} with its outward services replaced: the execution of a
 * tool answers a fixed {@see ToolResult} or throws, the plan application is
 * {@see DispatchTablePlanRecordServiceDouble}, the sub-agent spawn is {@see DispatchTableDelegationSpawnServiceDouble},
 * the failure log records instead of writing, the purpose of the conversation's tree is given by the test, and the
 * sub-agent records are built from the loaded rows. Every hit is recorded, so a case asserts which of them it reached.
 */
class DispatchTableDispatchServiceDouble extends AgentToolCallDispatchService
{
    /** @var string[] The names of the tools the dispatch executed, in order */
    public array $executedToolNames = [];

    /** @var array<int, array{toolName: string, error: string, exceptionClass: string|null}> Every failure the dispatch logged */
    public array $loggedFailures = [];

    /** The result the next execution answers; null = `result:<tool name>`, no error */
    public ?ToolResult $nextToolResult = null;

    /** Thrown by every execution instead of an answer */
    public ?Throwable $throwOnExecute = null;

    /** The purpose of the conversation's tree; null = none */
    public ?AIConversationPurpose $purposeOfConversationTree = null;

    public DispatchTablePlanRecordServiceDouble $planRecordServiceDouble;

    public DispatchTableDelegationSpawnServiceDouble $delegationSpawnServiceDouble;

    public function __construct()
    {
        $this->planRecordServiceDouble = new DispatchTablePlanRecordServiceDouble();
        $this->delegationSpawnServiceDouble = new DispatchTableDelegationSpawnServiceDouble();
    }

    protected function executeToolCall(ToolCall $toolCall, AIConversation $conversation): ToolResult
    {
        $toolName = (string)($toolCall->name ?? '');
        $this->executedToolNames[] = $toolName;
        if ($this->throwOnExecute !== null) {
            throw $this->throwOnExecute;
        }
        $toolResult = $this->nextToolResult ?? new ToolResult('result:' . $toolName);
        $toolCall->applyExecutionOutcome($toolResult);
        return $toolResult;
    }

    protected function findSubAgentRecordsOfConversation(AIConversation $conversation): DelegationSubAgentRecords
    {
        return $this->buildSubAgentRecordsOfLoadedRows($conversation);
    }

    protected function delegationSpawnService(): AIConversationDelegationSpawnService
    {
        return $this->delegationSpawnServiceDouble;
    }

    protected function taskPlanRecordService(): AIConversationTaskPlanRecordService
    {
        return $this->planRecordServiceDouble;
    }

    protected function findPurposeOfConversationTree(AIConversation $conversation): ?AIConversationPurpose
    {
        return $this->purposeOfConversationTree;
    }

    public function logToolFailure(
        AIConversation $conversation,
        string $toolName,
        array $arguments,
        string $error,
        ?Throwable $exception,
        ?string $modelName
    ): void {
        $this->loggedFailures[] = ['toolName' => $toolName, 'error' => $error, 'exceptionClass' => $exception !== null ? $exception::class : null];
    }
}
