<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteDecisionLayerSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteProgress;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecord;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordRun;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordRuns;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRunningTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;

/**
 * Every file a run writes keeps its name, its folder, its keys, their order and its encoding: the service writes the
 * objects built from the fixture input, the test reads the file back and compares it byte for byte with the stored
 * format of that file for the same values (`Fixtures/ResultFiles/`; the summaries and the record in `input.json` are
 * the `toArray()` of the objects this test builds).
 */
class AgentEvalResultFilesServiceWrittenFilesTest extends AgentEvalResultFilesTestCase
{
    /** The task of the fixture input. */
    protected function buildSuiteTaskOfFixture(): AgentEvalSuiteTask
    {
        $suiteTaskValues = $this->readFixtureInput()['suiteTask'];
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->suite = $suiteTaskValues['suite'];
        $agentEvalSuiteTask->caseId = $suiteTaskValues['caseId'];
        $agentEvalSuiteTask->modelName = $suiteTaskValues['modelName'];
        $agentEvalSuiteTask->flavor = $suiteTaskValues['flavor'];
        return $agentEvalSuiteTask;
    }

    /** The child's case row as the suite command stamps it with the task's facts, in the command's order. */
    protected function buildStampedSuiteCaseRow(): AgentEvalCaseRow
    {
        $fixtureInput = $this->readFixtureInput();
        $suiteTaskValues = $fixtureInput['suiteTask'];
        $caseRowValues = $fixtureInput['caseRowOfChild'];
        $caseRowValues['case'] = $suiteTaskValues['caseId'];
        $caseRowValues['suite'] = $suiteTaskValues['suite'];
        $caseRowValues['fixture'] = basename($suiteTaskValues['fixturePath'], '.json');
        $caseRowValues['model'] = $suiteTaskValues['modelName'];
        $caseRowValues['flavor'] = $suiteTaskValues['flavor'];
        $caseRowValues['access'] = $suiteTaskValues['access'];
        $caseRowValues['lane'] = $suiteTaskValues['agentSpaceId'] . ':' . $suiteTaskValues['resourceKey'];
        $caseRowValues['processSeconds'] = round(41.26, 1);
        return AgentEvalCaseRow::fromArray($caseRowValues);
    }

    /** The summary of the tools run of the fixture input: its two case rows, judged on one model, one repetition. */
    public function buildToolsRunSummaryOfFixture(): AgentEvalRunSummary
    {
        $fixtureInput = $this->readFixtureInput();
        $agentEvalCaseRows = AgentEvalCaseRows::fromRowArrays([$fixtureInput['caseRowOfChild'], $fixtureInput['secondCaseRow']]);
        return AgentEvalRunSummary::buildForToolsRun($agentEvalCaseRows, 'OPENAI.GPT6_LUNA', 'judge', ['planDynamics' => 'on'], 1, 'on', '');
    }

    /** The run summary of the suite run of the fixture input: the child's row stamped with the task, judged on one model. */
    public function buildSuiteRunSummaryOfFixture(): AgentEvalRunSummary
    {
        $agentEvalCaseRows = AgentEvalCaseRows::fromRows($this->buildStampedSuiteCaseRow());
        return AgentEvalRunSummary::buildForSuite($agentEvalCaseRows, 'OPENAI.GPT6_LUNA', 'judge');
    }

    /**
     * The settings of the suite run of the fixture input, in the shape every suite run records them; the arms carry a
     * non-ASCII value, so the record shows that it keeps unicode unescaped.
     */
    public function buildSuiteRecordSettingsOfFixture(): AgentEvalSuiteRecordSettings
    {
        $agentEvalSuiteDecisionLayerSettings = AgentEvalSuiteDecisionLayerSettings::newInstance();
        $agentEvalSuiteDecisionLayerSettings->mode = 'shadow';
        $agentEvalSuiteDecisionLayerSettings->siteModes = ['turnClose' => 'shadow', 'skillPreload' => 'off'];
        $agentEvalSuiteDecisionLayerSettings->turnClosingMode = 'deterministicCounter';
        $agentEvalSuiteRecordSettings = AgentEvalSuiteRecordSettings::forDecisionLayer($agentEvalSuiteDecisionLayerSettings);
        $agentEvalSuiteRecordSettings->modelNames = ['OPENAI.GPT6_LUNA'];
        $agentEvalSuiteRecordSettings->flavors = ['judge'];
        $agentEvalSuiteRecordSettings->suites = ['tools'];
        $agentEvalSuiteRecordSettings->arms = ['label' => 'nightly — café'];
        $agentEvalSuiteRecordSettings->maxProcesses = 4;
        $agentEvalSuiteRecordSettings->configuredReasoningEffortByModel = ['OPENAI.GPT6_LUNA' => 'medium'];
        return $agentEvalSuiteRecordSettings;
    }

