<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One performance figure of a run against a baseline run ({@see AgentEvalBaselineComparison}): the figure, its value in
 * the baseline and in this run, and the change in percent of the baseline (one decimal).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalMetricChange extends ValueObject
{
    /** @var string The key of the figure in {@see AgentEvalPerformanceRollUp::toArray()} */
    public string $metric = '';

    /** @var float The figure in the baseline run */
    public float $baselineValue = 0.0;

    /** @var float The figure in this run */
    public float $currentValue = 0.0;

    /** @var float The change in percent of the baseline value, one decimal */
    public float $changePercent = 0.0;

    /** The change of `$metric` from `$baselineValue` (above 0) to `$currentValue`. */
    public static function forValues(string $metric, float $baselineValue, float $currentValue): static
    {
        $agentEvalMetricChange = static::newInstance();
        $agentEvalMetricChange->metric = $metric;
        $agentEvalMetricChange->baselineValue = $baselineValue;
        $agentEvalMetricChange->currentValue = $currentValue;
        $agentEvalMetricChange->changePercent = round(($currentValue - $baselineValue) / $baselineValue * 100, 1);
        return $agentEvalMetricChange;
    }

    /** The absolute change: this run's value minus the baseline's. */
    public function getChange(): float
    {
        return $this->currentValue - $this->baselineValue;
    }

    /**
     * The change as a comparison stores it.
     *
     * @return array{metric: string, baseline: float, current: float, changePercent: float}
     */
    public function toArray(): array
    {
        return ['metric' => $this->metric, 'baseline' => $this->baselineValue, 'current' => $this->currentValue, 'changePercent' => $this->changePercent];
    }
}
