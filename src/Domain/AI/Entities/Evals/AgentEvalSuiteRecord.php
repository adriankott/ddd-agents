<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The record of one parallel suite run: when it started, how long it took against the case time it ran, whether it
 * was stopped, its settings and its plan ({@see AgentEvalSuitePlanSummary}), the baseline it compared with, the two
 * verdicts, the counts, and its runs ({@see AgentEvalSuiteRecordRun}).
 *
 * Correctness and performance are two verdicts: the run passes when every case that counts passed and it was not
 * stopped; against a baseline it must not need more model calls, turns, seconds or money than before.
 *
 * {@see toArray()} gives the record with the keys and in the order of the stored record.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteRecord extends ValueObject
{
    /** @var string Every case that counts passed and the run was not stopped */
    public const string VERDICT_PASSED = 'PASSED';

    /** @var string A case failed or the run was stopped */
    public const string VERDICT_FAILED = 'FAILED';

    /** @var string No run was compared with a baseline that shares a measured case */
    public const string VERDICT_NOT_COMPARED = 'NOT_COMPARED';

    /** @var string A performance figure of a run regressed against the baseline */
    public const string VERDICT_REGRESSED = 'REGRESSED';

    /** @var string Compared with the baseline, nothing regressed */
    public const string VERDICT_OK = 'OK';

    /** @var int The second the run started (Unix time) */
    public int $startedAtTimestamp = 0;

    /** @var int The wall seconds of the run */
    public int $wallSeconds = 0;

    /** @var float The process seconds of every case together */
    public float $processSeconds = 0.0;

    /** @var bool Whether the run was stopped before its queue was empty */
    public bool $stopped = false;

    /** @var AgentEvalSuiteRecordSettings The settings of the run as the suite states them */
    public AgentEvalSuiteRecordSettings $settings;

    /** @var AgentEvalSuitePlanSummary The plan of the run */
    public AgentEvalSuitePlanSummary $planSummary;

    /** @var string|null The folder name of the baseline run; null without a baseline */
    public ?string $baselineDirectoryName = null;

    /** @var string|null The line that says which baseline was chosen and why; null without the request for one */
    public ?string $baselineChoiceText = null;

    /** @var AgentEvalSuiteRecordRuns The runs, one per model and flavor */
    public AgentEvalSuiteRecordRuns $runs;

    /**
     * The record of a finished suite run.
     *
     * @param int $startedAtTimestamp the second the run started (Unix time)
     * @param int $wallSeconds the wall seconds of the run
     * @param bool $stopped whether the run was stopped before its queue was empty
     * @param AgentEvalSuiteRecordSettings $agentEvalSuiteRecordSettings the run's settings as the suite states them
     * @param AgentEvalSuitePlanSummary $agentEvalSuitePlanSummary the plan
     * @param AgentEvalBaselineChoice|null $agentEvalBaselineChoice the baseline choice; null without the request for one
     * @param string|null $baselineChoiceText the line that describes the choice
     * @param AgentEvalSuiteRecordRuns $agentEvalSuiteRecordRuns the runs
     * @return static
     */
    public static function forFinishedRun(
        int $startedAtTimestamp,
        int $wallSeconds,
        bool $stopped,
        AgentEvalSuiteRecordSettings $agentEvalSuiteRecordSettings,
        AgentEvalSuitePlanSummary $agentEvalSuitePlanSummary,
        ?AgentEvalBaselineChoice $agentEvalBaselineChoice,
        ?string $baselineChoiceText,
        AgentEvalSuiteRecordRuns $agentEvalSuiteRecordRuns
    ): static {
        $agentEvalSuiteRecord = static::newInstance();
        $agentEvalSuiteRecord->startedAtTimestamp = $startedAtTimestamp;
        $agentEvalSuiteRecord->wallSeconds = $wallSeconds;
        $agentEvalSuiteRecord->stopped = $stopped;
        $agentEvalSuiteRecord->settings = $agentEvalSuiteRecordSettings;
        $agentEvalSuiteRecord->planSummary = $agentEvalSuitePlanSummary;
        $agentEvalSuiteRecord->baselineDirectoryName = $agentEvalBaselineChoice?->getDirectoryName();
        $agentEvalSuiteRecord->baselineChoiceText = $baselineChoiceText;
        $agentEvalSuiteRecord->runs = $agentEvalSuiteRecordRuns;
        foreach ($agentEvalSuiteRecordRuns->getElements() as $agentEvalSuiteRecordRun) {
            $agentEvalSuiteRecord->processSeconds += $agentEvalSuiteRecordRun->runSummary->caseRows->sumProcessSeconds();
        }
        return $agentEvalSuiteRecord;
    }

    /** The cases that count over every run. */
    public function getTotal(): int
    {
        return (int)array_sum(array_map(static fn (AgentEvalSuiteRecordRun $agentEvalSuiteRecordRun): int => $agentEvalSuiteRecordRun->runSummary->total, $this->runs->getElements()));
    }

    /** The passed cases over every run. */
    public function getPassed(): int
    {
        return (int)array_sum(array_map(static fn (AgentEvalSuiteRecordRun $agentEvalSuiteRecordRun): int => $agentEvalSuiteRecordRun->runSummary->passed, $this->runs->getElements()));
    }

    /** The not-applicable cases over every run. */
    public function getNotApplicable(): int
    {
        return (int)array_sum(array_map(static fn (AgentEvalSuiteRecordRun $agentEvalSuiteRecordRun): int => $agentEvalSuiteRecordRun->runSummary->notApplicable, $this->runs->getElements()));
    }

    /** {@see VERDICT_PASSED} when every case that counts passed and the run was not stopped, else {@see VERDICT_FAILED}. */
    public function getCorrectnessVerdict(): string
    {
        return $this->getPassed() === $this->getTotal() && !$this->stopped ? static::VERDICT_PASSED : static::VERDICT_FAILED;
    }

    /** {@see VERDICT_NOT_COMPARED} without a comparable baseline, else {@see VERDICT_REGRESSED} or {@see VERDICT_OK}. */
    public function getPerformanceVerdict(): string
    {
        $compared = false;
        $regressed = false;
        foreach ($this->runs->getElements() as $agentEvalSuiteRecordRun) {
            $compared = $compared || $agentEvalSuiteRecordRun->isComparedWithBaseline();
            $regressed = $regressed || $agentEvalSuiteRecordRun->hasPerformanceRegressions();
        }
        if (!$compared) {
            return static::VERDICT_NOT_COMPARED;
        }
        return $regressed ? static::VERDICT_REGRESSED : static::VERDICT_OK;
    }

    /** Whether the run succeeds: correctness passed and the performance did not regress. */
    public function isSuccessful(): bool
    {
        return $this->getCorrectnessVerdict() === static::VERDICT_PASSED && $this->getPerformanceVerdict() !== static::VERDICT_REGRESSED;
    }

    /**
     * The process seconds per wall second, one decimal; null for a run of 0 wall seconds.
     */
    public function getSpeedUp(): ?float
    {
        return $this->wallSeconds > 0 ? round($this->processSeconds / $this->wallSeconds, 1) : null;
    }

    /** The two verdicts of the run. */
    public function buildVerdict(): AgentEvalSuiteRecordVerdict
    {
        return AgentEvalSuiteRecordVerdict::forVerdicts($this->getCorrectnessVerdict(), $this->getPerformanceVerdict());
    }

    /**
     * The record as a suite writes it: the stored document of `<suite run>/_suite.json`. The start time is written in the
     * server's time zone.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'startedAt' => date('Y-m-d H:i:s', $this->startedAtTimestamp),
            'wallSeconds' => $this->wallSeconds,
            'processSeconds' => (int)round($this->processSeconds),
            'speedUp' => $this->getSpeedUp(),
            'stopped' => $this->stopped,
            'settings' => $this->settings->toArray(),
            'plan' => $this->planSummary->toArray(),
            'baseline' => $this->baselineDirectoryName,
            'baselineChoice' => $this->baselineChoiceText,
            'verdict' => $this->buildVerdict()->toArray(),
            'total' => $this->getTotal(),
            'passed' => $this->getPassed(),
            'notApplicable' => $this->getNotApplicable(),
            'runs' => $this->runs->toArraysByRunKey(),
        ];
    }
}
