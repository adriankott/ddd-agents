<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Changed performance figures of a run against its baseline ({@see AgentEvalMetricChange}): the regressions or the
 * improvements, in the order the comparison reads the figures.
 *
 * @property AgentEvalMetricChange[] $elements
 * @method AgentEvalMetricChange[] getElements()
 * @method AgentEvalMetricChange|null first()
 * @method AgentEvalMetricChange|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalMetricChanges extends ObjectSet
{
    /**
     * Every change as a comparison stores it.
     *
     * @return array
     */
    public function toArrays(): array
    {
        return array_map(static fn (AgentEvalMetricChange $agentEvalMetricChange): array => $agentEvalMetricChange->toArray(), $this->getElements());
    }

    /**
     * The figures that changed, in their order.
     *
     * @return string[]
     */
    public function getMetrics(): array
    {
        return array_map(static fn (AgentEvalMetricChange $agentEvalMetricChange): string => $agentEvalMetricChange->metric, $this->getElements());
    }
}
