<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\LoopExits;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageBookedCosts;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease\RunReleaseConversationsServiceDouble;

/**
 * The conversations service of the loop-exit stage: the activity stamp of a yield is recorded with its handover
 * marker; the turn's overhead costs, the stamped creation settings and the working-state slices are not written.
 */
class LoopExitConversationsServiceDouble extends RunReleaseConversationsServiceDouble
{
    public function recordInboundMessageActivity(AIConversation $conversation, bool $stampAgentTurnOwed = false): void
    {
        $this->eventLog->record('recordInboundMessageActivity stampAgentTurnOwed=' . ($stampAgentTurnOwed ? 'yes' : 'no'));
    }

    public function addOverheadCostsToConversation(int $aiConversationId, AIUsageBookedCosts $overheadBookedCosts): void
    {
    }

    public function persistContextRetentionSettings(AIConversation $conversation): void
    {
    }

    /** A slice of the working state (the closing step's stamp) stays on the loaded conversation: nothing is written. */
    protected function writeAgentWorkingStateSliceUnderRunToken(AIConversation $conversation, array $slice, ?string $runToken): void
    {
    }

    /** The in-memory rows answer: the stage's conversation has no stored rows. */
    public function conversationHasModelCall(AIConversation $conversation): bool
    {
        foreach ($conversation->messages->getElements() as $message) {
            if (($message->inputTokens ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }
}
