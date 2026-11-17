<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationTurnMessages;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the plan render reads from the CURRENT turn, derived from its rows at each context build (never stored): the plan
 * items an open `askOwner` question of this turn names ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestion::$planItemKey}).
 * Such an item waits for the owner for the rest of the turn — for the "after the items before it" mark of a later
 * answer-text item it counts like an unanswered owner widget ({@see TaskPlanItem::isAwaitingOwnerOffer()},
 * {@see TaskPlanItems::buildCompactTailHintLines()}).
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanRenderTurnState extends ValueObject
{
    /** @var string[] The keys, as the plan render shows them, of the items an open askOwner question of this turn names. */
    public array $itemKeysWaitingForOwnerAnswer = [];

    /**
     * The state of `$turnMessages`: the plan item key of the newest COMPLETED askOwner call since the newest owner-authored
     * inbound of the turn (the window of {@see AIConversationTurnMessages::getNewestCompletedToolCallNamedSinceOwnerInbound()};
     * a refused or repeated call is no open question); with `$beforeMessageIndex` only a call on a row below it (the rebuild
     * of a recorded call). Null when no such call names an item — the render is then unchanged.
     */
    public static function ofTurn(AIConversationTurnMessages $turnMessages, ?int $beforeMessageIndex = null): ?static
    {
        $planItemKey = null;
        foreach ($turnMessages->getRows() as $position => $message) {
            if ($position > 0 && $message->isOwnerAuthoredInbound()) {
                $planItemKey = null;
                continue;
            }
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall === null || $toolCall->name !== AskOwnerTool::NAME || $toolCall->status !== AIConversationMessage::STATUS_COMPLETED
                || ($beforeMessageIndex !== null && ($message->indexInConversation ?? null) !== null && $message->indexInConversation >= $beforeMessageIndex)
            ) {
                continue;
            }
            $planItemKey = AskOwnerTool::checkArguments($toolCall->arguments)->question?->planItemKey;
        }
        return $planItemKey !== null ? static::forItemKeysWaitingForOwnerAnswer($planItemKey) : null;
    }

    public static function forItemKeysWaitingForOwnerAnswer(string ...$itemKeys): static
    {
        $taskPlanRenderTurnState = new static();
        $taskPlanRenderTurnState->itemKeysWaitingForOwnerAnswer = array_values($itemKeys);
        return $taskPlanRenderTurnState;
    }

    /**
     * Whether the node at `$taskPlanItemAddress` waits for the owner's answer to a question of this turn: the question
     * names the node or one of its ancestors.
     */
    public function isAddressWaitingForOwnerAnswer(TaskPlanItemAddress $taskPlanItemAddress): bool
    {
        if (in_array($taskPlanItemAddress->key, $this->itemKeysWaitingForOwnerAnswer, true)) {
            return true;
        }
        foreach ($taskPlanItemAddress->ancestors as $ancestorItem) {
            if ($ancestorItem->key !== null && in_array($ancestorItem->key, $this->itemKeysWaitingForOwnerAnswer, true)) {
                return true;
            }
        }
        return false;
    }
}
