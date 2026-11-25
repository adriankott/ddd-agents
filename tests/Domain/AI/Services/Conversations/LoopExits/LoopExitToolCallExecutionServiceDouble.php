<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\LoopExits;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallExecutionService;

/**
 * The tool execution of the loop-exit stage: every call completes with `result:<toolName>`, except the one named
 * tool during which another worker takes over the run claim.
 */
class LoopExitToolCallExecutionServiceDouble extends AgentToolCallExecutionService
{
    /** The tool during whose execution another worker takes over the run claim. */
    public ?string $loseRunClaimOnToolName = null;

    /** @var string[] the executed tool names, in order */
    public array $executedToolNames = [];

    public function __construct()
    {
        // The parent service is container-managed and holds no constructor state.
    }

    public function executeToolCall(ToolCall $toolCall, AIConversation $conversation): ToolResult
    {
        $toolName = (string)($toolCall->name ?? '');
        $this->executedToolNames[] = $toolName;
        if ($toolName === $this->loseRunClaimOnToolName) {
            throw new AgentRunClaimLostException('claim lost during ' . $toolName);
        }
        $toolCall->markCompleted();
        return new ToolResult('result:' . $toolName);
    }
}
