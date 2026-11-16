<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The entries of a variants timeline ({@see AIConversationRunReportVariantsChange}) in the order of the messages and,
 * within one message, of the dimensions.
 *
 * @property AIConversationRunReportVariantsChange[] $elements
 * @method AIConversationRunReportVariantsChange[] getElements()
 * @method AIConversationRunReportVariantsChange|null first()
 * @method AIConversationRunReportVariantsChange|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportVariantsChanges extends ObjectSet
{
    /**
     * The entries under the keys the run report prints them with.
     *
     * @return array<int, array{at: int, dimension: string, from: string, to: string, sections: string, toolAfter: string}>
     */
    public function toArray(): array
    {
        $changeArrays = [];
        foreach ($this->getElements() as $variantsChange) {
            $changeArrays[] = $variantsChange->toArray();
        }
        return $changeArrays;
    }
}
