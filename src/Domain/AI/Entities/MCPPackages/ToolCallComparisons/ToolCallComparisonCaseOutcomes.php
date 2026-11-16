<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The outcomes of the cases of one comparison recording ({@see ToolCallComparisonCaseOutcome}), one per case id, in
 * script order. A case recorded again replaces its earlier outcome.
 *
 * @property ToolCallComparisonCaseOutcome[] $elements
 * @method ToolCallComparisonCaseOutcome[] getElements()
 * @method ToolCallComparisonCaseOutcome|null first()
 * @method ToolCallComparisonCaseOutcome|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonCaseOutcomes extends ObjectSet
{
    /** The outcome of the case `$caseId`; null when the set holds none. */
    public function findOutcomeOfCase(string $caseId): ?ToolCallComparisonCaseOutcome
    {
        return $this->getByUniqueKey(ToolCallComparisonCaseOutcome::buildUniqueKeyOfCase($caseId));
    }

    /**
     * The stored document of every outcome, in order ({@see ToolCallComparisonCaseOutcome::toArray()}).
     *
     * @return array
     */
    public function toArray(): array
    {
        $storedOutcomes = [];
        foreach ($this->getElements() as $toolCallComparisonCaseOutcome) {
            $storedOutcomes[] = $toolCallComparisonCaseOutcome->toArray();
        }
        return $storedOutcomes;
    }
}
