<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The case rows of a suite run per run ({@see AgentEvalCaseRowsOfRun}), the runs in the order their first row came
 * in, the rows of a run in the order they came in.
 *
 * @property AgentEvalCaseRowsOfRun[] $elements
 * @method AgentEvalCaseRowsOfRun[] getElements()
 * @method AgentEvalCaseRowsOfRun|null first()
 * @method AgentEvalCaseRowsOfRun|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRowsByRun extends ObjectSet
{
    /** Adds the row of a finished task to the rows of its run (a run's first row adds the run). */
    public function addRowOfTask(AgentEvalSuiteTask $agentEvalSuiteTask, AgentEvalCaseRow $agentEvalCaseRow): void
    {
        $agentEvalCaseRowsOfRun = $this->getRowsOfRunKey($agentEvalSuiteTask->getRunKey());
        if ($agentEvalCaseRowsOfRun === null) {
            $agentEvalCaseRowsOfRun = AgentEvalCaseRowsOfRun::forTask($agentEvalSuiteTask);
            $addedCaseRowsOfRun = $agentEvalCaseRowsOfRun;
            $this->add($addedCaseRowsOfRun);
        }
        $agentEvalCaseRowsOfRun->caseRows->add($agentEvalCaseRow);
    }

    /** The rows of the run `$runKey`; null when no row of it came in. */
    public function getRowsOfRunKey(string $runKey): ?AgentEvalCaseRowsOfRun
    {
        return $this->getByUniqueKey(AgentEvalCaseRowsOfRun::uniqueKeyOfRunKey($runKey));
    }

    /** Every row of every run in one set: the runs in their order, the rows of each run in theirs (the stored run). */
    public function buildAllRows(): AgentEvalCaseRows
    {
        $allRows = AgentEvalCaseRows::newInstance();
        foreach ($this->getElements() as $agentEvalCaseRowsOfRun) {
            foreach ($agentEvalCaseRowsOfRun->caseRows->getElements() as $agentEvalCaseRow) {
                $allRows->add($agentEvalCaseRow);
            }
        }
        return $allRows;
    }

    /** The rows over every run. */
    public function countRows(): int
    {
        return (int)array_sum(array_map(static fn (AgentEvalCaseRowsOfRun $agentEvalCaseRowsOfRun): int => $agentEvalCaseRowsOfRun->caseRows->count(), $this->getElements()));
    }
}
