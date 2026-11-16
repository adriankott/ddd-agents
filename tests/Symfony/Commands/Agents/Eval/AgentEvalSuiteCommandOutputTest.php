<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\AgentEvalSuiteCommand;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\AgentSpacesServiceConsoleScopeDouble;
use InvalidArgumentException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * What the suite command prints and what its children get, pinned to the files in `Fixtures/SuiteReport/`. Every
 * expected text there was printed by the application's former suite command (before the command moved into the
 * module) from the documents of the same inputs ({@see AgentEvalSuiteOutputScenarios}): its plan and report methods
 * with the `toArray()` documents of the plan, the settings and the record; its case and retry lines with the same rows
 * and counters; its child command lines with the same tasks and options. Two words differ, and only where the module
 * reads them from the application: the label of the decision-layer line (the former command printed its own label,
 * here the module's `decision:`) and the word for an agent space in two report lines (the former command printed its
 * application's word, here the module's default wording); in the child command lines the application's scope option
 * stands where the former command passed its own (`--externalSpaceKey=north` of this test's agent-spaces service) and
 * the console of the kernel project directory where it named its own.
 */
class AgentEvalSuiteCommandOutputTest extends IntegrationTestCase
{
    public function testThePlanLinesAreTheFormerLines(): void
    {
        $bufferedOutput = new BufferedOutput();
        $probeCommand = $this->buildProbeCommand();

        $probeCommand->exposeWritePlan($bufferedOutput, AgentEvalSuiteOutputScenarios::buildPlanWithLanesAndWaves(), AgentEvalSuiteOutputScenarios::buildTransientRunSettings());
        self::assertSame($this->readExpected('plan-with-lanes.txt'), $bufferedOutput->fetch());

        $probeCommand->exposeWritePlan($bufferedOutput, AgentEvalSuiteOutputScenarios::buildPlanWithoutLanes(), AgentEvalSuiteOutputScenarios::buildFixedEffortRunSettings());
        self::assertSame($this->readExpected('plan-without-lanes.txt'), $bufferedOutput->fetch());
    }

    public function testTheReportLinesAreTheFormerLines(): void
    {
        $bufferedOutput = new BufferedOutput();

        $this->buildProbeCommand()->exposeWriteReport($bufferedOutput, AgentEvalSuiteOutputScenarios::buildRecordOfTwoRuns(), '/runs/2025-11-16_000000-suite');

        self::assertSame($this->readExpected('report.txt'), $bufferedOutput->fetch());
    }

    public function testTheFinishedCaseLinesAreTheFormerLines(): void
    {
        $bufferedOutput = new BufferedOutput();
        $probeCommand = $this->buildProbeCommand();

        foreach (AgentEvalSuiteOutputScenarios::buildFinishedRows() as $rowIndex => $agentEvalCaseRow) {
            $probeCommand->exposeWriteFinishedCaseLines(
                $bufferedOutput,
                AgentEvalSuiteOutputScenarios::buildRunStateWithFinishedCount(10 + $rowIndex),
                AgentEvalSuiteOutputScenarios::buildTaskOfRow($agentEvalCaseRow, $rowIndex),
                $agentEvalCaseRow
            );
        }

        self::assertSame($this->readExpected('case-lines.txt'), $bufferedOutput->fetch());
    }

    public function testTheChildCommandLinesAreTheFormerLines(): void
    {
        $this->replaceService(AgentSpacesService::class, new AgentSpacesServiceConsoleScopeDouble());
        $probeCommand = $this->buildProbeCommand();
        $expectedCommandLines = json_decode(str_replace('{rootDir}', DDDService::instance()->getRootDir(), $this->readExpected('child-command-lines.json')), true);
        $optionSets = [
            'scope-judges-arms' => ['--externalSpaceKey' => 'north', '--judge-model' => 'm-judge', '--driver-model' => 'm-driver', '--include-async' => true],
            'transient-enforced' => ['--transient' => true, '--' . AgentEvalSuiteCommand::OPTION_ENFORCE_BUDGET => true],
        ];
        $tasks = [
            'tools-judge' => [AgentEvalSuiteTask::KIND_TOOLS_CASE, AgentEvalSuiteTask::FLAVOR_JUDGE, 'tools', 'm-one'],
            'tools-call' => [AgentEvalSuiteTask::KIND_TOOLS_CASE, AgentEvalSuiteTask::FLAVOR_CALL, 'tools', null],
            'selection' => [AgentEvalSuiteTask::KIND_SELECTION_CASE, AgentEvalSuiteTask::FLAVOR_CALL, 'selection', 'm-one'],
            'recall' => [AgentEvalSuiteTask::KIND_RECALL_CASE, AgentEvalSuiteTask::FLAVOR_CALL, 'recall', 'm-one'],
        ];

        foreach ($optionSets as $optionSetName => $optionValues) {
            foreach ($tasks as $taskName => [$kind, $flavor, $suite, $modelName]) {
                $commandLine = $probeCommand->exposeBuildProcessArguments(
                    $this->buildTask($kind, $flavor, $suite, $modelName),
                    new ArrayInput($optionValues, $probeCommand->getDefinition()),
                    '/runs/suite/m-one-call/.result-tools-catalog.case.json',
                    $optionSetName === 'scope-judges-arms' ? ['reasoning-effort' => 'low', 'decision-model-skill-preload' => 'on'] : []
                );
                self::assertSame($expectedCommandLines[$optionSetName][$taskName], $commandLine, "$optionSetName / $taskName");
            }
        }
    }

    public function testAKindWithoutAChildCommandIsRefused(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No child command for the task kind ownKind');
        $probeCommand->exposeBuildProcessArguments(
            $this->buildTask('ownKind', AgentEvalSuiteTask::FLAVOR_JUDGE, 'own', 'm-one'),
            new ArrayInput([], $probeCommand->getDefinition()),
            '/runs/suite/.result.json',
            []
        );
    }

    /**
     * A case that ended on a database deadlock or a transport timeout said nothing about the agent: it runs once more;
     * an assertion failure, an unknown error, the timeout of the case itself and a passed row are results.
     */
    public function testOnlyAnErrorRowWithAKnownInfrastructureTextIsRetried(): void
    {
        $retriedInfrastructureErrors = $this->buildProbeCommand()->exposeRetriedInfrastructureErrors();

        self::assertSame(
            ['Deadlock found when trying to get lock', 'Lock wait timeout exceeded', 'cURL error 28', 'cURL error 56', 'MySQL server has gone away', '/var/cache/'],
            $retriedInfrastructureErrors
        );
        self::assertSame('Deadlock found when trying to get lock', AgentEvalCaseRow::fromArray(['passed' => false, 'error' => 'An exception occurred while executing a query: SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'])->findInfrastructureError($retriedInfrastructureErrors));
        self::assertSame('cURL error 28', AgentEvalCaseRow::fromArray(['passed' => false, 'error' => 'cURL error 28: Operation timed out after 300000 milliseconds'])->findInfrastructureError($retriedInfrastructureErrors));
        self::assertSame('cURL error 56', AgentEvalCaseRow::fromArray(['passed' => false, 'error' => 'Model call transport failed: cURL error 56: Recv failure: Connection reset by peer'])->findInfrastructureError($retriedInfrastructureErrors));
        self::assertNull(AgentEvalCaseRow::fromArray(['passed' => false, 'failures' => ['tool not called: expected one of [getItems]']])->findInfrastructureError($retriedInfrastructureErrors), 'an assertion failure is a result');
        self::assertNull(AgentEvalCaseRow::fromArray(['passed' => false, 'error' => 'Space 1 not found'])->findInfrastructureError($retriedInfrastructureErrors), 'an unknown error is a result');
        self::assertNull(AgentEvalCaseRow::fromArray(['passed' => false, 'error' => "the case's child process exceeded its 600s ceiling and was ended"])->findInfrastructureError($retriedInfrastructureErrors), 'a timeout of the case is a result');
        self::assertNull(AgentEvalCaseRow::fromArray(['passed' => true, 'error' => 'cURL error 28'])->findInfrastructureError($retriedInfrastructureErrors));
    }

    public function testAStopSignalBeforeTheCasesStartEndsTheProcessAndOneAfterIsCounted(): void
    {
        $probeCommand = $this->buildProbeCommand();
        if (defined('SIGINT') && defined('SIGTERM')) {
            self::assertSame([constant('SIGINT'), constant('SIGTERM')], $probeCommand->getSubscribedSignals());
        }

        self::assertSame(130, $probeCommand->handleSignal(2), 'before the first case a signal ends the process as it always did');
        self::assertFalse($probeCommand->isStopRequested());

        $probeCommand->markCasesStarted();
        self::assertFalse($probeCommand->handleSignal(2), 'once cases run the suite stops gracefully');
        self::assertTrue($probeCommand->isStopRequested());
        self::assertFalse($probeCommand->isKillRequested());
        self::assertFalse($probeCommand->handleSignal(15));
        self::assertTrue($probeCommand->isKillRequested());
    }

    public function testTheModulesStopLineNamesNoKindOfItsOwn(): void
    {
        self::assertSame(
            'Stop requested — 6 queued case(s) dropped; every case that changes no live state is ended, running writes finish and restore (a second signal ends them too).',
            $this->buildProbeCommand()->exposeDescribeStopRequest(6)
        );
    }

    protected function buildProbeCommand(): AgentEvalSuiteProbeCommand
    {
        return new AgentEvalSuiteProbeCommand('app:ai-agent:eval-suite');
    }

    protected function buildTask(string $kind, string $flavor, string $suite, ?string $modelName): AgentEvalSuiteTask
    {
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->kind = $kind;
        $agentEvalSuiteTask->flavor = $flavor;
        $agentEvalSuiteTask->suite = $suite;
        $agentEvalSuiteTask->caseId = 'catalog.case';
        $agentEvalSuiteTask->fixturePath = '/fixtures/Catalog.json';
        $agentEvalSuiteTask->modelName = $modelName;
        return $agentEvalSuiteTask;
    }

    protected function readExpected(string $fileName): string
    {
        return (string)file_get_contents(AgentEvalSuiteOutputScenarios::FIXTURE_DIRECTORY . '/' . $fileName);
    }
}
