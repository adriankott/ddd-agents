<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use RuntimeException;

/**
 * The baseline of a suite run: `latest` takes the newest former suite run that shares enough of the planned cases —
 * at least five and at least half — and skips a newer smoke run of one case; a named folder is taken whatever its
 * overlap; the cases a former run measured are the rows of its run summaries that carry a performance block; the
 * summary to compare with is read from the baseline's folder of the same model and flavor.
 */
class AgentEvalResultFilesServiceBaselineTest extends AgentEvalResultFilesTestCase
{
    public const string RUN_FOLDER = 'OPENAI.GPT6_LUNA-judge';

    /**
     * A former suite run whose one run folder measured `$measuredCaseIds` (plus one error row that is never compared).
     *
     * @param string[] $measuredCaseIds
     */
    protected function writeFormerSuiteRun(string $folderName, array $measuredCaseIds): string
    {
        $suiteRunDirectory = $this->resultsDirectory . '/' . $folderName;
        $this->writeTemporaryFile($suiteRunDirectory . '/_suite.json', '{}');
        $caseRows = [['case' => 'judged.errored', 'passed' => false, 'error' => 'the child process left no result']];
        foreach ($measuredCaseIds as $measuredCaseId) {
            $caseRows[] = ['case' => $measuredCaseId, 'passed' => true, 'performance' => ['modelCalls' => 10]];
        }
        $this->writeTemporaryFile($suiteRunDirectory . '/' . self::RUN_FOLDER . '/_summary.json', (string)json_encode(['cases' => $caseRows]));
        return $suiteRunDirectory;
    }

    /** @return string[] */
    protected function buildCaseIds(int $firstCaseNumber, int $lastCaseNumber): array
    {
        return array_map(static fn (int $caseNumber): string => 'judged.case-' . $caseNumber, range($firstCaseNumber, $lastCaseNumber));
    }

    protected function buildPlannedTasks(int $caseCount): AgentEvalSuiteTasks
    {
        $plannedTasks = AgentEvalSuiteTasks::newInstance();
        for ($caseNumber = 1; $caseNumber <= $caseCount; $caseNumber++) {
            $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
            $agentEvalSuiteTask->modelName = 'OPENAI.GPT6_LUNA';
            $agentEvalSuiteTask->flavor = 'judge';
            $agentEvalSuiteTask->suite = 'judged';
            $agentEvalSuiteTask->caseId = 'judged.case-' . $caseNumber;
            $plannedTasks->add($agentEvalSuiteTask);
        }
        return $plannedTasks;
    }

    public function testLatestSkipsANewerSmokeRunAndTakesTheNewestRunWithEnoughCommonCases(): void
    {
        $fullRun = $this->writeFormerSuiteRun('2026-10-22_190000-suite-refactor-0d8a', $this->buildCaseIds(1, 20));
        $this->writeFormerSuiteRun('2026-10-22_211953-suite-smoke-night', ['judged.case-1']);
        $this->writeFormerSuiteRun('2026-10-21_080000-suite-old', $this->buildCaseIds(1, 24));
        $currentSuiteDirectory = $this->resultsDirectory . '/2026-10-22_212337-suite-refactor-night';

        $choice = $this->getResultFilesService()->findBaselineChoice('latest', $currentSuiteDirectory, $this->buildPlannedTasks(24));

        self::assertNotNull($choice);
        self::assertSame($fullRun, $choice->directory, 'the newest run with at least half of the 24 planned cases, not the smoke run');
        self::assertSame(20, $choice->toArray()['commonCases'], 'the error row is never compared');
        self::assertSame(12, $choice->toArray()['minimumCommonCases']);
        self::assertSame([['directory' => $this->resultsDirectory . '/2026-10-22_211953-suite-smoke-night', 'commonCases' => 1]], $choice->skippedRuns->toArrays());
    }

    public function testNoBaselineWhenNoFormerRunSharesEnoughCases(): void
    {
        $this->writeFormerSuiteRun('2026-10-22_211953-suite-smoke-night', $this->buildCaseIds(1, 4));

        $choice = $this->getResultFilesService()->findBaselineChoice('latest', $this->resultsDirectory . '/2026-10-23_010000-suite', $this->buildPlannedTasks(6));

        self::assertNotNull($choice);
        self::assertNull($choice->directory, 'four common cases are under the floor of five');
        self::assertSame(5, $choice->toArray()['minimumCommonCases']);
        self::assertSame(6, $choice->toArray()['plannedCases']);
        self::assertSame([['directory' => $this->resultsDirectory . '/2026-10-22_211953-suite-smoke-night', 'commonCases' => 4]], $choice->skippedRuns->toArrays());
    }

    public function testANamedFolderIsTakenWhateverItsOverlap(): void
    {
        $smokeRun = $this->writeFormerSuiteRun('2026-10-22_211953-suite-smoke-night', ['judged.case-1']);

        $choiceByPath = $this->getResultFilesService()->findBaselineChoice($smokeRun . '/', $this->resultsDirectory . '/2026-10-23_010000-suite', $this->buildPlannedTasks(24));
        $choiceByName = $this->getResultFilesService()->findBaselineChoice('2026-10-22_211953-suite-smoke-night', $this->resultsDirectory . '/2026-10-23_010000-suite', $this->buildPlannedTasks(24));

        self::assertNotNull($choiceByPath);
        self::assertSame($smokeRun, $choiceByPath->directory, 'without the trailing slash');
        self::assertSame(1, $choiceByPath->toArray()['commonCases']);
        self::assertSame(24, $choiceByPath->toArray()['plannedCases']);
        self::assertNotNull($choiceByName);
        self::assertSame($smokeRun, $choiceByName->directory, 'a name is looked up in the results folder');
    }

