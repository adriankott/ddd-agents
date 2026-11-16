<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The summary of one run (one model in one flavor) over its case rows: the counts, the model, the flavor and the arms,
 * the totals of the timed rows (duration, tokens, cost, tool calls), the performance roll-up
 * ({@see AgentEvalPerformanceRollUp}) and the rows themselves.
 *
 * Two formats, each with the keys and the order of its stored file ({@see toArray()}):
 *
 *  - {@see FORMAT_SUITE} ({@see buildForSuite()}): the summary a parallel suite writes per run folder and the selection
 *    evaluation prints — `total`, `passed`, `errors`, `notApplicable`, `model`, `flavor`, `arms`, `metrics`, `cases`
 *    (without message traces);
 *  - {@see FORMAT_TOOLS} ({@see buildForToolsRun()}): the summary of a tools evaluation — `total`, `passed`,
 *    `notApplicable`, `model`, `flavor`, `arms`, `repeat`, `metrics` (with the task-plan size and, when measured, the
 *    repetitions, the plan-dynamics and the skill-preload figures), `cases`.
 *
 * `total` counts every row but the not-applicable ones. The totals read the rows with a measured `durationMs` only.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalRunSummary extends ValueObject
{
    /** @var string The format of a parallel suite's run summary */
    public const string FORMAT_SUITE = 'suite';

    /** @var string The format of a tools evaluation's summary */
    public const string FORMAT_TOOLS = 'tools';

    /** @var string One of the `FORMAT_*` constants */
    public string $format = self::FORMAT_SUITE;

    /** @var int The rows that count for the pass rate (every row but the not-applicable ones) */
    public int $total = 0;

    /** @var int The rows whose case passed */
    public int $passed = 0;

    /** @var int The rows that carry an error */
    public int $errors = 0;

    /** @var int The rows whose case does not apply to its agent space */
    public int $notApplicable = 0;

    /** @var string|null The model of the run */
    public ?string $modelName = null;

    /** @var string The flavor of the run */
    public string $flavor = AgentEvalSuiteTask::FLAVOR_CALL;

    /** @var string[] The arms of the run: option name → value */
    public array $arms = [];

    /** @var int How often every case ran (tools format only) */
    public int $repeat = 1;

    /** @var int The wall milliseconds of the timed rows */
    public int $totalDurationMs = 0;

    /** @var int The tokens of the timed rows */
    public int $totalTokens = 0;

    /** @var float The cost of the timed rows in USD, six decimals */
    public float $totalCostUsd = 0.0;

    /** @var float Milliseconds per timed row, two decimals */
    public float $avgDurationMs = 0.0;

    /** @var float Tokens per timed row, two decimals */
    public float $avgTotalTokens = 0.0;

    /**
     * @var float Cost per timed row in USD. The suite format rounds the average to six decimals. The tools format rounds
     * it to two decimals first ({@see AgentEvalCaseRows::averageNumber()}) and then to six: kept on purpose, because the
     * stored figure of every tools summary is this double-rounded value and a stored figure does not change.
     */
    public float $avgCostPerCaseUsd = 0.0;

    /** @var float Tool calls per timed row, two decimals */
    public float $avgToolCallCount = 0.0;

    /** @var float Work tool calls per timed row, two decimals */
    public float $avgWorkToolCallCount = 0.0;

    /** @var float|null The cost of the timed rows per passed case, six decimals; null without a pass */
    public ?float $costPerPassUsd = null;

    /** @var AgentEvalPerformanceRollUp The performance over the measured rows */
    public AgentEvalPerformanceRollUp $performanceRollUp;

    /** @var AgentEvalTaskPlanSizeRollUp|null The size of the task plans; tools format only, null without a plan */
    public ?AgentEvalTaskPlanSizeRollUp $taskPlanSizeRollUp = null;

    /** @var AgentEvalCaseRepetitions|null The repetitions per case; tools format with `repeat` above 1 only */
    public ?AgentEvalCaseRepetitions $repetitionsByCase = null;

    /** @var AgentEvalPlanDynamicsRollUp|null The plan-dynamics figures; tools format only, when measured */
    public ?AgentEvalPlanDynamicsRollUp $planDynamicsRollUp = null;

    /** @var AgentEvalSkillPreloadRollUp|null The skill-preload figures; tools format only, when measured */
    public ?AgentEvalSkillPreloadRollUp $skillPreloadRollUp = null;

    /** @var AgentEvalCaseRows The rows of the run */
    public AgentEvalCaseRows $caseRows;

    /**
     * The summary a parallel suite writes per run folder.
     *
     * @param AgentEvalCaseRows $agentEvalCaseRows the rows of the run
     * @param string|null $modelName
     * @param string $flavor
     * @param string[] $arms option name → value
     * @return static
     */
    public static function buildForSuite(AgentEvalCaseRows $agentEvalCaseRows, ?string $modelName, string $flavor, array $arms = []): static
    {
        $agentEvalRunSummary = static::buildCounts(static::FORMAT_SUITE, $agentEvalCaseRows, $modelName, $flavor, $arms);
        $timedRows = $agentEvalCaseRows->buildTimedRows();
        $agentEvalRunSummary->avgCostPerCaseUsd = $timedRows->count() === 0 ? 0.0 : round($timedRows->sumNumber('costUsd') / $timedRows->count(), 6);
        return $agentEvalRunSummary;
    }

    /**
     * The summary of a tools evaluation.
     *
     * @param AgentEvalCaseRows $agentEvalCaseRows the rows of the run
     * @param string|null $modelName
     * @param string $flavor
     * @param string[] $arms option name → value
     * @param int $repeat how often every case ran
     * @param string $planDynamicsArm the plan-dynamics arm of the run (its value, or the default arm's)
     * @param string $skillPreloadArm the skill-preload arm of the run (its value, or the default arm's)
     * @return static
     */
    public static function buildForToolsRun(
        AgentEvalCaseRows $agentEvalCaseRows,
        ?string $modelName,
        string $flavor,
        array $arms,
        int $repeat,
        string $planDynamicsArm,
        string $skillPreloadArm
    ): static {
        $agentEvalRunSummary = static::buildCounts(static::FORMAT_TOOLS, $agentEvalCaseRows, $modelName, $flavor, $arms);
        $agentEvalRunSummary->repeat = $repeat;
        $agentEvalRunSummary->avgCostPerCaseUsd = round($agentEvalCaseRows->buildTimedRows()->averageNumber('costUsd'), 6);
        $agentEvalRunSummary->taskPlanSizeRollUp = $agentEvalCaseRows->rollUpTaskPlanSize();
        $agentEvalRunSummary->repetitionsByCase = $repeat > 1 ? $agentEvalCaseRows->rollUpRepetitionsByCase() : null;
        $agentEvalRunSummary->planDynamicsRollUp = $agentEvalCaseRows->rollUpPlanDynamics($planDynamicsArm);
        $agentEvalRunSummary->skillPreloadRollUp = $agentEvalCaseRows->rollUpSkillPreload($skillPreloadArm);
        return $agentEvalRunSummary;
    }

    /**
     * The summary a run wrote, read back from its decoded file (a baseline run's summary): the rows of `cases`, the
     * model, the flavor and the arms; a summary that carries `repeat` is of the tools format. Every count and figure is
     * computed again from the stored rows by the format's builder — the stored `metrics` are not read, except the arm
     * names of the decision figures.
     *
     * @param array $summaryFields the decoded summary
     * @return static
     */
    public static function fromArray(array $summaryFields): static
    {
        $agentEvalCaseRows = AgentEvalCaseRows::fromRowArrays((array)($summaryFields['cases'] ?? []));
        $modelName = isset($summaryFields['model']) ? (string)$summaryFields['model'] : null;
        $flavor = (string)($summaryFields['flavor'] ?? AgentEvalSuiteTask::FLAVOR_CALL);
        $arms = array_map('strval', (array)($summaryFields['arms'] ?? []));
        if (!array_key_exists('repeat', $summaryFields)) {
            return static::buildForSuite($agentEvalCaseRows, $modelName, $flavor, $arms);
        }
        $metrics = (array)($summaryFields['metrics'] ?? []);
        return static::buildForToolsRun(
            $agentEvalCaseRows,
            $modelName,
            $flavor,
            $arms,
            (int)$summaryFields['repeat'],
            (string)(((array)($metrics['planDynamics'] ?? []))['arm'] ?? ''),
            (string)(((array)($metrics['skillPreload'] ?? []))['arm'] ?? '')
        );
    }

    /**
     * The counts and the totals both formats share.
     *
     * @param string $format
     * @param AgentEvalCaseRows $agentEvalCaseRows
     * @param string|null $modelName
     * @param string $flavor
     * @param string[] $arms
     * @return static
     */
    protected static function buildCounts(string $format, AgentEvalCaseRows $agentEvalCaseRows, ?string $modelName, string $flavor, array $arms): static
    {
        $agentEvalRunSummary = static::newInstance();
        $agentEvalRunSummary->format = $format;
        $agentEvalRunSummary->caseRows = $agentEvalCaseRows;
        $agentEvalRunSummary->total = $agentEvalCaseRows->countApplicable();
        $agentEvalRunSummary->passed = $agentEvalCaseRows->countPassed();
        $agentEvalRunSummary->errors = $agentEvalCaseRows->countErrors();
        $agentEvalRunSummary->notApplicable = $agentEvalCaseRows->countNotApplicable();
        $agentEvalRunSummary->modelName = $modelName;
        $agentEvalRunSummary->flavor = $flavor;
        $agentEvalRunSummary->arms = $arms;
        $timedRows = $agentEvalCaseRows->buildTimedRows();
        $agentEvalRunSummary->totalDurationMs = (int)$timedRows->sumNumber('durationMs');
        $agentEvalRunSummary->totalTokens = (int)$timedRows->sumNumber('totalTokens');
        $agentEvalRunSummary->totalCostUsd = round($timedRows->sumNumber('costUsd'), 6);
        $agentEvalRunSummary->avgDurationMs = $timedRows->averageNumber('durationMs');
        $agentEvalRunSummary->avgTotalTokens = $timedRows->averageNumber('totalTokens');
        $agentEvalRunSummary->avgToolCallCount = $timedRows->averageNumber('toolCallCount');
        $agentEvalRunSummary->avgWorkToolCallCount = $timedRows->averageNumber('workToolCallCount');
        $agentEvalRunSummary->costPerPassUsd = $agentEvalRunSummary->passed > 0 ? round($timedRows->sumNumber('costUsd') / $agentEvalRunSummary->passed, 6) : null;
        $agentEvalRunSummary->performanceRollUp = AgentEvalPerformanceRollUp::fromCaseRows($agentEvalCaseRows);
        return $agentEvalRunSummary;
    }

    /** Whether every case that counts passed (a run without such a case passes). */
    public function hasEveryCasePassed(): bool
    {
        return $this->passed === $this->total;
    }

    /**
     * The `metrics` block in the order of the summary's format: part of the stored document of every summary file.
     *
     * @return array
     */
    public function buildMetricsArray(): array
    {
        $metrics = [
            'totalDurationMs' => $this->totalDurationMs,
            'totalTokens' => $this->totalTokens,
            'totalCostUsd' => $this->totalCostUsd,
            'avgDurationMs' => $this->avgDurationMs,
            'avgTotalTokens' => $this->avgTotalTokens,
            'avgCostPerCaseUsd' => $this->avgCostPerCaseUsd,
            'avgToolCallCount' => $this->avgToolCallCount,
            'avgWorkToolCallCount' => $this->avgWorkToolCallCount,
            'costPerPassUsd' => $this->costPerPassUsd,
            'performance' => $this->performanceRollUp->toArray(),
        ];
        if ($this->format !== static::FORMAT_TOOLS) {
            return $metrics;
        }
        $metrics['taskPlanSize'] = $this->taskPlanSizeRollUp?->toArray();
        if ($this->repetitionsByCase !== null) {
            $metrics['repetitions'] = $this->repetitionsByCase->toArrays();
        }
        if ($this->planDynamicsRollUp !== null) {
            $metrics['planDynamics'] = $this->planDynamicsRollUp->toArray();
        }
        if ($this->skillPreloadRollUp !== null) {
            $metrics['skillPreload'] = $this->skillPreloadRollUp->toArray();
        }
        return $metrics;
    }

    /**
     * The summary as it is written: the stored document of `<suite run>/<model>-<flavor>/_summary.json` (suite format),
     * and of `<run>.json`, `<run>/_summary.json` and the child's result file `.result-<suite>-<caseId>.json` (tools
     * format). The suite format never carries the rows' message traces; the tools format carries them when
     * `$withMessageTraces` is true (its loose run file, its result file and its printed JSON), not in its summary file.
     *
     * @return array
     */
    public function toArray(bool $withMessageTraces = false): array
    {
        if ($this->format !== static::FORMAT_TOOLS) {
            return [
                'total' => $this->total,
                'passed' => $this->passed,
                'errors' => $this->errors,
                'notApplicable' => $this->notApplicable,
                'model' => $this->modelName,
                'flavor' => $this->flavor,
                'arms' => $this->arms,
                'metrics' => $this->buildMetricsArray(),
                'cases' => $this->caseRows->toArraysWithoutMessages(),
            ];
        }
        return [
            'total' => $this->total,
            'passed' => $this->passed,
            'notApplicable' => $this->notApplicable,
            'model' => $this->modelName,
            'flavor' => $this->flavor,
            'arms' => $this->arms,
            'repeat' => $this->repeat,
            'metrics' => $this->buildMetricsArray(),
            'cases' => $withMessageTraces ? $this->caseRows->toArrays() : $this->caseRows->toArraysWithoutMessages(),
        ];
    }
}
