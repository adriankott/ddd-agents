<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The size of the task plans over the case rows that carried one (`taskPlanSize`): their number, and per figure
 * (nodes, depth, tail tokens max per call and sum per case) the maximum and the median. A median of an even number of
 * values is the mean of the two middle values (a whole number stays an integer).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalTaskPlanSizeRollUp extends ValueObject
{
    /** @var string[] The figures of a plan size, in the order the roll-up stores them */
    public const array FIGURES = ['nodes', 'maxDepth', 'tailTokensMax', 'tailTokensSum'];

    /** @var int The rows that carried a plan size */
    public int $cases = 0;

    /** @var int The most nodes of a plan */
    public int $nodesMax = 0;

    /** @var int|float The median of the nodes */
    public int|float $nodesMedian = 0;

    /** @var int The deepest nesting of a plan */
    public int $maxDepthMax = 0;

    /** @var int|float The median of the deepest nesting */
    public int|float $maxDepthMedian = 0;

    /** @var int The largest plan tail one model call carried */
    public int $tailTokensMaxMax = 0;

    /** @var int|float The median of the largest plan tail per case */
    public int|float $tailTokensMaxMedian = 0;

    /** @var int The largest sum of plan tails of one case */
    public int $tailTokensSumMax = 0;

    /** @var int|float The median of the sums of plan tails */
    public int|float $tailTokensSumMedian = 0;

    /** The roll-up of the rows `$agentEvalCaseRows` that carry a `taskPlanSize` block; null when no row carries one. */
    public static function fromCaseRows(AgentEvalCaseRows $agentEvalCaseRows): ?static
    {
        $taskPlanSizes = [];
        foreach ($agentEvalCaseRows->getElements() as $agentEvalCaseRow) {
            if (is_array($agentEvalCaseRow->getValue('taskPlanSize'))) {
                $taskPlanSizes[] = $agentEvalCaseRow->getValue('taskPlanSize');
            }
        }
        if ($taskPlanSizes === []) {
            return null;
        }
        $agentEvalTaskPlanSizeRollUp = static::newInstance();
        $agentEvalTaskPlanSizeRollUp->cases = count($taskPlanSizes);
        foreach (static::FIGURES as $figure) {
            $values = array_map('intval', array_column($taskPlanSizes, $figure));
            sort($values);
            $middle = intdiv(count($values), 2);
            $agentEvalTaskPlanSizeRollUp->{$figure . 'Max'} = max($values);
            $agentEvalTaskPlanSizeRollUp->{$figure . 'Median'} = count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
        }
        return $agentEvalTaskPlanSizeRollUp;
    }

    /**
     * The roll-up as a tools summary stores it under `metrics.taskPlanSize`: the stored document of
     * `<run>.json` / `<run>/_summary.json`.
     *
     * @return array{cases: int, nodesMax: int, nodesMedian: int|float, maxDepthMax: int, maxDepthMedian: int|float, tailTokensMaxMax: int, tailTokensMaxMedian: int|float, tailTokensSumMax: int, tailTokensSumMedian: int|float}
     */
    public function toArray(): array
    {
        return [
            'cases' => $this->cases,
            'nodesMax' => $this->nodesMax,
            'nodesMedian' => $this->nodesMedian,
            'maxDepthMax' => $this->maxDepthMax,
            'maxDepthMedian' => $this->maxDepthMedian,
            'tailTokensMaxMax' => $this->tailTokensMaxMax,
            'tailTokensMaxMedian' => $this->tailTokensMaxMedian,
            'tailTokensSumMax' => $this->tailTokensSumMax,
            'tailTokensSumMedian' => $this->tailTokensSumMedian,
        ];
    }
}
