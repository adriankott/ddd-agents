<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use ReflectionMethod;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunningCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalToolsRunSettings;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AgentEvalResultFilesTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use ReflectionProperty;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The tools evaluation keeps what its readers read: the files of a run in child processes (one case file per case run,
 * `<caseId>[.rep<k>].json`, the case file the stored format of `Fixtures/ResultFiles/tools-case-file.rep2.json`, and
 * `_summary.json` without the message traces), the `repetition` of a row and `metrics.repetitions`, the rows a parent
 * writes for a child that ran into its ceiling or printed nothing, the record of the run with its stored options, the
 * command line of a child, the SKIP / N/A lines and the not-applicable row of the run in this process, and the lines of
 * the report. Every expected text below is the current command's format string (or literal row) applied to the
 * figures of the test; the child processes print scripted documents and no model is called.
 */
class AgentEvalToolsCommandTest extends AgentEvalResultFilesTestCase
{
    /** @var string The case of the stored case file of the fixture input */
    public const string STORED_CASE_ID = 'catalog.last-7-days/über';

    /**
     * The results folder of the test, with a resource group and an exclusive fixture that no case of the test names: a
     * configured list that is empty would make the configuration read the module's file again, which names another
     * results folder.
     */
    protected function setResultsDirectoryConfigValue(?string $resultsDirectory): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues['AI']['evalSuite'] = [
            'resultsDirectory' => $resultsDirectory,
            'resourceGroupByFixture' => ['Unrelated.Fixture' => 'unrelated'],
            'exclusiveFixtures' => ['Unrelated.Fixture'],
        ];
        $configTree->setValue(null, $configValues);
    }

    public function testARepeatedRunWritesACaseFilePerRunTheSummaryAndTheRecord(): void
    {
        $childDocument = ['total' => 1, 'cases' => [$this->readFixtureInput()['caseRowOfChild']]];
        $probeCommand = new AgentEvalToolsProbeCommand();
        $probeCommand->childDocumentFile = $this->writeTemporaryJsonFile('child-document.json', $childDocument);
        $fixturePath = $this->writeFixture([['id' => self::STORED_CASE_ID, 'prompt' => 'catalog of the last week', 'agentSpaceId' => 7, 'access' => 'read', 'expect' => ['tool' => 'searchCatalog']]]);

        $commandTester = $this->runProbeCommand($probeCommand, ['--fixture' => $fixturePath, '--repeat' => '2', '--no-prune' => true, '--json' => true]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $runDirectories = glob($this->resultsDirectory . '/*-default-all', GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $runDirectories, 'one run folder, named by date, model and scope');
        $runDirectory = $runDirectories[0];
        self::assertFileEquals(self::FIXTURE_DIRECTORY . '/tools-case-file.rep2.json', $runDirectory . '/catalog.last-7-days_ber.rep2.json');
        $firstRunRow = json_decode((string)file_get_contents($runDirectory . '/catalog.last-7-days_ber.rep1.json'), true);
        self::assertSame(1, $firstRunRow['repetition']);
        self::assertSame('repetition', array_key_last($firstRunRow), 'the repetition is appended to the child\'s row');

        $summaryDocument = json_decode((string)file_get_contents($runDirectory . '/' . AgentEvalResultFilesService::SUMMARY_FILE_NAME), true);
        self::assertSame(['total', 'passed', 'notApplicable', 'model', 'flavor', 'arms', 'repeat', 'metrics', 'cases'], array_keys($summaryDocument));
        self::assertSame([2, 2, 0, null, 'call', [], 2], [$summaryDocument['total'], $summaryDocument['passed'], $summaryDocument['notApplicable'], $summaryDocument['model'], $summaryDocument['flavor'], $summaryDocument['arms'], $summaryDocument['repeat']]);
        self::assertSame([['case' => self::STORED_CASE_ID, 'runs' => 2, 'passed' => 2, 'errors' => 0, 'passRate' => 1]], $summaryDocument['metrics']['repetitions']);
        self::assertSame([1, 2], $this->sortedRepetitionsOf($summaryDocument['cases']));
        foreach ($summaryDocument['cases'] as $summaryCaseRow) {
            self::assertArrayNotHasKey('messages', $summaryCaseRow, 'the case file holds the trace, the summary does not');
        }

        $printedDocument = json_decode($commandTester->getDisplay(), true);
        self::assertSame(array_keys($summaryDocument), array_keys($printedDocument), '--json prints the result document');
        self::assertArrayHasKey('messages', $printedDocument['cases'][0], 'the printed document carries the traces');

        $storedRun = $probeCommand->suiteRunsServiceDouble->insertedAgentEvalSuiteRun;
        self::assertNotNull($storedRun);
        self::assertSame('app:ai-agent:eval-tools', $storedRun->command);
        self::assertSame('all', $storedRun->suiteLabel);
        self::assertSame(basename($runDirectory), $storedRun->logDirectoryName);
        self::assertSame(['model' => null, 'flavor' => 'call', 'arms' => [], 'repeat' => 2, 'skill' => null, 'case' => null], $storedRun->getRunOptions()->toArray());
    }

    /**
     * A child that ended just under its ceiling but is polled just after it keeps its result: the ceiling counts only
     * while the child runs. A child that still runs past its ceiling is a timed-out row; one within it is no row yet.
     */
    public function testTheCeilingIsCheckedOnlyWhileTheChildRuns(): void
    {
        $probeCommand = new AgentEvalToolsProbeCommand();
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->caseId = 'catalog.late-poll';
        $agentEvalSuiteTask->timeoutSeconds = 60;
        $endedChild = new ScriptedChildProcessDouble();
        $endedChild->ceilingPassed = true;
        $endedChild->printedOutput = 'noise {"cases":[{"case":"catalog.late-poll","passed":true}]}';
        $runningChild = new ScriptedChildProcessDouble();
        $runningChild->running = true;
        $overdueChild = new ScriptedChildProcessDouble();
        $overdueChild->running = true;
        $overdueChild->ceilingPassed = true;

        $findRowOfFinishedChild = new ReflectionMethod($probeCommand, 'findRowOfFinishedChild');
        $endedRow = $findRowOfFinishedChild->invoke($probeCommand, AgentEvalSuiteRunningCase::forStartedProcess($agentEvalSuiteTask, $endedChild, 0.0, ''));
        $runningRow = $findRowOfFinishedChild->invoke($probeCommand, AgentEvalSuiteRunningCase::forStartedProcess($agentEvalSuiteTask, $runningChild, 0.0, ''));
        $overdueRow = $findRowOfFinishedChild->invoke($probeCommand, AgentEvalSuiteRunningCase::forStartedProcess($agentEvalSuiteTask, $overdueChild, 0.0, ''));

        self::assertSame(['case' => 'catalog.late-poll', 'passed' => true], $endedRow?->toArray(), 'an ended child keeps its result');
        self::assertSame(0, $endedChild->ceilingChecks);
        self::assertNull($runningRow);
        self::assertSame(1, $runningChild->ceilingChecks);
        self::assertSame(AgentEvalCaseRow::CHILD_PROCESS_TIMED_OUT_ERROR, $overdueRow?->getError());
    }

    public function testAChildThatRunsIntoItsCeilingOrPrintsNothingIsAFailedRowAndTheRunGoesOn(): void
    {
        $probeCommand = new AgentEvalToolsProbeCommand();
        $probeCommand->childBehaviourByCaseId = ['slow.case' => AgentEvalToolsProbeCommand::CHILD_OUTLIVES_ITS_CEILING, 'silent.case' => AgentEvalToolsProbeCommand::CHILD_PRINTS_NOTHING];
        $fixturePath = $this->writeFixture([
            ['id' => 'slow.case', 'prompt' => 'slow', 'agentSpaceId' => 7, 'access' => 'read'],
            ['id' => 'silent.case', 'prompt' => 'silent', 'agentSpaceId' => 7, 'access' => 'read'],
            ['id' => 'spaceless.case', 'prompt' => 'which space?', 'access' => 'read'],
            ['id' => 'queue.case', 'prompt' => 'queued', 'agentSpaceId' => 7, 'executionMode' => 'async'],
        ]);

        $commandTester = $this->runProbeCommand($probeCommand, ['--fixture' => $fixturePath, '--parallel' => '2', '--no-prune' => true]);

        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $runDirectory = (glob($this->resultsDirectory . '/*-default-all', GLOB_ONLYDIR) ?: [''])[0];
        self::assertSame(
            ['case' => 'slow.case', 'passed' => false, 'failures' => ["the case's child process exceeded its 600s ceiling and was killed (raise the case's timeoutSeconds when the flow is legitimately that long)"], 'error' => 'child process timed out'],
            json_decode((string)file_get_contents($runDirectory . '/slow.case.json'), true)
        );
        self::assertSame(['case' => 'silent.case', 'passed' => false, 'error' => 'no parseable JSON from child process'], json_decode((string)file_get_contents($runDirectory . '/silent.case.json'), true));

        $displayLines = explode("\n", $commandTester->getDisplay());
        self::assertContains(sprintf('  SKIP %-32s no agent space (case or --agentSpaceId) — %s', 'spaceless.case', 'which space?'), $displayLines, 'a case without a scope is named, not dropped');
        self::assertContains(sprintf('  SKIP %-32s %s', 'queue.case', 'async-only case — covered by the job-queue runner (--include-async to force inline)'), $displayLines);
        self::assertContains('Parallel: 2 read-only case(s) @ concurrency 2 + 0 sequential case(s) (edit/memory-flow)', $displayLines);
        self::assertContains('  timed out slow.case', $displayLines);
        self::assertContains('  done silent.case', $displayLines);
        self::assertContains('Overall: 0/2 (0%)', $displayLines);
        self::assertContains('Saved: ' . $runDirectory . '/', $displayLines);
    }

    public function testARunThatNamesAnAccountRunsTheCasesWithoutASpaceAtAccountLevel(): void
    {
        $probeCommand = new AgentEvalToolsProbeCommand();
        $runnerServiceDouble = new AgentEvalRunnerServiceWithScriptedApplicabilityDouble();
        $probeCommand->runnerServiceDouble = $runnerServiceDouble;
        $probeCommand->ownShapeRowFields = ['passed' => true];
        $fixturePath = $this->writeFixture([
            ['id' => 'own.spaceless', 'prompt' => 'which tickets are open?', 'expect' => ['tool' => 'searchCatalog']],
        ]);
        $resultFile = $this->temporaryDirectory . '/account-level/result.json';

        $commandTester = $this->runProbeCommand($probeCommand, ['--fixture' => $fixturePath, '--parallel' => '1', '--no-save' => true, '--result-file' => $resultFile, '--accountId' => '40']);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringNotContainsString('SKIP', $commandTester->getDisplay(), 'an account-level run plans the case');
        self::assertSame([40], $runnerServiceDouble->fallbackAccountIdsOfChecks);
        $resultDocument = json_decode((string)file_get_contents($resultFile), true);
        self::assertSame([1, 1], [$resultDocument['total'], $resultDocument['passed']]);
    }

    public function testTheRunInThisProcessReportsTheCasesThatDoNotApplyAndPrintsTheReport(): void
    {
        $probeCommand = new AgentEvalToolsProbeCommand();
        $runnerServiceDouble = new AgentEvalRunnerServiceWithScriptedApplicabilityDouble();
        $runnerServiceDouble->notApplicableDetailByCaseId = ['locked.case' => 'the space is locked'];
        $probeCommand->runnerServiceDouble = $runnerServiceDouble;
        $probeCommand->ownShapeRowFields = [
            'passed' => true,
            'performance' => ['modelCalls' => 4, 'ownerTurns' => 1, 'agentSeconds' => 12.0, 'secondsPerOwnerTurn' => [12.0]],
            'totalTokens' => 800,
            'costUsd' => 0.0123,
            'durationMs' => 1200,
        ];
        $fixturePath = $this->writeFixture([
            ['id' => 'own.case', 'prompt' => 'own', 'agentSpaceId' => 7, 'expect' => ['tool' => 'searchCatalog']],
            ['id' => 'locked.case', 'prompt' => 'locked', 'agentSpaceId' => 7, 'expect' => ['tool' => ['searchCatalog', 'listCatalog']]],
            ['id' => 'vision.case', 'prompt' => 'look', 'agentSpaceId' => 7, 'requiresVision' => true],
        ]);
        $resultFile = $this->temporaryDirectory . '/parent/result.json';

        $commandTester = $this->runProbeCommand($probeCommand, ['--fixture' => $fixturePath, '--parallel' => '1', '--no-save' => true, '--result-file' => $resultFile]);

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $resultDocument = json_decode((string)file_get_contents($resultFile), true);
        self::assertSame(
            ['skill' => 'Catalog.Search', 'case' => 'locked.case', 'tool' => 'searchCatalog|listCatalog', 'flavor' => 'call', 'agentSpaceId' => 7, 'passed' => null, 'outcome' => 'notApplicable', 'notApplicableReason' => 'ineligibleScope', 'notApplicableDetail' => 'the space is locked'],
            $resultDocument['cases'][1]
        );
        self::assertSame([1, 1, 1], [$resultDocument['total'], $resultDocument['passed'], $resultDocument['notApplicable']]);
        self::assertSame([null, null], $runnerServiceDouble->fallbackAgentSpaceIdsOfChecks, 'no scope option: the cases name their own');
        self::assertNull($probeCommand->suiteRunsServiceDouble->insertedAgentEvalSuiteRun, 'a run a parent reads keeps no record');

        $performanceRollUpLines = $this->buildSpeedLinesOfDocument($resultDocument);
        self::assertSame(
            array_merge([
                '',
                'Catalog.Search',
                sprintf('  N/A  %-32s %s', 'locked.case', 'the space is locked'),
                sprintf('  SKIP %-32s needs a vision-capable model; %s cannot see images', 'vision.case', 'the default model'),
                '',
                'Overall: 1/1 (100%) · 1 not applicable on their workspace (not counted)',
                'Model cost: $0.0123 total · $0.0100/case · $0.0123/pass · 800 tok  ( — excl. judge/driver)',
                'Performance: 4.00 model calls/case · 1.00 owner turns/case · 12.0 s/owner turn (median 12.0, p90 12.0) · 12.0 s agent time/case',
            ], $performanceRollUpLines, ['']),
            explode("\n", $commandTester->getDisplay())
        );
    }

    public function testAJudgeOrDriverModelTheCatalogDoesNotKnowIsRefusedWithTheInvalidExitCodeBeforeAnyCaseRuns(): void
    {
        $fixturePath = $this->writeFixture([['id' => 'own.case', 'prompt' => 'own', 'agentSpaceId' => 7, 'expect' => ['tool' => 'searchCatalog']]]);
        foreach (['--judge-model', '--driver-model'] as $modelOptionName) {
            $probeCommand = new AgentEvalToolsProbeCommand();
            $resultFile = $this->temporaryDirectory . '/refused/result.json';

            $commandTester = $this->runProbeCommand($probeCommand, ['--fixture' => $fixturePath, '--judge' => true, $modelOptionName => 'ALIBABA.QWEN4_MAX.json', '--no-save' => true, '--result-file' => $resultFile]);

            self::assertSame(Command::INVALID, $commandTester->getStatusCode(), $modelOptionName);
            self::assertSame(
                $modelOptionName . ': ' . sprintf(AgentEvalJudgedRunService::UNKNOWN_MODEL_NAME_MESSAGE, 'ALIBABA.QWEN4_MAX.json'),
                trim($commandTester->getDisplay())
            );
            self::assertFileDoesNotExist($resultFile, 'no case ran');
            self::assertNull($probeCommand->suiteRunsServiceDouble->insertedAgentEvalSuiteRun);
        }
    }

    public function testAChildGetsTheCaseTheScopeOptionsAsGivenAndEveryOptionOfTheRun(): void
    {
        $probeCommand = new AgentEvalToolsProbeCommand();
        $input = new ArrayInput(['--agentSpaceId' => '7', '--ignore-budget' => true], $probeCommand->getDefinition());
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->caseId = 'catalog.compare';
        $agentEvalSuiteTask->fixturePath = '/fixtures/Tools/Catalog.Search.json';
        $runSettings = AgentEvalToolsRunSettings::newInstance();
        $runSettings->modelName = 'VENDOR.MODEL';
        $runSettings->ownerDeviceSurface = OwnerDevice::SURFACES[0];
        $runSettings->flavor = AgentEvalSuiteTask::FLAVOR_JUDGE;
        $runSettings->judgeModelName = 'VENDOR.JUDGE';
        $runSettings->driverModelName = 'VENDOR.DRIVER';
        $runSettings->includeAsyncOnlyCases = true;
        $runSettings->persistConversations = true;
        $runSettings->evalArms = ['decision-model' => 'off', 'store-snapshot' => 'on'];
        $runSettings->agentEvalArms = AgentEvalArms::newInstance();

        self::assertSame(
            [
                'php', '-d', 'memory_limit=2G', DDDService::instance()->getRootDir() . '/bin/console', 'app:ai-agent:eval-tools',
                '--case=catalog.compare', '--json', '--no-save', '--fixture=/fixtures/Tools/Catalog.Search.json',
                '--agentSpaceId=7', '--model=VENDOR.MODEL', '--owner-device=' . OwnerDevice::SURFACES[0],
                '--judge', '--judge-model=VENDOR.JUDGE', '--driver-model=VENDOR.DRIVER',
                '--ignore-budget', '--include-async', '--persist-conversations', '--decision-model=off', '--store-snapshot',
            ],
            $probeCommand->buildChildProcessArgumentsOfTask($agentEvalSuiteTask, $input, $runSettings)
        );

        $runSettings->persistConversations = false;
        $runSettings->flavor = AgentEvalSuiteTask::FLAVOR_CALL;
        $runSettings->evalArms = [];
        $childArguments = $probeCommand->buildChildProcessArgumentsOfTask($agentEvalSuiteTask, new ArrayInput([], $probeCommand->getDefinition()), $runSettings);
        self::assertSame('--transient', $childArguments[array_key_last($childArguments)]);
        self::assertNotContains('--judge', $childArguments);
        self::assertNotContains('--ignore-budget', $childArguments);
    }

    public function testARunWithACaseFilterRunsInThisProcessAndARepeatedOneInChildProcesses(): void
    {
        $runSettings = AgentEvalToolsRunSettings::newInstance();
        $runSettings->parallel = 10;
        self::assertTrue($runSettings->runsInChildProcesses(), 'the default: every case in a child of its own');
        $runSettings->caseFilter = 'a';
        self::assertFalse($runSettings->runsInChildProcesses(), 'a child process itself runs one case in its process');
        $runSettings->repeat = 3;
        self::assertTrue($runSettings->runsInChildProcesses(), 'each repetition is a child of its own, also for one case');
        $runSettings->repeat = 1;
        $runSettings->caseFilter = null;
        $runSettings->parallel = 1;
        self::assertFalse($runSettings->runsInChildProcesses());

        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->taskId = 'default|call|tools|a';
        $repeatedTask = $agentEvalSuiteTask->buildRepetition(2);
        self::assertSame('default|call|tools|a#2', $repeatedTask->taskId, 'the runs of one case are told apart');
        self::assertSame('a.rep2.json', DDDService::instance()->getService(AgentEvalResultFilesService::class)->getCaseFileNameOfToolsRun('a', $repeatedTask->repetition));
    }

    /**
     * The speed lines of the report: the performance roll-up's own two lines (their format is the roll-up's), each with
     * a capital first letter.
     *
     * @param array<string, mixed> $resultDocument
     * @return string[]
     */
    protected function buildSpeedLinesOfDocument(array $resultDocument): array
    {
        return array_map('ucfirst', AgentEvalRunSummary::fromArray($resultDocument)->performanceRollUp->buildSpeedKpiLines());
    }

    /**
     * The repetitions of the summary's rows, in ascending order (the rows are in the order the children finished).
     *
     * @param array<int, array<string, mixed>> $summaryCaseRows
     * @return int[]
     */
    protected function sortedRepetitionsOf(array $summaryCaseRows): array
    {
        $repetitions = array_map('intval', array_column($summaryCaseRows, 'repetition'));
        sort($repetitions);
        return $repetitions;
    }

    /**
     * Writes a fixture of the skill `Catalog.Search` with `$caseObjects` into the temporary folder.
     *
     * @param array<int, array<string, mixed>> $caseObjects
     */
    protected function writeFixture(array $caseObjects): string
    {
        return $this->writeTemporaryJsonFile('fixtures/Catalog.Search.json', ['skill' => 'Catalog.Search', 'cases' => $caseObjects]);
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function writeTemporaryJsonFile(string $relativePath, array $document): string
    {
        $filePath = $this->temporaryDirectory . '/' . $relativePath;
        $this->writeTemporaryFile($filePath, (string)json_encode($document, AgentEvalResultFilesService::JSON_FLAGS));
        return $filePath;
    }

    /**
     * @param array<string, mixed> $commandInput
     */
    protected function runProbeCommand(AgentEvalToolsProbeCommand $probeCommand, array $commandInput): CommandTester
    {
        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute($commandInput, ['capture_stderr_separately' => true]);
        return $commandTester;
    }
}
