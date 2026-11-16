<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowsByRun;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixtures;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionProperty;

/**
 * What the evaluation commands take from the planning: every task and every options object is created through the
 * override points; a repeated case runs as one task per run with the run keys `<task id>#<k>`; the suites and the
 * fixture paths come from `AI.evalSuite`; the rows of a suite run are kept per run in the order they came in.
 */
class AgentEvalSuitePlanServiceCommandSupportTest extends IntegrationTestCase
{
    /** @var array<string, mixed>|null The config values before the test */
    protected ?array $configTreeBefore = null;

    /** @var string A temporary folder of fixtures */
    protected string $fixtureDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configTreeBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : null;
        $this->fixtureDirectory = sys_get_temp_dir() . '/agent-eval-plan-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureDirectory . '/Catalog', 0775, true);
        file_put_contents($this->fixtureDirectory . '/Catalog/Catalog.Items.json', (string)json_encode(['skill' => 'Catalog.Items', 'cases' => [
            ['id' => 'catalog.list', 'prompt' => 'list', 'agentSpaceId' => 7, 'expect' => ['tool' => 'listItems']],
            ['id' => 'catalog.other', 'prompt' => 'other', 'expect' => ['tool' => 'listItems']],
        ]]));
        file_put_contents($this->fixtureDirectory . '/selection.json', (string)json_encode(['cases' => []]));
    }

    protected function onTearDown(): void
    {
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configTreeBefore ?? []);
        foreach ([$this->fixtureDirectory . '/Catalog/Catalog.Items.json', $this->fixtureDirectory . '/selection.json'] as $fixtureFile) {
            @unlink($fixtureFile);
        }
        @rmdir($this->fixtureDirectory . '/Catalog');
        @rmdir($this->fixtureDirectory);
    }

    public function testTasksAndOptionsAreCreatedThroughTheOverridePoints(): void
    {
        $planServiceDouble = new AgentEvalSuitePlanServiceCreatingOwnClassesDouble();
        $planOptions = $planServiceDouble->buildPlanOptions(['VENDOR.MODEL_A', null], ['call']);
        $planOptions->fallbackAgentSpaceId = 9;

        $agentEvalSuiteTasks = $planServiceDouble->buildToolsTasks('tools', $planServiceDouble->findFixturesInFolder($this->fixtureDirectory . '/Catalog'), $planOptions);

        self::assertInstanceOf(AgentEvalSuitePlanOptionsOfAnApplication::class, $planOptions);
        self::assertSame(['VENDOR.MODEL_A', null], $planOptions->modelNames);
        self::assertCount(4, $agentEvalSuiteTasks->getElements());
        foreach ($agentEvalSuiteTasks->getElements() as $agentEvalSuiteTask) {
            self::assertInstanceOf(AgentEvalSuiteTaskOfAnApplication::class, $agentEvalSuiteTask);
        }
        self::assertSame([7, 9, 7, 9], array_map(static fn (AgentEvalSuiteTask $task): ?int => $task->getScopeId(), array_values($agentEvalSuiteTasks->getElements())));
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        self::assertSame(AgentEvalSuiteTask::class, $agentEvalSuitePlanService->createTask()::class, 'the module creates its own class');
        self::assertSame(AgentEvalSuitePlanOptions::class, $agentEvalSuitePlanService->createPlanOptions()::class);
    }

    public function testARepeatedCaseRunsAsOneTaskPerRunWithItsOwnRunKey(): void
    {
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::fromTasks($this->task('a'), $this->task('b'));

        $repeatedTasks = $agentEvalSuiteTasks->buildRepeatedTasks(3);
        $singleTasks = $agentEvalSuiteTasks->buildRepeatedTasks(1);

        self::assertSame(['m|call|tools|a#1', 'm|call|tools|a#2', 'm|call|tools|a#3', 'm|call|tools|b#1', 'm|call|tools|b#2', 'm|call|tools|b#3'], $repeatedTasks->getTaskIds());
        self::assertSame([1, 2, 3, 1, 2, 3], array_map(static fn (AgentEvalSuiteTask $task): int => $task->repetition, array_values($repeatedTasks->getElements())));
        self::assertSame(['a', 'a', 'a', 'b', 'b', 'b'], array_map(static fn (AgentEvalSuiteTask $task): string => $task->caseId, array_values($repeatedTasks->getElements())));
        self::assertTrue($repeatedTasks->first()?->isRepetition());
        self::assertSame(['m|call|tools|a', 'm|call|tools|b'], $singleTasks->getTaskIds(), 'a case that runs once keeps its task id');
        self::assertSame(0, $singleTasks->first()?->repetition);
        self::assertSame('m|call|tools|a', $agentEvalSuiteTasks->first()?->taskId, 'the planned task stays as it was');
    }

    public function testTheScheduleRunsTwoRunsOfOneReadingCaseSideBySide(): void
    {
        $repeatedTasks = AgentEvalSuiteTasks::fromTasks($this->task('a'))->buildRepeatedTasks(2);
        $agentEvalSuiteSchedule = AgentEvalSuiteSchedule::forProcesses(4);

        $runnableTasks = $agentEvalSuiteSchedule->selectRunnableTasks($repeatedTasks, AgentEvalSuiteTasks::newInstance());

        self::assertSame(['m|call|tools|a#1', 'm|call|tools|a#2'], $runnableTasks->getTaskIds());
    }

    public function testTheSuitesAndTheFixturePathsComeFromTheConfiguration(): void
    {
        $this->setEvalSuiteConfig([
            'suites' => [
                'tools' => ['folder' => $this->fixtureDirectory . '/Catalog', 'kind' => AgentEvalSuiteTask::KIND_TOOLS_CASE],
                'selection' => ['folder' => 'evals/Selection', 'kind' => AgentEvalSuiteTask::KIND_SELECTION_CASE, 'flavors' => ['call']],
                'widgets' => ['folder' => 'evals/Widgets', 'kind' => AgentEvalSuiteTask::KIND_TOOLS_CASE, 'default' => false],
                'broken' => ['kind' => AgentEvalSuiteTask::KIND_TOOLS_CASE],
            ],
            'fixturePaths' => ['selection' => $this->fixtureDirectory . '/selection.json', 'tools' => 'evals/Tools/'],
        ]);
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        $projectDirectory = DDDService::instance()->getRootDir();

        $agentEvalSuiteDefinitions = $agentEvalSuitePlanService->getSuiteDefinitions();

        self::assertSame(['tools', 'selection', 'widgets'], $agentEvalSuiteDefinitions->getSuiteNames(), 'an entry without a folder is skipped');
        self::assertSame(['tools', 'selection'], $agentEvalSuiteDefinitions->getDefaultSuiteNames());
        self::assertSame($projectDirectory . '/evals/Selection', $agentEvalSuiteDefinitions->getBySuiteName('selection')?->fixtureFolder);
        self::assertSame(AgentEvalSuiteTask::KIND_SELECTION_CASE, $agentEvalSuiteDefinitions->getBySuiteName('selection')?->taskKind);
        self::assertSame(['call'], $agentEvalSuiteDefinitions->getBySuiteName('selection')?->buildPlannedFlavors(['call', 'judge']));
        self::assertSame([], $agentEvalSuiteDefinitions->getBySuiteName('selection')?->buildPlannedFlavors(['judge']), 'not planned in the judge flavor alone');
        self::assertSame(['call', 'judge'], $agentEvalSuiteDefinitions->getBySuiteName('tools')?->buildPlannedFlavors(['call', 'judge']));
        self::assertNull($agentEvalSuiteDefinitions->getBySuiteName('broken'));
        self::assertSame($this->fixtureDirectory . '/selection.json', $agentEvalSuitePlanService->findFixturePath('selection'));
        self::assertSame($projectDirectory . '/evals/Tools', $agentEvalSuitePlanService->findFixturePath('tools'));
        self::assertNull($agentEvalSuitePlanService->findFixturePath('recall'));
        self::assertSame([$this->fixtureDirectory . '/selection.json'], $agentEvalSuitePlanService->findFixtureFilesAt($this->fixtureDirectory . '/selection.json'));
        self::assertSame([$this->fixtureDirectory . '/Catalog/Catalog.Items.json'], $agentEvalSuitePlanService->findFixtureFilesAt($this->fixtureDirectory . '/Catalog'));
        self::assertSame([], $agentEvalSuitePlanService->findFixtureFilesAt($this->fixtureDirectory . '/missing'));
    }

    public function testOneFixtureFileIsReadAsItsDecodedObject(): void
    {
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        file_put_contents($this->fixtureDirectory . '/no-object.json', '"text"');

        $agentEvalFixture = $agentEvalSuitePlanService->findFixtureAt($this->fixtureDirectory . '/Catalog/Catalog.Items.json');
        $emptyFixture = $agentEvalSuitePlanService->findFixtureAt($this->fixtureDirectory . '/selection.json');

        self::assertSame('Catalog.Items', $agentEvalFixture?->getName());
        self::assertSame(2, $agentEvalFixture?->getCases()->count());
        self::assertSame([], $emptyFixture?->getApplicationValue('cases'), 'a file without cases is still a fixture');
        self::assertNull($agentEvalSuitePlanService->findFixtureAt($this->fixtureDirectory . '/no-object.json'));
        self::assertNull($agentEvalSuitePlanService->findFixtureAt($this->fixtureDirectory . '/missing.json'));
        unlink($this->fixtureDirectory . '/no-object.json');
    }

    public function testTheModuleConfiguresNoSuiteAndNoFixturePath(): void
    {
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);

        self::assertSame([], $agentEvalSuitePlanService->getSuiteDefinitions()->getSuiteNames());
        self::assertNull($agentEvalSuitePlanService->findFixturePath('tools'));
        self::assertInstanceOf(AgentEvalFixtures::class, $agentEvalSuitePlanService->findFixturesInFolder($this->fixtureDirectory . '/missing'));
    }

    public function testTheRowsOfASuiteRunAreKeptPerRunInTheOrderTheyCameIn(): void
    {
        $agentEvalCaseRowsByRun = AgentEvalCaseRowsByRun::newInstance();
        $judgeTask = $this->task('j');
        $judgeTask->flavor = AgentEvalSuiteTask::FLAVOR_JUDGE;
        $defaultModelTask = $this->task('d');
        $defaultModelTask->modelName = null;

        $agentEvalCaseRowsByRun->addRowOfTask($this->task('a'), AgentEvalCaseRow::buildForUnparseableChildOutput('a'));
        $agentEvalCaseRowsByRun->addRowOfTask($judgeTask, AgentEvalCaseRow::buildForUnparseableChildOutput('j'));
        $agentEvalCaseRowsByRun->addRowOfTask($this->task('b'), AgentEvalCaseRow::buildForUnparseableChildOutput('b'));
        $agentEvalCaseRowsByRun->addRowOfTask($defaultModelTask, AgentEvalCaseRow::buildForUnparseableChildOutput('d'));

        self::assertSame(['m|call', 'm|judge', 'default|call'], array_map(static fn ($rowsOfRun): string => $rowsOfRun->runKey, array_values($agentEvalCaseRowsByRun->getElements())));
        self::assertSame(['a', 'b', 'j', 'd'], array_map(static fn (AgentEvalCaseRow $row): string => $row->getCaseId(), array_values($agentEvalCaseRowsByRun->buildAllRows()->getElements())));
        self::assertSame(2, $agentEvalCaseRowsByRun->getRowsOfRunKey('m|call')?->caseRows->count());
        self::assertSame('default', $agentEvalCaseRowsByRun->getRowsOfRunKey('default|call')?->modelName, 'the run key names the conversation default');
        self::assertSame('judge', $agentEvalCaseRowsByRun->getRowsOfRunKey('m|judge')?->flavor);
        self::assertNull($agentEvalCaseRowsByRun->getRowsOfRunKey('x|call'));
        self::assertSame(4, $agentEvalCaseRowsByRun->countRows());
    }

    protected function task(string $caseId): AgentEvalSuiteTask
    {
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->caseId = $caseId;
        $agentEvalSuiteTask->suite = 'tools';
        $agentEvalSuiteTask->modelName = 'm';
        $agentEvalSuiteTask->agentSpaceId = 1;
        $agentEvalSuiteTask->resourceKey = 'catalog';
        $agentEvalSuiteTask->taskId = 'm|call|tools|' . $caseId;
        return $agentEvalSuiteTask;
    }

    /** @param array<string, mixed> $evalSuiteConfig */
    protected function setEvalSuiteConfig(array $evalSuiteConfig): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues['AI']['evalSuite'] = $evalSuiteConfig + ['resultsDirectory' => 'var/agent-evals', 'resourceGroupByFixture' => [], 'exclusiveFixtures' => []];
        $configTree->setValue(null, $configValues);
    }
}
