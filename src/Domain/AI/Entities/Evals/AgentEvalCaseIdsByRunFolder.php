<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Case ids per run folder (`<model>-<flavor>`): the cases a suite run plans, or the cases a former suite run measured
 * (the rows of its run summaries that carry a performance block). Two such lists give the cases two runs can be
 * compared on.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCaseIdsByRunFolder extends ValueObject
{
    /** @var array Run folder name → (its case ids in the order they were added, duplicates kept) */
    protected array $caseIdsByRunFolder = [];

    /** Adds one case id to the run folder `$runFolderName`. */
    public function addCaseId(string $runFolderName, string $caseId): void
    {
        $this->caseIdsByRunFolder[$runFolderName][] = $caseId;
    }

    /**
     * The run folder names in the order they were first added.
     *
     * @return string[]
     */
    public function getRunFolderNames(): array
    {
        return array_map('strval', array_keys($this->caseIdsByRunFolder));
    }

    /**
     * The case ids of one run folder, as added; empty for a folder this list does not know.
     *
     * @return string[]
     */
    public function getCaseIdsOfRunFolder(string $runFolderName): array
    {
        return $this->caseIdsByRunFolder[$runFolderName] ?? [];
    }

    /** The distinct case ids over every run folder (a case counts once per folder). */
    public function countDistinctCases(): int
    {
        $distinctCases = 0;
        foreach ($this->caseIdsByRunFolder as $caseIds) {
            $distinctCases += count(array_unique($caseIds));
        }
        return $distinctCases;
    }

    /**
     * The cases this list (the planned run) shares with `$measuredCaseIds` (a former run): per run folder, the distinct
     * case ids both name.
     */
    public function countCasesInCommonWith(AgentEvalCaseIdsByRunFolder $measuredCaseIds): int
    {
        $commonCases = 0;
        foreach ($this->caseIdsByRunFolder as $runFolderName => $plannedCaseIds) {
            $commonCases += count(array_intersect(array_unique($plannedCaseIds), array_unique($measuredCaseIds->getCaseIdsOfRunFolder((string)$runFolderName))));
        }
        return $commonCases;
    }
}