    /** The record of the finished suite run of the fixture input: one run, no baseline, a plan of three tasks. */
    public function buildSuiteRecordOfFixture(): AgentEvalSuiteRecord
    {
        $agentEvalSuitePlanSummary = AgentEvalSuitePlanSummary::newInstance();
        $agentEvalSuitePlanSummary->tasks = 3;
        $agentEvalSuiteRecordRun = AgentEvalSuiteRecordRun::forRun('OPENAI.GPT6_LUNA|judge', $this->buildSuiteRunSummaryOfFixture(), null);
        $agentEvalSuiteRecordRuns = AgentEvalSuiteRecordRuns::newInstance();
        $agentEvalSuiteRecordRuns->add($agentEvalSuiteRecordRun);
        return AgentEvalSuiteRecord::forFinishedRun(
            (int)mktime(10, 0, 0, 11, 16, 2026),
            95,
            false,
            $this->buildSuiteRecordSettingsOfFixture(),
            $agentEvalSuitePlanSummary,
            null,
            null,
            $agentEvalSuiteRecordRuns
        );
    }

    /**
     * The progress of the fixture input (`progress`), built the way a suite run takes it: the counts, and one running
     * task with its child process.
     */
    public function buildSuiteProgressOfFixture(): AgentEvalSuiteProgress
    {
        $progressValues = $this->readFixtureInput()['progress'];
        $agentEvalSuiteProgress = AgentEvalSuiteProgress::takenAt((int)strtotime($progressValues['at']));
        $agentEvalSuiteProgress->total = $progressValues['total'];
        $agentEvalSuiteProgress->finished = $progressValues['finished'];
        $agentEvalSuiteProgress->passed = $progressValues['passed'];
        $agentEvalSuiteProgress->notApplicable = $progressValues['notApplicable'];
        $agentEvalSuiteProgress->queued = $progressValues['queued'];
        foreach ($progressValues['running'] as $runningValues) {
            [$modelName, $flavor] = explode('|', $runningValues['run']);
            [$agentSpaceId, $resourceKey] = explode(':', $runningValues['lane']);
            $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
            $agentEvalSuiteTask->taskId = $runningValues['run'] . '|' . $runningValues['suite'] . '|' . $runningValues['case'];
            $agentEvalSuiteTask->caseId = $runningValues['case'];
            $agentEvalSuiteTask->suite = $runningValues['suite'];
            $agentEvalSuiteTask->modelName = $modelName;
            $agentEvalSuiteTask->flavor = $flavor;
            $agentEvalSuiteTask->agentSpaceId = (int)$agentSpaceId;
            $agentEvalSuiteTask->resourceKey = $resourceKey;
            $agentEvalSuiteTask->access = $runningValues['access'];
            $agentEvalSuiteRunningTask = AgentEvalSuiteRunningTask::forTask($agentEvalSuiteTask, $runningValues['pid'], $runningValues['seconds']);
            $agentEvalSuiteProgress->runningTasks->add($agentEvalSuiteRunningTask);
        }
        return $agentEvalSuiteProgress;
    }

    public function testTheNamesOfARunOfTheToolsCommand(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $startedAtTimestamp = (int)mktime(20, 54, 41, 7, 13, 2026);

        self::assertSame('Catalog.Search', $agentEvalResultFilesService->getScopeTagOfToolsRun('Catalog.Search', 'ignored'));
        self::assertSame('case-catalog.last_7', $agentEvalResultFilesService->getScopeTagOfToolsRun(null, 'catalog.last 7'));
        self::assertSame('case-catalog.x', $agentEvalResultFilesService->getScopeTagOfToolsRun('', 'catalog.x'));
        self::assertSame('all', $agentEvalResultFilesService->getScopeTagOfToolsRun(null, ''));
        self::assertSame('2026-07-13_205441-OPENAI.GPT6_LUNA-all', $agentEvalResultFilesService->getToolsRunName($startedAtTimestamp, 'OPENAI.GPT6_LUNA', 'all'));
        self::assertSame('2026-07-13_205441-default-case-x', $agentEvalResultFilesService->getToolsRunName($startedAtTimestamp, null, 'case-x'));
        self::assertSame($this->resultsDirectory . '/2026-07-13_205441-default-all', $agentEvalResultFilesService->getToolsRunDirectory('2026-07-13_205441-default-all'));
        self::assertSame($this->resultsDirectory . '/2026-07-13_205441-default-all.json', $agentEvalResultFilesService->getToolsRunFile('2026-07-13_205441-default-all'));
        self::assertSame('catalog.last-7-days_ber.json', $agentEvalResultFilesService->getCaseFileNameOfToolsRun('catalog.last-7-days/über', 0));
        self::assertSame('catalog.last-7-days_ber.rep2.json', $agentEvalResultFilesService->getCaseFileNameOfToolsRun('catalog.last-7-days/über', 2));
        self::assertSame('x', $agentEvalResultFilesService->getSafeFileNameSegment(''));
        self::assertSame('a_b.c-d', $agentEvalResultFilesService->getSafeFileNameSegment('a/ :b.c-d'));
    }

