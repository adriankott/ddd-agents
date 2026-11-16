<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The conversations of one tree as the evaluation purge read them ({@see AIConversationEvalTreeConversation}), in the
 * order they were read; {@see AIConversationEvalTree::fromConversations()} decides on them.
 *
 * @property AIConversationEvalTreeConversation[] $elements
 * @method AIConversationEvalTreeConversation[] getElements()
 * @method AIConversationEvalTreeConversation|null first()
 * @method AIConversationEvalTreeConversation|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AIConversationEvalTreeConversations extends ObjectSet
{
    /** @return int[] the ids of the conversations, in the order of the set */
    public function getConversationIds(): array
    {
        $conversationIds = [];
        foreach ($this->getElements() as $treeConversation) {
            $conversationIds[] = $treeConversation->id;
        }
        return $conversationIds;
    }
}
