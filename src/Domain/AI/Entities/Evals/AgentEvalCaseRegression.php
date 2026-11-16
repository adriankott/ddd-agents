<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A case whose model calls grew against the baseline run ({@see AgentEvalBaselineComparison}): its model calls and
 * agent seconds in both runs.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRegression extends ValueObject
{
    /** @var string The case id */
    public string $caseId = '';

    /** @var int The model calls of the case in the baseline run */
    public int $baselineModelCalls = 0;

    /** @var int The model calls of the case in this run */
    public int $modelCalls = 0;

    /** @var float The agent seconds of the case in the baseline run */
    public float $baselineAgentSeconds = 0.0;

    /** @var float The agent seconds of the case in this run */
    public float $agentSeconds = 0.0;

    /** The regression of the case of `$currentRow` against `$baselineRow` (both measured rows of one case). */
    public static function fromRows(AgentEvalCaseRow $currentRow, AgentEvalCaseRow $baselineRow): static
    {
        $agentEvalCaseRegression = static::newInstance();
        $agentEvalCaseRegression->caseId = $currentRow->getCaseId();
        $agentEvalCaseRegression->baselineModelCalls = (int)$baselineRow->getPerformanceNumber('modelCalls');
        $agentEvalCaseRegression->modelCalls = (int)$currentRow->getPerformanceNumber('modelCalls');
        $agentEvalCaseRegression->baselineAgentSeconds = $baselineRow->getPerformanceNumber('agentSeconds');
        $agentEvalCaseRegression->agentSeconds = $currentRow->getPerformanceNumber('agentSeconds');
        return $agentEvalCaseRegression;
    }

    /** How many model calls the case needed more than in the baseline. */
    public function getModelCallGrowth(): int
    {
        return $this->modelCalls - $this->baselineModelCalls;
    }

    /**
     * The regression as a comparison stores it.
     *
     * @return array{case: string, baselineModelCalls: int, modelCalls: int, baselineAgentSeconds: float, agentSeconds: float}
     */
    public function toArray(): array
    {
        return [
            'case' => $this->caseId,
            'baselineModelCalls' => $this->baselineModelCalls,
            'modelCalls' => $this->modelCalls,
            'baselineAgentSeconds' => $this->baselineAgentSeconds,
            'agentSeconds' => $this->agentSeconds,
        ];
    }
}
