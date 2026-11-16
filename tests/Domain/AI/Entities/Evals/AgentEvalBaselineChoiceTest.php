<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalBaselineChoice;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseIdsByRunFolder;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The latest baseline is the newest former suite run that shares enough of the planned cases — at least five and at
 * least half — and a newer smoke run of one case is skipped; the printed line names the choice and the skipped runs.
 * A named folder is taken whatever its overlap.
 */
class AgentEvalBaselineChoiceTest extends UnitTestCase
{
    protected const string RUN_FOLDER = 'VENDOR.MODEL-judge';

    public function testLatestSkipsANewerSmokeRunAndTakesTheNewestRunWithEnoughCommonCases(): void
    {
        $agentEvalBaselineChoice = AgentEvalBaselineChoice::forPlannedCases($this->plannedTasks(24)->buildPlannedCaseIdsByRunFolder());

        // newest first, as the suite folders sort by their timestamped names
        self::assertFalse($agentEvalBaselineChoice->considerFormerRun('/runs/2000-01-03_211953-suite-smoke', $this->measuredCases(['case-1'])));
        self::assertTrue($agentEvalBaselineChoice->considerFormerRun('/runs/2000-01-03_190000-suite-full', $this->measuredCases(array_map(static fn (int $caseNumber): string => 'case-' . $caseNumber, range(1, 20)))));
        self::assertFalse($agentEvalBaselineChoice->considerFormerRun('/runs/2000-01-02_080000-suite-old', $this->measuredCases(array_map(static fn (int $caseNumber): string => 'case-' . $caseNumber, range(1, 24)))), 'the first run that qualifies is taken');

        self::assertSame([
            'directory' => '/runs/2000-01-03_190000-suite-full',
            'commonCases' => 20,
            'plannedCases' => 24,
            'minimumCommonCases' => 12,
            'skippedRuns' => [['directory' => '/runs/2000-01-03_211953-suite-smoke', 'commonCases' => 1]],
        ], $agentEvalBaselineChoice->toArray());
        self::assertSame('2000-01-03_190000-suite-full', $agentEvalBaselineChoice->getDirectoryName());
        self::assertSame(
            'baseline: 2000-01-03_190000-suite-full (latest) — the newest suite run sharing at least 12 of the 24 planned cases: 20 in common; skipped as too small an overlap: 2000-01-03_211953-suite-smoke (1 common)',
            $agentEvalBaselineChoice->describe(false)
        );
    }

    public function testNoBaselineWhenNoFormerRunSharesEnoughCases(): void
    {
        $agentEvalBaselineChoice = AgentEvalBaselineChoice::forPlannedCases($this->plannedTasks(6)->buildPlannedCaseIdsByRunFolder());

        $agentEvalBaselineChoice->considerFormerRun('/runs/2000-01-03_211953-suite-smoke', $this->measuredCases(['case-1', 'case-2', 'case-3', 'case-4']));

        self::assertFalse($agentEvalBaselineChoice->hasBaseline(), 'four common cases are under the floor of five');
        self::assertNull($agentEvalBaselineChoice->getDirectoryName());
        self::assertSame(0, $agentEvalBaselineChoice->commonCases);
        self::assertSame(
            'baseline: none — no former suite run shares at least 5 of the 6 planned cases; skipped as too small an overlap: 2000-01-03_211953-suite-smoke (4 common)',
            $agentEvalBaselineChoice->describe(false)
        );
    }

    public function testANamedFolderIsTakenWhateverItsOverlap(): void
    {
        $agentEvalBaselineChoice = AgentEvalBaselineChoice::forPlannedCases($this->plannedTasks(24)->buildPlannedCaseIdsByRunFolder());

        $agentEvalBaselineChoice->takeNamedRun('/runs/2000-01-03_211953-suite-smoke', $this->measuredCases(['case-1']));

        self::assertSame('/runs/2000-01-03_211953-suite-smoke', $agentEvalBaselineChoice->directory);
        self::assertSame('baseline: 2000-01-03_211953-suite-smoke (named) — 1 of the 24 planned cases in common', $agentEvalBaselineChoice->describe(true));
    }

    public function testTheFloorIsFiveAndHalfThePlannedCasesButNeverMoreThanPlanned(): void
    {
        $agentEvalBaselineChoice = AgentEvalBaselineChoice::forPlannedCases(AgentEvalCaseIdsByRunFolder::newInstance());

        self::assertSame(3, $agentEvalBaselineChoice->computeMinimumCommonCases(3), 'a run of three cases needs all three');
        self::assertSame(5, $agentEvalBaselineChoice->computeMinimumCommonCases(8));
        self::assertSame(12, $agentEvalBaselineChoice->computeMinimumCommonCases(24));
        self::assertSame(1, $agentEvalBaselineChoice->computeMinimumCommonCases(0));
    }

    public function testOnlyMeasuredRowsOfTheSameRunFolderCountAsShared(): void
    {
        $plannedCaseIds = $this->plannedTasks(2)->buildPlannedCaseIdsByRunFolder();
        $measuredCaseIds = AgentEvalCaseIdsByRunFolder::newInstance();
        AgentEvalCaseRows::fromRowArrays([
            ['case' => 'case-1', 'passed' => false, 'error' => 'the child process left no result'],
            ['case' => 'case-2', 'passed' => true, 'performance' => ['modelCalls' => 10]],
            ['case' => '', 'passed' => true, 'performance' => ['modelCalls' => 10]],
            'not a row',
        ])->addMeasuredCaseIdsTo($measuredCaseIds, self::RUN_FOLDER);
        $otherFolder = AgentEvalCaseIdsByRunFolder::newInstance();
        $otherFolder->addCaseId('VENDOR.MODEL-call', 'case-1');

        self::assertSame(['case-2'], $measuredCaseIds->getCaseIdsOfRunFolder(self::RUN_FOLDER), 'an error row is never compared');
        self::assertSame(1, $plannedCaseIds->countCasesInCommonWith($measuredCaseIds));
        self::assertSame(0, $plannedCaseIds->countCasesInCommonWith($otherFolder));
    }

    protected function plannedTasks(int $caseCount): AgentEvalSuiteTasks
    {
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::newInstance();
        for ($caseNumber = 1; $caseNumber <= $caseCount; $caseNumber++) {
            $task = AgentEvalSuiteTask::newInstance();
            $task->modelName = 'VENDOR.MODEL';
            $task->flavor = AgentEvalSuiteTask::FLAVOR_JUDGE;
            $task->caseId = 'case-' . $caseNumber;
            $task->taskId = $task->getRunKey() . '|tools|' . $task->caseId;
            $agentEvalSuiteTasks->add($task);
        }
        return $agentEvalSuiteTasks;
    }

    /**
     * @param string[] $caseIds
     */
    protected function measuredCases(array $caseIds): AgentEvalCaseIdsByRunFolder
    {
        $measuredCaseIds = AgentEvalCaseIdsByRunFolder::newInstance();
        foreach ($caseIds as $caseId) {
            $measuredCaseIds->addCaseId(self::RUN_FOLDER, $caseId);
        }
        return $measuredCaseIds;
    }
}
