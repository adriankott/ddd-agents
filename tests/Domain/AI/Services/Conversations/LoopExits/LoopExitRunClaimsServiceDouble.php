<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\LoopExits;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease\RunReleaseRunClaimsServiceDouble;

/**
 * The run claims of the loop-exit stage: the step-boundary heartbeat answers whether the claim is still held
 * (scripted), forgetting the process's token is recorded, no sub-agent run is unfinished.
 */
class LoopExitRunClaimsServiceDouble extends RunReleaseRunClaimsServiceDouble
{
    /** What the step-boundary heartbeat answers: false = another worker took the claim. */
    public bool $heartbeatHeld = true;

    public function heartbeat(AIConversation $conversation, string $runToken): bool
    {
        return $this->heartbeatHeld;
    }

    public function forgetRunTokenClaimedInThisProcess(AIConversation $conversation): void
    {
        $this->eventLog->record('forgetRunTokenClaimedInThisProcess');
    }

    public function hasUnfinishedSubAgentRunOnANotificationTurn(AIConversation $parentConversation): bool
    {
        return false;
    }
}
