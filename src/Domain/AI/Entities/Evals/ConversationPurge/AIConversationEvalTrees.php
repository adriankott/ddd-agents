<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The conversation trees one batch of the evaluation purge deletes ({@see AIConversationEvalTree}), in the order they
 * were checked.
 *
 * @property AIConversationEvalTree[] $elements
 * @method AIConversationEvalTree[] getElements()
 * @method AIConversationEvalTree|null first()
 * @method AIConversationEvalTree|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AIConversationEvalTrees extends ObjectSet
{
    /** @return int[] the root of every tree, in the order of the set */
    public function getRootConversationIds(): array
    {
        $rootConversationIds = [];
        foreach ($this->getElements() as $tree) {
            $rootConversationIds[] = $tree->rootConversationId;
        }
        return $rootConversationIds;
    }

    /** @return int[] every conversation of every tree, each once, in the order of the trees */
    public function getConversationIds(): array
    {
        $conversationIds = [];
        foreach ($this->getElements() as $tree) {
            $conversationIds = array_merge($conversationIds, $tree->conversationIds);
        }
        return array_values(array_unique($conversationIds));
    }
}
