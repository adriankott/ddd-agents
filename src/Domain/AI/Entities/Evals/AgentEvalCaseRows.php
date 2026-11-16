<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The result rows of the cases of a run ({@see AgentEvalCaseRow}), in the order the cases finished. Each row is held
 * under the key of its own object: two rows of one case (repetitions) both stay.
 *
 * Besides the counts a run summary and a suite record read, the set rolls up what the tools evaluation
 * writes beside the performance: the repetitions per case, the size of the task plans, the plan-dynamics and the
 * skill-preload figures of the decision layer.
 *
 * @property AgentEvalCaseRow[] $elements
 * @method AgentEvalCaseRow[] getElements()
 * @method AgentEvalCaseRow|null first()
 * @method AgentEvalCaseRow|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseRows extends ObjectSet
{
    /**
     * The rows of the decoded list `$rowArrays` (a run summary's `cases`); an entry that is no JSON object is skipped.
     *
     * @param array $rowArrays the decoded rows
     * @return static
     */
    public static function fromRowArrays(array $rowArrays): static
    {
        $agentEvalCaseRows = static::newInstance();
        foreach ($rowArrays as $rowFields) {
            if (is_array($rowFields)) {
                $agentEvalCaseRow = AgentEvalCaseRow::fromArray($rowFields);
                $agentEvalCaseRows->add($agentEvalCaseRow);
            }
        }
        return $agentEvalCaseRows;
    }

    /**
     * A new set with the rows of `$agentEvalCaseRows`, in their order.
     *
     * @param AgentEvalCaseRow ...$agentEvalCaseRows
     * @return static
     */
    public static function fromRows(AgentEvalCaseRow ...$agentEvalCaseRows): static
    {
        $rowsSet = static::newInstance();
        foreach ($agentEvalCaseRows as $agentEvalCaseRow) {
            $rowsSet->add($agentEvalCaseRow);
        }
        return $rowsSet;
    }

    /**
     * Every row as it was built and stamped: `cases` of the stored document of a loose tools run `<run>.json` and of a
     * child's result file `.result-<suite>-<caseId>.json`.
     *
     * @return array
     */
    public function toArrays(): array
    {
        return array_map(static fn (AgentEvalCaseRow $agentEvalCaseRow): array => $agentEvalCaseRow->toArray(), $this->getElements());
    }

    /**
     * Every row without its message trace: `cases` of the stored document of `<run>/_summary.json` and
     * `<suite run>/<model>-<flavor>/_summary.json`.
     *
     * @return array
     */
    public function toArraysWithoutMessages(): array
    {
        return array_map(static fn (AgentEvalCaseRow $agentEvalCaseRow): array => $agentEvalCaseRow->toArrayWithoutMessages(), $this->getElements());
    }

    /** The rows of the suite `$suite`, in their order. */
    public function buildRowsOfSuite(string $suite): static
    {
        return static::fromRows(...array_values(array_filter(
            $this->getElements(),
            static fn (AgentEvalCaseRow $agentEvalCaseRow): bool => $agentEvalCaseRow->getSuite() === $suite
        )));
    }

    /** The rows that carry a `performance` block (a row whose run ended in an error carries none), in their order. */
    public function buildMeasuredRows(): static
    {
        return static::fromRows(...array_values(array_filter(
            $this->getElements(),
            static fn (AgentEvalCaseRow $agentEvalCaseRow): bool => $agentEvalCaseRow->hasPerformance()
        )));
    }

    /** The rows with a measured wall-clock `durationMs` (the rows a run's totals average over), in their order. */
    public function buildTimedRows(): static
    {
        return static::fromRows(...array_values(array_filter(
            $this->getElements(),
            static fn (AgentEvalCaseRow $agentEvalCaseRow): bool => $agentEvalCaseRow->hasDurationMs()
        )));
    }

    /**
     * The suites of the rows in the order they first occur (a row without a suite gives the empty suite).
     *
     * @return string[]
     */
    public function getSuitesInOrder(): array
    {
        $suites = [];
        foreach ($this->getElements() as $agentEvalCaseRow) {
            $suites[$agentEvalCaseRow->getSuite()] = true;
        }
        return array_map('strval', array_keys($suites));
    }

    /** The rows whose case passed. */
    public function countPassed(): int
    {
        return count(array_filter($this->getElements(), static fn (AgentEvalCaseRow $agentEvalCaseRow): bool => $agentEvalCaseRow->isPassed()));
    }

    /** The rows whose case does not apply to its agent space. */
    public function countNotApplicable(): int
    {
        return count(array_filter($this->getElements(), static fn (AgentEvalCaseRow $agentEvalCaseRow): bool => $agentEvalCaseRow->isNotApplicable()));
    }

    /** The rows that carry an error. */
    public function countErrors(): int
    {
        return count(array_filter($this->getElements(), static fn (AgentEvalCaseRow $agentEvalCaseRow): bool => $agentEvalCaseRow->hasError()));
    }

    /** The rows that count for the pass rate: every row but the not-applicable ones. */
    public function countApplicable(): int
    {
        return $this->count() - $this->countNotApplicable();
    }

    /** The sum of one top-level number over the rows (0 for a row without it). */
    public function sumNumber(string $key): float
    {
        return (float)array_sum(array_map(static fn (AgentEvalCaseRow $agentEvalCaseRow): float => $agentEvalCaseRow->getNumber($key), $this->getElements()));
    }

    /** The average of one top-level number over the rows, two decimals; 0 without a row. */
    public function averageNumber(string $key): float
    {
        return $this->count() === 0 ? 0.0 : round($this->sumNumber($key) / $this->count(), 2);
    }

    /** The process seconds of every row together. */
    public function sumProcessSeconds(): float
    {
        return (float)array_sum(array_map(static fn (AgentEvalCaseRow $agentEvalCaseRow): float => $agentEvalCaseRow->getProcessSeconds(), $this->getElements()));
    }

    /**
     * `suite:case — detail` of every not-applicable row.
     *
     * @return string[]
     */
    public function describeNotApplicableCases(): array
    {
        $descriptions = [];
        foreach ($this->getElements() as $agentEvalCaseRow) {
            if ($agentEvalCaseRow->isNotApplicable()) {
                $descriptions[] = $agentEvalCaseRow->describeNotApplicableCase();
            }
        }
        return $descriptions;
    }

    /**
     * `suite:case` of every row whose restore step was refused.
     *
     * @return string[]
     */
    public function describeRestoreFailedCases(): array
    {
        $descriptions = [];
        foreach ($this->getElements() as $agentEvalCaseRow) {
            if ($agentEvalCaseRow->isRestoreFailed()) {
                $descriptions[] = $agentEvalCaseRow->describeSuiteAndCase();
            }
        }
        return $descriptions;
    }

    /**
     * `suite:case` of every row that ran a second time after an infrastructure error.
     *
     * @return string[]
     */
    public function describeRetriedCases(): array
    {
        $descriptions = [];
        foreach ($this->getElements() as $agentEvalCaseRow) {
            if ($agentEvalCaseRow->wasRetried()) {
                $descriptions[] = $agentEvalCaseRow->describeSuiteAndCase();
            }
        }
        return $descriptions;
    }

    /**
     * `suite:case` of every failed row (not passed, not a not-applicable row).
     *
     * @return string[]
     */
    public function describeFailedCases(): array
    {
        $descriptions = [];
        foreach ($this->getElements() as $agentEvalCaseRow) {
            if ($agentEvalCaseRow->isFailed()) {
                $descriptions[] = $agentEvalCaseRow->describeSuiteAndCase();
            }
        }
        return $descriptions;
    }

    /**
     * Adds the case id of every measured row (a `performance` block and a case id) to `$measuredCaseIds` under the run
     * folder `$runFolderName`: the cases a baseline comparison can compare.
     */
    public function addMeasuredCaseIdsTo(AgentEvalCaseIdsByRunFolder $measuredCaseIds, string $runFolderName): void
    {
        foreach ($this->getElements() as $agentEvalCaseRow) {
            if ($agentEvalCaseRow->hasPerformance() && $agentEvalCaseRow->getCaseId() !== '') {
                $measuredCaseIds->addCaseId($runFolderName, $agentEvalCaseRow->getCaseId());
            }
        }
    }

    /**
     * Per case (first-seen order) how many runs it had, how many passed, how many ended in an error row (a child that
     * crashed or timed out) and the pass rate over the runs (three decimals) — the reliability of one prompt over
     * repeated runs. A not-applicable row is left out.
     */
    public function rollUpRepetitionsByCase(): AgentEvalCaseRepetitions
    {
        $agentEvalCaseRepetitions = AgentEvalCaseRepetitions::newInstance();
        foreach ($this->getElements() as $agentEvalCaseRow) {
            if (!$agentEvalCaseRow->isNotApplicable()) {
                $agentEvalCaseRepetitions->countRunOfRow($agentEvalCaseRow);
            }
        }
        return $agentEvalCaseRepetitions;
    }

    /**
     * The size of the task plans over the rows that carried one (`taskPlanSize`); null when no row carried a plan.
     */
    public function rollUpTaskPlanSize(): ?AgentEvalTaskPlanSizeRollUp
    {
        return AgentEvalTaskPlanSizeRollUp::fromCaseRows($this);
    }

    /**
     * The plan-dynamics figures over the rows that carry a `planDynamics` block; null when no row carries the block.
     *
     * @param string $planDynamicsArm the arm of the run (its name, or the default arm's)
     */
    public function rollUpPlanDynamics(string $planDynamicsArm): ?AgentEvalPlanDynamicsRollUp
    {
        $planRows = $this->buildRowsCarryingBlock('planDynamics');
        if ($planRows->count() === 0) {
            return null;
        }
        return AgentEvalPlanDynamicsRollUp::fromRows($planDynamicsArm, $planRows, $this->buildTimedRows());
    }

    /**
     * The skill-preload figures over every judged owner turn of the rows that carry a `skillPreload` block; null when
     * no row carries the block.
     *
     * @param string $skillPreloadArm the arm of the run (its name, or the default arm's)
     */
    public function rollUpSkillPreload(string $skillPreloadArm): ?AgentEvalSkillPreloadRollUp
    {
        $preloadRows = $this->buildRowsCarryingBlock('skillPreload');
        if ($preloadRows->count() === 0) {
            return null;
        }
        return AgentEvalSkillPreloadRollUp::fromRows($skillPreloadArm, $preloadRows);
    }

    /** The rows that carry the block `$blockKey` (a JSON object), in their order. */
    public function buildRowsCarryingBlock(string $blockKey): static
    {
        return static::fromRows(...array_values(array_filter(
            $this->getElements(),
            static fn (AgentEvalCaseRow $agentEvalCaseRow): bool => is_array($agentEvalCaseRow->getValue($blockKey))
        )));
    }

    /** How many rows carry `$value` under `$key` of their block `$blockKey`. */
    public function countRowsWithBlockValue(string $blockKey, string $key, string $value): int
    {
        return count(array_filter(
            $this->getElements(),
            static fn (AgentEvalCaseRow $agentEvalCaseRow): bool => (((array)$agentEvalCaseRow->getValue($blockKey))[$key] ?? null) === $value
        ));
    }
}