    public function testANamedFolderWithoutASuiteRecordIsRefused(): void
    {
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-10-22_211953-OPENAI.GPT6_LUNA-all/_summary.json', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The baseline 2026-10-22_211953-OPENAI.GPT6_LUNA-all is no suite run (no _suite.json)');
        $this->getResultFilesService()->findBaselineChoice('2026-10-22_211953-OPENAI.GPT6_LUNA-all', $this->resultsDirectory . '/2026-10-23_010000-suite', $this->buildPlannedTasks(3));
    }

    public function testWithoutAReferenceThereIsNoBaseline(): void
    {
        self::assertNull($this->getResultFilesService()->findBaselineChoice(null, $this->resultsDirectory . '/2026-10-23_010000-suite', $this->buildPlannedTasks(3)));
    }

    public function testTheFormerSuiteRunsAreTheFinishedOnesNewestFirstWithoutTheCurrentOne(): void
    {
        $this->writeFormerSuiteRun('2026-10-21_080000-suite-old', []);
        $this->writeFormerSuiteRun('2026-10-22_190000-suite', []);
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-10-22_200000-suite-unfinished/_progress.json', '{}');
        $this->writeTemporaryFile($this->resultsDirectory . '/2026-10-22_200500-OPENAI.GPT6_LUNA-all/_suite.json', '{}');
        $currentSuiteDirectory = $this->writeFormerSuiteRun('2026-10-23_010000-suite', []);

        self::assertSame(
            [$this->resultsDirectory . '/2026-10-22_190000-suite', $this->resultsDirectory . '/2026-10-21_080000-suite-old'],
            $this->getResultFilesService()->findFormerSuiteRunDirectoriesNewestFirst($currentSuiteDirectory)
        );
    }

    public function testTheMeasuredCasesAreTheRowsWithAPerformanceBlockPerRunFolder(): void
    {
        $suiteRunDirectory = $this->writeFormerSuiteRun('2026-10-22_190000-suite', ['judged.case-1', 'judged.case-2']);
        $this->writeTemporaryFile($suiteRunDirectory . '/default-call/_summary.json', (string)json_encode(['cases' => [
            ['case' => 'pick.one', 'performance' => ['modelCalls' => 1]],
            ['case' => '', 'performance' => ['modelCalls' => 1]],
            ['case' => 'pick.two', 'performance' => null],
        ]]));
        $this->writeTemporaryFile($suiteRunDirectory . '/broken-call/_summary.json', 'not json');

        $measuredCaseIds = $this->getResultFilesService()->findMeasuredCaseIdsByRunFolder($suiteRunDirectory);

        self::assertEqualsCanonicalizing([self::RUN_FOLDER, 'default-call'], $measuredCaseIds->getRunFolderNames());
        self::assertSame(['judged.case-1', 'judged.case-2'], $measuredCaseIds->getCaseIdsOfRunFolder(self::RUN_FOLDER));
        self::assertSame(['pick.one'], $measuredCaseIds->getCaseIdsOfRunFolder('default-call'));
        self::assertSame([], $measuredCaseIds->getCaseIdsOfRunFolder('broken-call'), 'an unreadable summary measured nothing');
    }

    public function testThePlannedCasesArePerRunFolderOfModelAndFlavor(): void
    {
        $plannedTasks = $this->buildPlannedTasks(2);
        $taskWithoutModel = AgentEvalSuiteTask::newInstance();
        $taskWithoutModel->flavor = 'call';
        $taskWithoutModel->suite = 'selection';
        $taskWithoutModel->caseId = 'pick.one';
        $plannedTasks->add($taskWithoutModel);

        $plannedCaseIds = $plannedTasks->buildPlannedCaseIdsByRunFolder();

        self::assertSame([self::RUN_FOLDER, 'default-call'], $plannedCaseIds->getRunFolderNames());
        self::assertSame(['judged.case-1', 'judged.case-2'], $plannedCaseIds->getCaseIdsOfRunFolder(self::RUN_FOLDER));
        self::assertSame(['pick.one'], $plannedCaseIds->getCaseIdsOfRunFolder('default-call'));
        self::assertSame(self::RUN_FOLDER, $this->getResultFilesService()->getRunFolderName('OPENAI.GPT6_LUNA', 'judge'), 'the service names a run folder as a task does');
    }

    public function testTheBaselineSummaryIsReadFromTheFolderOfTheSameModelAndFlavor(): void
    {
        $baselineDirectory = $this->writeFormerSuiteRun('2026-10-22_190000-suite', ['judged.case-1']);
        $this->writeTemporaryFile($baselineDirectory . '/default-call/_summary.json', 'not json');
        $agentEvalResultFilesService = $this->getResultFilesService();

        $baselineRunSummary = $agentEvalResultFilesService->findBaselineRunSummary($baselineDirectory, 'OPENAI.GPT6_LUNA', 'judge');

        self::assertNotNull($baselineRunSummary);
        self::assertSame(json_decode((string)file_get_contents($baselineDirectory . '/' . self::RUN_FOLDER . '/_summary.json'), true)['cases'], $baselineRunSummary->caseRows->toArrays());
        self::assertNull($agentEvalResultFilesService->findBaselineRunSummary($baselineDirectory, 'OPENAI.GPT6_LUNA', 'call'), 'no folder of that flavor');
        self::assertNull($agentEvalResultFilesService->findBaselineRunSummary($baselineDirectory, null, 'call'), 'an unreadable summary');
    }
}