    public function testFolderAndFileNamesAreMadeSafeByTheTasksOneRule(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->modelName = 'VENDOR/model: ünïcode';
        $agentEvalSuiteTask->flavor = 'judge';

        foreach (['VENDOR/model: ünïcode', 'catalog.last-7-days/über', '', 'a..b_c-d'] as $value) {
            self::assertSame($agentEvalSuiteTask->sanitizeForFolderName($value), $agentEvalResultFilesService->getSafeFileNameSegment($value), $value);
        }
        self::assertSame('VENDOR_model_n_code-judge', $agentEvalSuiteTask->getRunFolderName());
        self::assertSame($agentEvalSuiteTask->getRunFolderName(), $agentEvalResultFilesService->getRunFolderName('VENDOR/model: ünïcode', 'judge'));
    }

    public function testTheCaseFileOfAChildRunKeepsTheStoredFormat(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $caseRowValues = $this->readFixtureInput()['caseRowOfChild'];
        $caseRowValues['repetition'] = 2;

        $runDirectory = $agentEvalResultFilesService->prepareToolsRunDirectory('2026-07-13_205441-OPENAI.GPT6_LUNA-all');
        $agentEvalResultFilesService->writeCaseFileOfToolsRun($runDirectory, $caseRowValues['case'], 2, AgentEvalCaseRow::fromArray($caseRowValues));

        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/tools-case-file.rep2.json', $runDirectory . '/catalog.last-7-days_ber.rep2.json');
    }

    public function testTheSummaryOfARunFolderLeavesOutTheMessageTracesAndTheLooseRunFileKeepsThem(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $agentEvalRunSummary = $this->buildToolsRunSummaryOfFixture();

        $summaryFile = $agentEvalResultFilesService->writeToolsRunSummary('2026-07-13_205441-OPENAI.GPT6_LUNA-all', true, $agentEvalRunSummary);
        $runFile = $agentEvalResultFilesService->writeToolsRunSummary('2026-07-13_205442-OPENAI.GPT6_LUNA-case-x', false, $agentEvalRunSummary);
        $agentEvalResultFilesService->writeResultFile($this->temporaryDirectory . '/parent/reads/this.json', $agentEvalRunSummary);

        self::assertSame($this->resultsDirectory . '/2026-07-13_205441-OPENAI.GPT6_LUNA-all/_summary.json', $summaryFile);
        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/tools-run-folder-summary.json', $summaryFile);
        self::assertSame($this->resultsDirectory . '/2026-07-13_205442-OPENAI.GPT6_LUNA-case-x.json', $runFile);
        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/tools-run-file.json', $runFile);
        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/tools-run-file.json', $this->temporaryDirectory . '/parent/reads/this.json', 'the result file is the whole result');
        self::assertArrayHasKey('messages', $agentEvalRunSummary->toArray(true)['cases'][0], 'writing leaves the summary object as it was');
    }

    public function testTheFilesOfASuiteRunKeepTheStoredFormat(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $fixtureInput = $this->readFixtureInput();
        $agentEvalSuiteTask = $this->buildSuiteTaskOfFixture();
        $suiteDirectory = $agentEvalResultFilesService->createSuiteRunDirectory((int)mktime(10, 0, 0, 11, 16, 2026), 'effort low');

        $agentEvalResultFilesService->writeCaseFileOfSuiteTask($suiteDirectory, $agentEvalSuiteTask, $this->buildStampedSuiteCaseRow());
        $agentEvalResultFilesService->writeRunSummaryOfSuiteRun($suiteDirectory, 'OPENAI.GPT6_LUNA', 'judge', $this->buildSuiteRunSummaryOfFixture());
        $agentEvalResultFilesService->writeProgressOfSuiteRun($suiteDirectory, $this->buildSuiteProgressOfFixture());

        self::assertSame($this->resultsDirectory . '/2026-11-16_100000-suite-effort_low', $suiteDirectory);
        $caseFile = $suiteDirectory . '/OPENAI.GPT6_LUNA-judge/tools-catalog.last-7-days_ber.json';
        self::assertSame($caseFile, $agentEvalResultFilesService->getCaseFileOfSuiteTask($suiteDirectory, $agentEvalSuiteTask));
        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/suite-case-file.json', $caseFile);
        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/suite-run-summary.json', $suiteDirectory . '/OPENAI.GPT6_LUNA-judge/_summary.json');
        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/suite-progress.json', $suiteDirectory . '/_progress.json', 'the progress file escapes unicode');

        $agentEvalResultFilesService->stampRetryOnCaseFileOfSuiteTask($suiteDirectory, $agentEvalSuiteTask, 'Deadlock found when trying to get lock');
        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/suite-case-file-retried.json', $caseFile);

        $agentEvalResultFilesService->writeRecordOfSuiteRun($suiteDirectory, $this->buildSuiteRecordOfFixture());
        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/suite-record.json', $suiteDirectory . '/_suite.json', 'the record keeps unicode unescaped');
        self::assertFileDoesNotExist($suiteDirectory . '/_progress.json', 'the record ends the progress file');
    }

