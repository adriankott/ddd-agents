<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The reports of several runs ({@see AIConversationRunReport}), one per conversation, in the order they were added.
 *
 * @property AIConversationRunReport[] $elements
 * @method AIConversationRunReport[] getElements()
 * @method AIConversationRunReport|null first()
 * @method AIConversationRunReport|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AIConversationRunReports extends ObjectSet
{
    /**
     * @param int $aiConversationId
     * @return AIConversationRunReport|null the report of that conversation
     */
    public function getByConversationId(int $aiConversationId): ?AIConversationRunReport
    {
        return $this->getByUniqueKey(AIConversationRunReport::uniqueKeyForConversationId($aiConversationId));
    }
}
