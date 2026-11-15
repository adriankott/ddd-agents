<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;

/** The agent service whose settle closes every conversation whose id the test listed; records each settle. */
class ReaperSettlingAgentServiceDouble extends AIConversationAgentService
{
    /** @var int[] The conversations the settle closes */
    public array $conversationIdsClosedBySettle = [];

    /**
     * @param RecoveryPassLog $recoveryPassLog
     */
    public function __construct(public RecoveryPassLog $recoveryPassLog)
    {
    }

    public function settlePurposeConversationIfGoalReached(AIConversation $conversation): void
    {
        $this->recoveryPassLog->record("settle:{$conversation->id}");
        if (in_array((int)$conversation->id, $this->conversationIdsClosedBySettle, true)) {
            $conversation->runState = AIConversationRunState::COMPLETED;
        }
    }
}
