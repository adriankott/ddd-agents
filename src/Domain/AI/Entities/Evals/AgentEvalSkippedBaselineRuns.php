<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The former suite runs the choice of the latest baseline skipped ({@see AgentEvalSkippedBaselineRun}), newest first.
 *
 * @property AgentEvalSkippedBaselineRun[] $elements
 * @method AgentEvalSkippedBaselineRun[] getElements()
 * @method AgentEvalSkippedBaselineRun|null first()
 * @method AgentEvalSkippedBaselineRun|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalSkippedBaselineRuns extends ObjectSet
{
    /**
     * @return array the list of `{directory, commonCases}` entries
     */
    public function toArrays(): array
    {
        return array_map(static fn (AgentEvalSkippedBaselineRun $agentEvalSkippedBaselineRun): array => $agentEvalSkippedBaselineRun->toArray(), $this->getElements());
    }
}
