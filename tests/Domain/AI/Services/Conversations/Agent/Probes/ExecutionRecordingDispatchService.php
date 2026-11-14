<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\Probes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService;

/** The dispatch with the execution of a tool recorded and answered with a fixed result; no executor runs. */
class ExecutionRecordingDispatchService extends AgentToolCallDispatchService
{
    /** @var string[] The names of the tools the dispatch executed, in order */
    public array $executedToolNames = [];

    /** The result every execution returns; null = a successful "done" */
    public ?ToolResult $stubbedToolResult = null;

    protected function executeToolCall(ToolCall $toolCall, AIConversation $conversation): ToolResult
    {
        $this->executedToolNames[] = (string)($toolCall->name ?? '');
        $toolResult = $this->stubbedToolResult ?? new ToolResult('{"content":[{"type":"text","text":"done"}]}');
        $toolCall->applyExecutionOutcome($toolResult);
        return $toolResult;
    }
}
