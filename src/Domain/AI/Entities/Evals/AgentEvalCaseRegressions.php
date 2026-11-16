<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The cases whose model calls grew against the baseline run ({@see AgentEvalCaseRegression}), the largest growth first
 * once {@see sortByGrowth()} ran.
 *
 * @property AgentEvalCaseRegression[] $elements
 * @method AgentEvalCaseRegression[] getElements()
 * @method AgentEvalCaseRegression|null first()
 * @method AgentEvalCaseRegression|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRegressions extends ObjectSet
{
    /** Orders the cases by their growth in model calls, the largest first; cases of equal growth keep their order. */
    public function sortByGrowth(): void
    {
        $this->sort(static fn (AgentEvalCaseRegression $left, AgentEvalCaseRegression $right): int => $right->getModelCallGrowth() <=> $left->getModelCallGrowth());
    }

    /**
     * Every regression as a comparison stores it.
     *
     * @return array
     */
    public function toArrays(): array
    {
        return array_map(static fn (AgentEvalCaseRegression $agentEvalCaseRegression): array => $agentEvalCaseRegression->toArray(), $this->getElements());
    }
}
