<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What got SLOWER or more expensive than in a baseline run: the performance figures that grew by more than the
 * tolerance and by more than their noise floor ({@see BASELINE_COMPARED_METRICS}), the figures that shrank the same
 * way, and the cases whose model calls grew. Correctness and performance are separate axes: a run can pass every case
 * and still be a regression.
 *
 * Like is compared with like: both roll-ups are taken over the cases both runs measured (rows with a performance block,
 * matched by case id). The latency of a live model varies from run to run, so the per-case list reads the model calls,
 * the one stable number. A run that shares no measured case with the baseline is not comparable and gives no verdict.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalBaselineComparison extends ValueObject
{
    /** @var float[] The figures a comparison reads, and the smallest absolute growth that counts (noise floor) */
    public const array BASELINE_COMPARED_METRICS = [
        'avgModelCallsPerCase' => 0.3,
        'avgOwnerTurnsPerCase' => 0.3,
        'avgSecondsPerOwnerTurn' => 2.0,
        'avgAgentSecondsPerCase' => 3.0,
        'avgTotalTokensPerCase' => 1500.0,
        'avgCostPerCaseUsd' => 0.0005,
    ];

    /** @var float The percent a figure may grow over the baseline before it counts as a regression, by default */
    public const float DEFAULT_TOLERANCE_PERCENT = 15.0;

    /** @var int A case counts as a per-case regression when its model calls grew by the tolerance AND by at least this many */
    public const int PER_CASE_MINIMUM_MODEL_CALL_GROWTH = 2;

    /** @var bool Whether the runs share a measured case */
    public bool $comparable = false;

    /** @var float The tolerance in percent */
    public float $tolerancePercent = self::DEFAULT_TOLERANCE_PERCENT;

    /** @var int The measured cases both runs share */
    public int $commonCases = 0;

    /** @var AgentEvalMetricChanges The figures that grew beyond the tolerance and the noise floor */
    public AgentEvalMetricChanges $regressions;

    /** @var AgentEvalMetricChanges The figures that shrank beyond the tolerance and the noise floor */
    public AgentEvalMetricChanges $improvements;

    /** @var AgentEvalCaseRegressions The cases whose model calls grew, the largest growth first */
    public AgentEvalCaseRegressions $caseRegressions;

    /**
     * The comparison of the rows `$currentRows` of a run with the rows `$baselineRows` of its baseline run.
     *
     * @param AgentEvalCaseRows $currentRows the rows of this run
     * @param AgentEvalCaseRows $baselineRows the rows of the baseline run
     * @param float $tolerancePercent the percent a figure may grow before it counts
     * @return static
     */
    public static function compareCaseRows(AgentEvalCaseRows $currentRows, AgentEvalCaseRows $baselineRows, float $tolerancePercent = self::DEFAULT_TOLERANCE_PERCENT): static
    {
        $agentEvalBaselineComparison = static::newInstance();
        $agentEvalBaselineComparison->tolerancePercent = $tolerancePercent;
        $agentEvalBaselineComparison->regressions = AgentEvalMetricChanges::newInstance();
        $agentEvalBaselineComparison->improvements = AgentEvalMetricChanges::newInstance();
        $agentEvalBaselineComparison->caseRegressions = AgentEvalCaseRegressions::newInstance();
        $baselineRowsByCase = [];
        foreach ($baselineRows->buildMeasuredRows()->getElements() as $baselineRow) {
            $baselineRowsByCase[$baselineRow->getCaseId()] = $baselineRow;
        }
        $currentCommonRows = AgentEvalCaseRows::newInstance();
        $baselineCommonRows = AgentEvalCaseRows::newInstance();
        foreach ($currentRows->getElements() as $currentRow) {
            $caseId = $currentRow->getCaseId();
            if ($caseId === '' || !$currentRow->hasPerformance() || !isset($baselineRowsByCase[$caseId])) {
                continue;
            }
            $currentCommonRows->add($currentRow);
            // a copy: two rows of this run with the same case id count the baseline row twice, as they count themselves
            $baselineCommonRow = AgentEvalCaseRow::fromArray($baselineRowsByCase[$caseId]->toArray());
            $baselineCommonRows->add($baselineCommonRow);
            $agentEvalBaselineComparison->addCaseRegressionWhenGrown($currentRow, $baselineRowsByCase[$caseId]);
        }
        $agentEvalBaselineComparison->caseRegressions->sortByGrowth();
        if ($currentCommonRows->count() === 0) {
            return $agentEvalBaselineComparison;
        }
        $agentEvalBaselineComparison->comparable = true;
        $agentEvalBaselineComparison->commonCases = $currentCommonRows->count();
        $agentEvalBaselineComparison->compareRollUps(
            AgentEvalPerformanceRollUp::fromCaseRows($currentCommonRows),
            AgentEvalPerformanceRollUp::fromCaseRows($baselineCommonRows)
        );
        return $agentEvalBaselineComparison;
    }

    /**
     * Adds the case of `$currentRow` to the case regressions when its model calls grew by at least
     * {@see PER_CASE_MINIMUM_MODEL_CALL_GROWTH} and by more than the tolerance.
     */
    protected function addCaseRegressionWhenGrown(AgentEvalCaseRow $currentRow, AgentEvalCaseRow $baselineRow): void
    {
        $agentEvalCaseRegression = AgentEvalCaseRegression::fromRows($currentRow, $baselineRow);
        if ($agentEvalCaseRegression->getModelCallGrowth() >= static::PER_CASE_MINIMUM_MODEL_CALL_GROWTH
            && $agentEvalCaseRegression->modelCalls > $agentEvalCaseRegression->baselineModelCalls * (1 + $this->tolerancePercent / 100)
        ) {
            $this->caseRegressions->add($agentEvalCaseRegression);
        }
    }

    /** Compares every figure of {@see BASELINE_COMPARED_METRICS} whose baseline value is above 0. */
    protected function compareRollUps(AgentEvalPerformanceRollUp $currentRollUp, AgentEvalPerformanceRollUp $baselineRollUp): void
    {
        foreach (static::BASELINE_COMPARED_METRICS as $metric => $noiseFloor) {
            $baselineValue = $baselineRollUp->getFigure($metric);
            $currentValue = $currentRollUp->getFigure($metric);
            if ($baselineValue <= 0.0) {
                continue;
            }
            $agentEvalMetricChange = AgentEvalMetricChange::forValues($metric, $baselineValue, $currentValue);
            if ($agentEvalMetricChange->changePercent > $this->tolerancePercent && $agentEvalMetricChange->getChange() > $noiseFloor) {
                $this->regressions->add($agentEvalMetricChange);
            } elseif ($agentEvalMetricChange->changePercent < -$this->tolerancePercent && -$agentEvalMetricChange->getChange() > $noiseFloor) {
                $this->improvements->add($agentEvalMetricChange);
            }
        }
    }

    /** Whether a figure grew beyond the tolerance and its noise floor. */
    public function hasRegressions(): bool
    {
        return $this->regressions->count() > 0;
    }

    /**
     * The comparison as a suite record stores it (`runs.<run>.baselineComparison` of the stored document of
     * `<suite run>/_suite.json`). A comparison that is not comparable lists nothing.
     *
     * @return array{comparable: bool, tolerancePercent: float, commonCases: int, regressions: array, improvements: array, caseRegressions: array}
     */
    public function toArray(): array
    {
        return [
            'comparable' => $this->comparable,
            'tolerancePercent' => $this->tolerancePercent,
            'commonCases' => $this->commonCases,
            'regressions' => $this->comparable ? $this->regressions->toArrays() : [],
            'improvements' => $this->comparable ? $this->improvements->toArrays() : [],
            'caseRegressions' => $this->comparable ? $this->caseRegressions->toArrays() : [],
        ];
    }
}
