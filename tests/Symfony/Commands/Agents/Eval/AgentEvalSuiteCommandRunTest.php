<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AgentEvalResultFilesTestCase;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AgentEvalSuiteRunsServiceWithoutDatabaseDouble;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A whole suite run of the command, its children played by `Fixtures/SuiteRun/fake-child.php` (one process per case,
 * one at a time): the suites come from the configuration (`AI.evalSuite.suites`), every case's row is collected from
 * its result file, a missing result becomes a failed row with the child's error tail, a deadlock earns one retry at
 * the head of the queue (not a refused restore), the harness lines of the error output stay on the row, every case is
 * written with its trace beside a summary without it, the record and the stored run follow, and the exit code says
 * whether every case passed. The run with a named baseline compares; a stop drops the queue; no matching case is
 * INVALID; a dry run prints the plan as JSON and writes nothing. The line formats are pinned in
 * {@see AgentEvalSuiteCommandOutputTest}; the formats of the files by the tests of the result files service.
 */
class AgentEvalSuiteCommandRunTest extends AgentEvalResultFilesTestCase
{
    public const string SUITE_RUN_FIXTURE_DIRECTORY = __DIR__ . '/Fixtures/SuiteRun';

    protected AgentEvalSuiteRunsServiceWithoutDatabaseDouble $suiteRunsServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->suiteRunsServiceDouble = new AgentEvalSuiteRunsServiceWithoutDatabaseDouble();
        $this->replaceService(AgentEvalSuiteRunsService::class, $this->suiteRunsServiceDouble);
        $this->replaceService(AgentEvalResultFilesService::class, new AgentEvalResultFilesServiceInTestFolderDouble($this->resultsDirectory));
        $this->applySuiteConfiguration();
    }

    public function testASuiteRunCollectsRetriesWritesRecordsAndFailsOnAFailedCase(): void
    {
        $commandTester = $this->runSuite(['--label' => 'first']);
        $standardOutput = $commandTester->getDisplay();
        $suiteDirectory = $this->findSuiteDirectory('first');
        $runDirectory = $suiteDirectory . '/default-call';

        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        self::assertStringStartsWith("Eval suite — 6 case runs · models [default] · flavors [call, judge]\n  suites:  tools 6\n  access:  write 6\n  lanes:   7:Catalog 6\n", $standardOutput);
        self::assertStringContainsString((string)file_get_contents(AgentEvalSuiteOutputScenarios::FIXTURE_DIRECTORY . '/retry-line.txt'), $standardOutput);
        self::assertSame(1, substr_count($standardOutput, 'RETRY'), 'a refused restore is not retried');
        self::assertMatchesRegularExpression('/  PASS call    tools      catalog\.pass {32}\s+\d+ s · 3 calls · 1 turns  \[1\/6 · 0 running · 5 queued\]/', $standardOutput);
        self::assertStringContainsString("         ! the child process left no result (exit code 3): PHP Fatal error: boom in the child\n", $standardOutput);
        self::assertStringContainsString("         the preparation step answered an error\n", $standardOutput);
        self::assertStringContainsString("Verdict: correctness FAILED (2/5) · performance not compared (no baseline)\n", $standardOutput);

        $passedCaseFile = $this->readJsonFile($runDirectory . '/tools-catalog.pass.json');
        self::assertSame(
            ['skill', 'case', 'tool', 'passed', 'matchedTool', 'performance', 'failures', 'totalTokens', 'costUsd', 'durationMs', 'messages', 'suite', 'fixture', 'model', 'flavor', 'access', 'lane', 'processSeconds'],
            array_keys($passedCaseFile),
            'the child row keeps its keys in place; the task facts follow'
        );
        self::assertSame(['Catalog', null, 'call', 'write', '7:Catalog'], [$passedCaseFile['fixture'], $passedCaseFile['model'], $passedCaseFile['flavor'], $passedCaseFile['access'], $passedCaseFile['lane']]);
        self::assertSame('Deadlock found when trying to get lock', $this->readJsonFile($runDirectory . '/tools-catalog.retry.json')['retriedAfter']);
        self::assertSame(['passed' => false, 'error' => 'the child process left no result (exit code 3): PHP Fatal error: boom in the child'], array_slice($this->readJsonFile($runDirectory . '/tools-catalog.no-result.json'), 0, 2));
        $refusedRestoreFile = $this->readJsonFile($runDirectory . '/tools-catalog.restore-refused.json');
        self::assertSame(['restore writeItems [ERROR] the write was refused'], $refusedRestoreFile['harnessLog']);
        self::assertTrue($refusedRestoreFile['restoreFailed']);
        self::assertSame([], preg_grep('/\.arguments\.json$/', glob($runDirectory . '/.result-*.json') ?: [], PREG_GREP_INVERT), 'the result files of the children are removed');
        self::assertFileDoesNotExist($suiteDirectory . '/_progress.json');

        $summary = $this->readJsonFile($runDirectory . '/_summary.json');
        $summaryCaseIds = array_column($summary['cases'], 'case');
        self::assertSame(['catalog.pass', 'catalog.fail', 'catalog.retry', 'catalog.no-result', 'catalog.restore-refused', 'catalog.not-applicable'], $summaryCaseIds, 'the retry ran first again, at the head of the queue');
        self::assertArrayNotHasKey('messages', $summary['cases'][0]);
        $retriedRow = $summary['cases'][2];
        self::assertSame('Deadlock found when trying to get lock', $retriedRow['retriedAfter']);
        self::assertSame(['error', 'processSeconds', 'harnessLog'], array_keys($retriedRow['firstAttempt']));
        self::assertSame(['restore capture getItems done'], $retriedRow['harnessLog']);

        $suiteRecord = $this->readJsonFile($suiteDirectory . '/_suite.json');
        self::assertSame(['startedAt', 'wallSeconds', 'processSeconds', 'speedUp', 'stopped', 'settings', 'plan', 'baseline', 'baselineChoice', 'verdict', 'total', 'passed', 'notApplicable', 'runs'], array_keys($suiteRecord));
        self::assertSame(['correctness' => 'FAILED', 'performance' => 'NOT_COMPARED'], $suiteRecord['verdict']);
        self::assertSame([5, 2, 1], [$suiteRecord['total'], $suiteRecord['passed'], $suiteRecord['notApplicable']]);
        self::assertSame(['default|call'], array_keys($suiteRecord['runs']));
        self::assertSame(['tools:catalog.restore-refused'], $suiteRecord['runs']['default|call']['restoreFailedCases']);
        self::assertSame(['tools:catalog.retry'], $suiteRecord['runs']['default|call']['retriedCases']);

        $storedRun = $this->suiteRunsServiceDouble->insertedAgentEvalSuiteRun;
        self::assertNotNull($storedRun);
        self::assertSame('app:ai-agent:eval-suite', $storedRun->command);
        self::assertSame(basename($suiteDirectory), $storedRun->logDirectoryName);
        self::assertSame(
            ['models' => ['default'], 'flavors' => ['call', 'judge'], 'suites' => ['tools'], 'arms' => [], 'persistedConversations' => true, 'stopped' => false, 'verdict' => ['correctness' => 'FAILED', 'performance' => 'NOT_COMPARED'], 'baseline' => null],
            $storedRun->getRunOptions()->toArray()
        );

        $childArguments = json_decode((string)file_get_contents($runDirectory . '/.result-tools-catalog.pass.json.arguments.json'), true);
        self::assertSame(
            ['app:ai-agent:eval-tools', '--case=catalog.pass', '--fixture=' . self::SUITE_RUN_FIXTURE_DIRECTORY . '/Catalog/Catalog.json', '--result-file=' . $runDirectory . '/.result-tools-catalog.pass.json', '--ignore-budget', '--json', '--persist-conversations', '--no-save', '--no-prune'],
            $childArguments
        );
    }

    public function testARunWithANamedBaselineComparesAndAPassingRunSucceeds(): void
    {
        $this->runSuite(['--label' => 'first', '--case' => 'catalog.pass,catalog.fail']);
        $baselineDirectoryName = basename($this->findSuiteDirectory('first'));

        $commandTester = $this->runSuite(['--label' => 'second', '--case' => 'catalog.pass', '--baseline' => $baselineDirectoryName]);
        $standardOutput = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString("baseline: $baselineDirectoryName (named) — 1 of the 1 planned cases in common\n", $standardOutput);
        self::assertStringContainsString("    against the baseline (1 common cases, tolerance 15 %):\n      inside the tolerance\n", $standardOutput);
        self::assertStringContainsString('Verdict: correctness PASSED (1/1) · performance OK', $standardOutput);
        $suiteRecord = $this->readJsonFile($this->findSuiteDirectory('second') . '/_suite.json');
        self::assertSame($baselineDirectoryName, $suiteRecord['baseline']);
        self::assertTrue($suiteRecord['runs']['default|call']['baselineComparison']['comparable']);
    }

    public function testAStopDropsTheQueueAndFailsTheRun(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->requestStopBeforeTheRun();
        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute($this->buildRunOptions(['--label' => 'stopped']));
        $standardOutput = $commandTester->getDisplay();

        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        self::assertStringContainsString("Stop requested — 6 queued case(s) dropped; every case that changes no live state is ended, running writes finish and restore (a second signal ends them too).\n", $standardOutput);
        $suiteRecord = $this->readJsonFile($this->findSuiteDirectory('stopped') . '/_suite.json');
        self::assertTrue($suiteRecord['stopped']);
        self::assertSame(['correctness' => 'FAILED', 'performance' => 'NOT_COMPARED'], $suiteRecord['verdict']);
        self::assertSame([], $suiteRecord['runs']);
    }

    public function testNoMatchingCaseIsInvalidAndADryRunPrintsThePlanAsJsonAndWritesNothing(): void
    {
        $commandTester = $this->runSuite(['--case' => 'catalog.unknown']);
        self::assertSame(Command::INVALID, $commandTester->getStatusCode());
        self::assertSame("No case matched — nothing to run.\n", $commandTester->getDisplay());

        $commandTester = $this->runSuite(['--dry-run' => true, '--json' => true]);
        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $dryRunDocument = json_decode($commandTester->getDisplay(), true);
        self::assertSame(['settings', 'plan'], array_keys($dryRunDocument));
        self::assertSame(
            ['models', 'flavors', 'suites', 'arms', 'persistedConversations', 'maxProcesses', 'isolateWholeScope', 'configuredReasoningEffortByModel', 'platformDecisionLayer'],
            array_keys($dryRunDocument['settings'])
        );
        self::assertSame(6, $dryRunDocument['plan']['tasks']);
        self::assertSame([], glob($this->resultsDirectory . '/*') ?: [], 'a dry run writes nothing');
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function runSuite(array $options): CommandTester
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        $commandTester->execute($this->buildRunOptions($options));
        return $commandTester;
    }

    /**
     * One process at a time, no head start, no stagger: the cases run in the order of the queue.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function buildRunOptions(array $options): array
    {
        return ['--max-processes' => '1', '--head-start-seconds' => '0', '--stagger-ms' => '0'] + $options;
    }

    protected function buildProbeCommand(): AgentEvalSuiteProbeCommand
    {
        $this->applySuiteConfiguration();
        $probeCommand = new AgentEvalSuiteProbeCommand('app:ai-agent:eval-suite');
        $probeCommand->childScriptPath = self::SUITE_RUN_FIXTURE_DIRECTORY . '/fake-child.php';
        return $probeCommand;
    }

    /**
     * The configuration of the suites as an application's file states it. No key is empty: the configuration reads its
     * file again for an empty or missing value and would put the module's defaults back. Applied again before every
     * command is built (a run reads keys of other files on the way, which can read this file again).
     */
    protected function applySuiteConfiguration(): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = (array)$configTree->getValue();
        $configValues['AI']['evalSuite'] = [
            'resultsDirectory' => $this->resultsDirectory,
            'resourceGroupByFixture' => ['Unrelated' => 'unrelated'],
            'exclusiveFixtures' => ['Unrelated'],
            'suites' => ['tools' => ['folder' => self::SUITE_RUN_FIXTURE_DIRECTORY . '/Catalog', 'kind' => 'toolsCase', 'flavors' => ['call']]],
            'fixturePaths' => ['tools' => self::SUITE_RUN_FIXTURE_DIRECTORY . '/Catalog'],
        ];
        $configTree->setValue(null, $configValues);
    }

    protected function findSuiteDirectory(string $label): string
    {
        $suiteDirectories = glob($this->resultsDirectory . '/*-suite-' . $label, GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $suiteDirectories);
        return $suiteDirectories[0];
    }

    /** @return array<string, mixed> */
    protected function readJsonFile(string $filePath): array
    {
        return (array)json_decode((string)file_get_contents($filePath), true);
    }
}
