<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the evaluation harness itself spent beside the agent under evaluation: the judge's verdict calls and the
 * driver's simulated owner messages, in USD. The agent's own cost (`costUsd` of a row) never includes them.
 *
 * Counted per judged run by {@see \DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService::callModel()} from the cost
 * the provider reported for each harness call (a call without a reported cost counts 0), stamped on the judged row as
 * `judgeCostUsd` / `driverCostUsd` and summed by the run summary (`metrics.harnessCosts`).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalHarnessCosts extends ValueObject
{
    /** @var float The judge's calls in USD */
    public float $judgeCostUsd = 0.0;

    /** @var float The driver's calls in USD */
    public float $driverCostUsd = 0.0;

    /** Costs summed from the judged rows of a run (`judgeCostUsd`, `driverCostUsd`), six decimals. */
    public static function fromCaseRows(AgentEvalCaseRows $agentEvalCaseRows): static
    {
        $agentEvalHarnessCosts = static::newInstance();
        $agentEvalHarnessCosts->judgeCostUsd = round($agentEvalCaseRows->sumNumber('judgeCostUsd'), 6);
        $agentEvalHarnessCosts->driverCostUsd = round($agentEvalCaseRows->sumNumber('driverCostUsd'), 6);
        return $agentEvalHarnessCosts;
    }

    /**
     * Adds the cost of one harness call booked under `$aiUsageScope`: the judge's scope to the judge, the simulated
     * owner's to the driver; another scope is left out.
     */
    public function addCostOfUsageScope(string $aiUsageScope, float $costUsd): void
    {
        if ($aiUsageScope === AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION) {
            $this->judgeCostUsd += $costUsd;
        } elseif ($aiUsageScope === AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER) {
            $this->driverCostUsd += $costUsd;
        }
    }

    /** The judge's and the driver's cost together. */
    public function getTotalCostUsd(): float
    {
        return $this->judgeCostUsd + $this->driverCostUsd;
    }

    /** Whether any harness call reported a cost. */
    public function hasCost(): bool
    {
        return $this->getTotalCostUsd() > 0.0;
    }

    /**
     * The stored form under `metrics.harnessCosts`.
     *
     * @return array{judgeCostUsd: float, driverCostUsd: float, totalCostUsd: float}
     */
    public function toArray(): array
    {
        return [
            'judgeCostUsd' => round($this->judgeCostUsd, 6),
            'driverCostUsd' => round($this->driverCostUsd, 6),
            'totalCostUsd' => round($this->getTotalCostUsd(), 6),
        ];
    }
}
