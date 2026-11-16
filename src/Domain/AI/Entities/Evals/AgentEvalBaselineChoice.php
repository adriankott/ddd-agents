<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Which former suite run a suite run compares its performance with, and why.
 *
 * A folder the operator names is taken whatever its overlap ({@see takeNamedRun()}). The latest baseline is the
 * NEWEST former suite run that shares enough of the planned cases ({@see considerFormerRun()}, called newest first
 * until one qualifies): at least {@see LATEST_BASELINE_MINIMUM_COMMON_CASES} and at least
 * {@see LATEST_BASELINE_MINIMUM_COMMON_SHARE} of the planned cases, never more than the planned cases themselves. A
 * newer run with fewer common cases (a smoke run of one case) is skipped and named. No run qualifies = no baseline.
 * A former run shares a case when its run summary of the same run folder holds a measured row of it.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalBaselineChoice extends ValueObject
{
    /** @var int The latest baseline skips a former suite run that shares fewer planned cases than this … */
    public const int LATEST_BASELINE_MINIMUM_COMMON_CASES = 5;

    /** @var float … or fewer than this share of the planned cases */
    public const float LATEST_BASELINE_MINIMUM_COMMON_SHARE = 0.5;

    /** @var AgentEvalCaseIdsByRunFolder The planned cases per run folder */
    public AgentEvalCaseIdsByRunFolder $plannedCaseIds;

    /** @var string|null The folder of the chosen run; null = no baseline */
    public ?string $directory = null;

    /** @var int The planned cases the chosen run shares; 0 without a choice */
    public int $commonCases = 0;

    /** @var int The planned cases (distinct per run folder) */
    public int $plannedCases = 0;

    /** @var int The planned cases a former run must share to be taken as the latest baseline */
    public int $minimumCommonCases = 0;

    /** @var AgentEvalSkippedBaselineRuns The former runs skipped for too small an overlap, newest first */
    public AgentEvalSkippedBaselineRuns $skippedRuns;

    /** The choice for the planned cases `$plannedCaseIds`, before any former run was considered. */
    public static function forPlannedCases(AgentEvalCaseIdsByRunFolder $plannedCaseIds): static
    {
        $agentEvalBaselineChoice = static::newInstance();
        $agentEvalBaselineChoice->plannedCaseIds = $plannedCaseIds;
        $agentEvalBaselineChoice->plannedCases = $plannedCaseIds->countDistinctCases();
        $agentEvalBaselineChoice->minimumCommonCases = $agentEvalBaselineChoice->computeMinimumCommonCases($agentEvalBaselineChoice->plannedCases);
        $agentEvalBaselineChoice->skippedRuns = AgentEvalSkippedBaselineRuns::newInstance();
        return $agentEvalBaselineChoice;
    }

    /**
     * The smallest number of planned cases a former suite run must share before the latest baseline takes it: at least
     * {@see LATEST_BASELINE_MINIMUM_COMMON_CASES} and at least {@see LATEST_BASELINE_MINIMUM_COMMON_SHARE} of the
     * planned cases — never more than the planned cases (a run of three cases needs all three), never less than one.
     */
    public function computeMinimumCommonCases(int $plannedCaseCount): int
    {
        $minimumCommonCases = max(static::LATEST_BASELINE_MINIMUM_COMMON_CASES, (int)ceil($plannedCaseCount * static::LATEST_BASELINE_MINIMUM_COMMON_SHARE));
        return max(1, min($plannedCaseCount, $minimumCommonCases));
    }

    /**
     * Considers the former suite run of the folder `$directory` that measured `$measuredCaseIds`: taken when no run
     * was taken yet and it shares enough planned cases, else skipped and named. Returns whether it was taken.
     */
    public function considerFormerRun(string $directory, AgentEvalCaseIdsByRunFolder $measuredCaseIds): bool
    {
        if ($this->directory !== null) {
            return false;
        }
        $commonCases = $this->plannedCaseIds->countCasesInCommonWith($measuredCaseIds);
        if ($commonCases >= $this->minimumCommonCases) {
            $this->directory = $directory;
            $this->commonCases = $commonCases;
            return true;
        }
        $agentEvalSkippedBaselineRun = AgentEvalSkippedBaselineRun::forDirectory($directory, $commonCases);
        $this->skippedRuns->add($agentEvalSkippedBaselineRun);
        return false;
    }

    /** Takes the folder the operator named, whatever its overlap; the choice still counts the planned cases it shares. */
    public function takeNamedRun(string $directory, AgentEvalCaseIdsByRunFolder $measuredCaseIds): void
    {
        $this->directory = $directory;
        $this->commonCases = $this->plannedCaseIds->countCasesInCommonWith($measuredCaseIds);
    }

    /** Whether a former run was taken. */
    public function hasBaseline(): bool
    {
        return $this->directory !== null;
    }

    /** The folder name of the chosen run; null without a baseline. */
    public function getDirectoryName(): ?string
    {
        return $this->directory !== null ? basename($this->directory) : null;
    }

    /**
     * The one line that says which baseline the run compares with and why (`$namedOnTheCommandLine` for a folder the
     * operator named).
     */
    public function describe(bool $namedOnTheCommandLine): string
    {
        $skippedRunTexts = array_map(static fn (AgentEvalSkippedBaselineRun $agentEvalSkippedBaselineRun): string => $agentEvalSkippedBaselineRun->describe(), $this->skippedRuns->getElements());
        $skippedText = $skippedRunTexts === [] ? '' : '; skipped as too small an overlap: ' . implode(', ', $skippedRunTexts);
        if ($this->directory === null) {
            return sprintf('baseline: none — no former suite run shares at least %d of the %d planned cases%s', $this->minimumCommonCases, $this->plannedCases, $skippedText);
        }
        if ($namedOnTheCommandLine) {
            return sprintf('baseline: %s (named) — %d of the %d planned cases in common', basename($this->directory), $this->commonCases, $this->plannedCases);
        }
        return sprintf(
            'baseline: %s (latest) — the newest suite run sharing at least %d of the %d planned cases: %d in common%s',
            basename($this->directory),
            $this->minimumCommonCases,
            $this->plannedCases,
            $this->commonCases,
            $skippedText
        );
    }

    /**
     * The choice in the form a suite stores and prints it.
     *
     * @return array{directory: string|null, commonCases: int, plannedCases: int, minimumCommonCases: int, skippedRuns: array}
     */
    public function toArray(): array
    {
        return [
            'directory' => $this->directory,
            'commonCases' => $this->commonCases,
            'plannedCases' => $this->plannedCases,
            'minimumCommonCases' => $this->minimumCommonCases,
            'skippedRuns' => $this->skippedRuns->toArrays(),
        ];
    }
}
