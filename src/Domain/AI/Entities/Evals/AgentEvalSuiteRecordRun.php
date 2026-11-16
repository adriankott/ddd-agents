<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One run (one model in one flavor) of a suite record ({@see AgentEvalSuiteRecord}): its run summary
 * ({@see AgentEvalRunSummary}), the cases a reader looks at first (not applicable, restore refused, retried, failed),
 * the counts and the performance per suite, and the comparison with the baseline run when there is one.
 *
 * The unique key of a record run is its run key (`<model>|<flavor>`).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteRecordRun extends ValueObject
{
    /** @var string The run key `<model>|<flavor>` */
    public string $runKey = '';

    /** @var string The model as the run key names it (`default` for the conversation default) */
    public string $modelName = '';

    /** @var string The flavor */
    public string $flavor = '';

    /** @var AgentEvalRunSummary The run summary */
    public AgentEvalRunSummary $runSummary;

    /** @var AgentEvalBaselineComparison|null The comparison with the baseline run; null without a baseline summary */
    public ?AgentEvalBaselineComparison $baselineComparison = null;

    /** The record of the run `$runKey` (`<model>|<flavor>`) with its summary and its baseline comparison. */
    public static function forRun(string $runKey, AgentEvalRunSummary $agentEvalRunSummary, ?AgentEvalBaselineComparison $agentEvalBaselineComparison): static
    {
        $agentEvalSuiteRecordRun = static::newInstance();
        $agentEvalSuiteRecordRun->runKey = $runKey;
        $runKeyParts = explode('|', $runKey, 2);
        $agentEvalSuiteRecordRun->modelName = $runKeyParts[0];
        $agentEvalSuiteRecordRun->flavor = $runKeyParts[1] ?? '';
        $agentEvalSuiteRecordRun->runSummary = $agentEvalRunSummary;
        $agentEvalSuiteRecordRun->baselineComparison = $agentEvalBaselineComparison;
        return $agentEvalSuiteRecordRun;
    }

    /** Whether the run was compared with a baseline that shares a measured case. */
    public function isComparedWithBaseline(): bool
    {
        return $this->baselineComparison !== null && $this->baselineComparison->comparable;
    }

    /** Whether a performance figure regressed against the baseline. */
    public function hasPerformanceRegressions(): bool
    {
        return $this->baselineComparison !== null && $this->baselineComparison->hasRegressions();
    }

    /**
     * Per suite (in the order the suites first occur in the rows): the cases that count, the not-applicable cases, the
     * passed cases and the performance over the suite's rows.
     */
    public function buildFiguresBySuite(): AgentEvalFiguresBySuite
    {
        return AgentEvalFiguresBySuite::fromCaseRows($this->runSummary->caseRows);
    }

    /**
     * The run as a suite record stores it under `runs`: part of the stored document of `<suite run>/_suite.json`.
     *
     * @return array
     */
    public function toArray(): array
    {
        $agentEvalCaseRows = $this->runSummary->caseRows;
        return [
            'model' => $this->modelName,
            'flavor' => $this->flavor,
            'total' => $this->runSummary->total,
            'passed' => $this->runSummary->passed,
            'errors' => $this->runSummary->errors,
            'notApplicable' => $this->runSummary->notApplicable,
            'notApplicableCases' => $agentEvalCaseRows->describeNotApplicableCases(),
            'restoreFailedCases' => $agentEvalCaseRows->describeRestoreFailedCases(),
            'retriedCases' => $agentEvalCaseRows->describeRetriedCases(),
            'failedCases' => $agentEvalCaseRows->describeFailedCases(),
            'bySuite' => $this->buildFiguresBySuite()->toArraysBySuite(),
            'performance' => $this->runSummary->performanceRollUp->toArray(),
            'costPerPassUsd' => $this->runSummary->costPerPassUsd,
            'baselineComparison' => $this->baselineComparison?->toArray(),
        ];
    }

    /** The run key. */
    public function uniqueKey(): string
    {
        return self::class . '_' . $this->runKey;
    }
}
