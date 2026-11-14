<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallFork;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;

/** The fork-join with the child's outcome given: no child process runs, the join adopts {@see $stubbedOutcome}. */
class OutcomeStubbingForkJoinService extends AgentToolCallForkJoinService
{
    /** The outcome the child wrote into the row, as the join reads it */
    public ToolCall|string $stubbedOutcome = '';

    public function waitForOutcome(AgentToolCallFork $agentToolCallFork, string $toolCallId, AIConversation $conversation): ToolCall|string
    {
        return $this->stubbedOutcome;
    }
}