    public function testASuiteFolderWithoutALabelAndAFolderOfARunWithoutAModel(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->suite = 'selection';
        $agentEvalSuiteTask->caseId = 'pick.one';
        $agentEvalSuiteTask->flavor = 'call';

        $suiteDirectory = $agentEvalResultFilesService->createSuiteRunDirectory((int)mktime(10, 0, 0, 11, 16, 2026), null);

        self::assertSame($this->resultsDirectory . '/2026-11-16_100000-suite', $suiteDirectory);
        self::assertDirectoryExists($suiteDirectory);
        self::assertSame($suiteDirectory . '/default-call', $agentEvalResultFilesService->getRunDirectoryOfSuiteTask($suiteDirectory, $agentEvalSuiteTask));
        self::assertSame('default-call', $agentEvalResultFilesService->getRunFolderName(null, 'call'));
    }

    public function testTheChildResultFileIsReadOnceAndRemoved(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $agentEvalSuiteTask = $this->buildSuiteTaskOfFixture();
        $suiteDirectory = $agentEvalResultFilesService->createSuiteRunDirectory((int)mktime(10, 0, 0, 11, 16, 2026), null);

        $resultFile = $agentEvalResultFilesService->prepareChildResultFileOfSuiteTask($suiteDirectory, $agentEvalSuiteTask);
        self::assertSame($suiteDirectory . '/OPENAI.GPT6_LUNA-judge/.result-tools-catalog.last-7-days_ber.json', $resultFile);
        self::assertDirectoryExists(dirname($resultFile), 'the run folder exists before the child starts');
        file_put_contents($resultFile, (string)file_get_contents(self::FIXTURE_DIRECTORY . '/tools-run-file.json'));

        $caseRow = $agentEvalResultFilesService->findCaseRowOfChildResultFile($resultFile);

        self::assertNotNull($caseRow);
        // as the child wrote it: a whole float is written without its fraction (`12.0` reads back as `12`)
        $childResultDocument = json_decode((string)file_get_contents(self::FIXTURE_DIRECTORY . '/tools-run-file.json'), true);
        self::assertSame($childResultDocument['cases'][0], $caseRow->toArray(), 'the first case row');
        self::assertFileDoesNotExist($resultFile);
        self::assertNull($agentEvalResultFilesService->findCaseRowOfChildResultFile($resultFile), 'a child that left no file');
        file_put_contents($resultFile, '{"cases": []}');
        self::assertNull($agentEvalResultFilesService->findCaseRowOfChildResultFile($resultFile), 'a file without a case row');
        self::assertFileDoesNotExist($resultFile, 'removed also when it held no row');
    }

    public function testARetryStampLeavesAMissingCaseFileMissing(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $suiteDirectory = $agentEvalResultFilesService->createSuiteRunDirectory((int)mktime(10, 0, 0, 11, 16, 2026), null);

        $agentEvalResultFilesService->stampRetryOnCaseFileOfSuiteTask($suiteDirectory, $this->buildSuiteTaskOfFixture(), 'cURL error 28');

        self::assertFileDoesNotExist($agentEvalResultFilesService->getCaseFileOfSuiteTask($suiteDirectory, $this->buildSuiteTaskOfFixture()));
    }

    public function testAStopFileRequestsTheGracefulStop(): void
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $suiteDirectory = $agentEvalResultFilesService->createSuiteRunDirectory((int)mktime(10, 0, 0, 11, 16, 2026), null);

        self::assertFalse($agentEvalResultFilesService->isStopRequestedForSuiteRun($suiteDirectory));
        touch($suiteDirectory . '/STOP');
        self::assertTrue($agentEvalResultFilesService->isStopRequestedForSuiteRun($suiteDirectory));
    }
}
