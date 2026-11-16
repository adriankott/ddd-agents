<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixture;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixtures;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionProperty;

/**
 * The planning of a parallel suite: how a case touches its agent space (read, write, exclusive) and which resource
 * it shares, which cases of which fixtures become tasks on which models and flavors, the scope of a task, the
 * duration priors, and the kinds of task an application plans itself.
 */
class AgentEvalSuitePlanServiceTest extends IntegrationTestCase
{
    /** @var mixed The memo of the effect catalog before the test */
    protected mixed $effectsByToolNameBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $effectsMemo = new ReflectionProperty(MCPToolEffectCatalog::class, 'effectsByToolName');
        $this->effectsByToolNameBeforeTest = $effectsMemo->getValue();
        $effectsMemo->setValue(null, [
            'getNotes' => new MCPToolEffect(MCPToolEffect::READ),
            'searchImages' => new MCPToolEffect(MCPToolEffect::READ),
            'listReplies' => new MCPToolEffect(MCPToolEffect::READ),
            'getTaskRuns' => new MCPToolEffect(MCPToolEffect::READ),
            'pinNote' => new MCPToolEffect(MCPToolEffect::WRITE),
            'createRecurringTaskSchedule' => new MCPToolEffect(MCPToolEffect::WRITE),
            'grantMandate' => new MCPToolEffect(MCPToolEffect::WRITE),
        ]);
    }

    protected function onTearDown(): void
    {
        (new ReflectionProperty(MCPToolEffectCatalog::class, 'effectsByToolName'))->setValue(null, $this->effectsByToolNameBeforeTest);
    }

    protected function planService(): AgentEvalSuitePlanService
    {
        $this->replaceService(AgentEvalSuitePlanService::class, new AgentEvalSuitePlanServiceWithResourceGroupsDouble());
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        return $agentEvalSuitePlanService;
    }

    public function testACaseIsAReadAWriteOrExclusiveByItsShape(): void
    {
        $planService = $this->planService();

        self::assertSame(AgentEvalSuiteTask::ACCESS_READ, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['id' => 'a']), 'Notes.Rankings'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['id' => 'a', 'restore' => ['readTool' => 'x']]), 'Notes.Profile'));
        self::assertSame('profileAndListings', $planService->resolveResourceKeyOfCase(AgentEvalCase::fromArray(['id' => 'a']), 'Notes.Listings'), 'one family of grants, one lane');
        self::assertSame('profileAndListings', $planService->resolveResourceKeyOfCase(AgentEvalCase::fromArray(['id' => 'a']), 'Notes.Profile'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['id' => 'a', 'expect' => ['stateAfterRun' => ['readTool' => 'x']]]), 'Notes.Posts'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['id' => 'a', 'sequential' => true]), 'Pages.Edit'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_EXCLUSIVE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['id' => 'a', 'turns' => ['one', 'two']]), 'Memory.Management'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_EXCLUSIVE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['id' => 'a', 'restore' => []]), 'Assistant.Grants'), 'an empty restore block is a restore block');
        self::assertSame(AgentEvalSuiteTask::ACCESS_EXCLUSIVE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['id' => 'a', 'restore' => [], 'exclusive' => true]), 'Notes.Profile'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_READ, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['id' => 'a']), 'Assistant.Grants'), 'a read of an exclusive fixture stays a read');
    }

    public function testACaseThatExpectsAChangingToolWritesWithoutARestoreBlock(): void
    {
        $planService = $this->planService();

        self::assertSame(AgentEvalSuiteTask::ACCESS_READ, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['expect' => ['tool' => ['getNotes', 'searchImages'], 'requiredTools' => 'listReplies']]), 'Notes.Posts'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_READ, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['expect' => ['tool' => 'update_task_plan', 'requiredTools' => ['set_task_status', 'load_skills']]]), 'PlanCreation'), 'the loop\'s own tools change the conversation, not the agent space');
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['expect' => ['tool' => ['getNotes', 'pinNote']]]), 'Notes.Posts'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['expect' => ['tool' => 'getTaskRuns', 'requiredTools' => ['createRecurringTaskSchedule']]]), 'Assistant.Tasks'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_EXCLUSIVE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['expect' => ['tool' => 'grantMandate']]), 'Assistant.Grants'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['expect' => ['tool' => 'aToolNoCatalogKnows']]), 'Notes.Posts'), 'a tool that does not declare itself read-only may change something');
        self::assertSame(AgentEvalSuiteTask::ACCESS_READ, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['expect' => ['tool' => '']]), 'Notes.Posts'), 'an empty name expects nothing');
    }

    public function testTheDeclaredAccessAndLaneOfACaseDecide(): void
    {
        $planService = $this->planService();

        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['access' => 'write', 'successRubric' => 'three posts are scheduled']), 'Notes.Posts'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_READ, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['access' => ' READ ', 'expect' => ['tool' => 'pinNote']]), 'Notes.Posts'), 'the declaration is read trimmed and in any case');
        self::assertSame(AgentEvalSuiteTask::ACCESS_EXCLUSIVE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['access' => 'exclusive']), 'Notes.Rankings'));
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $planService->resolveAccessOfCase(AgentEvalCase::fromArray(['access' => 'none', 'restore' => []]), 'Notes.Rankings'), 'an access a case cannot declare is ignored');

        self::assertSame('replies', $planService->resolveResourceKeyOfCase(AgentEvalCase::fromArray(['id' => 'a']), 'Notes.Settings'));
        self::assertSame('replies', $planService->resolveResourceKeyOfCase(AgentEvalCase::fromArray(['id' => 'a']), 'Notes.Replies'));
        self::assertSame('Notes.Keywords', $planService->resolveResourceKeyOfCase(AgentEvalCase::fromArray(['id' => 'a']), 'Notes.Keywords'), 'an unlisted fixture is its own resource');
        self::assertSame('openingHours', $planService->resolveResourceKeyOfCase(AgentEvalCase::fromArray(['id' => 'a', 'lane' => ' openingHours ']), 'Notes.Profile'), 'a case may name its lane');
    }

    public function testACaseFilterNamesOneCaseOrAList(): void
    {
        $options = AgentEvalSuitePlanOptions::forModelsAndFlavors(['MODEL'], ['call']);
        self::assertTrue($options->matchesCaseFilter('posts.list'));
        $options->caseFilter = '';
        self::assertTrue($options->matchesCaseFilter('posts.list'));
        $options->caseFilter = 'posts.list';
        self::assertTrue($options->matchesCaseFilter('posts.list'));
        $options->caseFilter = 'replies.latest, posts.list';
        self::assertTrue($options->matchesCaseFilter('posts.list'));
        $options->caseFilter = 'posts.list.colloquial,replies.latest';
        self::assertFalse($options->matchesCaseFilter('posts.list'));
    }

    public function testToolsTasksCoverEveryModelAndFlavorAndSkipWhatCannotRun(): void
    {
        $planService = $this->planService();
        $fixtures = $this->fixtures(['/fixtures/Notes.Profile.json' => ['skill' => 'Notes.Profile', 'cases' => [
            ['id' => 'profile.read', 'agentSpaceId' => 1],
            ['id' => 'profile.write', 'agentSpaceId' => 1, 'restore' => ['readTool' => 'getNotes'], 'timeoutSeconds' => 30],
            ['id' => 'profile.async', 'agentSpaceId' => 1, 'executionMode' => 'async'],
            ['id' => 'profile.vision', 'agentSpaceId' => 1, 'requiresVision' => true, 'timeoutSeconds' => 900],
            ['id' => 'profile.nowhere'],
            ['prompt' => 'a case without an id'],
            'no case at all',
        ]]]);
        $options = AgentEvalSuitePlanOptions::forModelsAndFlavors(['MODEL', 'SEEING'], ['call', 'judge']);
        $options->visionCapabilityByModelName = ['SEEING' => true];

        $tasks = $planService->buildToolsTasks('tools', $fixtures, $options);

        self::assertCount(2 * 2 * 2 + 2, $tasks, 'read + write per model and flavor, the vision case on the seeing model only');
        self::assertContains('SEEING|judge|tools|profile.vision', $tasks->getTaskIds());
        self::assertNotContains('MODEL|call|tools|profile.vision', $tasks->getTaskIds());
        $writeTask = $tasks->getByTaskId('MODEL|call|tools|profile.write');
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $writeTask->access);
        self::assertSame('profileAndListings', $writeTask->resourceKey);
        self::assertSame(1, $writeTask->getScopeId());
        self::assertSame(60, $writeTask->timeoutSeconds, 'a case declares no ceiling under a minute');
        self::assertSame('/fixtures/Notes.Profile.json', $writeTask->fixturePath);
        self::assertSame(35.0, $writeTask->estimatedSeconds);
        self::assertSame(900, $tasks->getByTaskId('SEEING|judge|tools|profile.vision')->timeoutSeconds);
        self::assertSame(90.0, $tasks->getByTaskId('SEEING|judge|tools|profile.vision')->estimatedSeconds);
        self::assertSame(600, $tasks->getByTaskId('MODEL|call|tools|profile.read')->timeoutSeconds);

        $options = AgentEvalSuitePlanOptions::forModelsAndFlavors(['MODEL'], ['call']);
        $options->caseFilter = 'profile.write';
        self::assertCount(1, $planService->buildToolsTasks('tools', $fixtures, $options));
        $options->caseFilter = null;
        $options->includeAsyncOnlyCases = true;
        self::assertCount(3, $planService->buildToolsTasks('tools', $fixtures, $options), 'async cases only on request');
        $options->includeAsyncOnlyCases = false;
        $options->fallbackAgentSpaceId = 9;
        self::assertCount(3, $planService->buildToolsTasks('tools', $fixtures, $options), 'the fallback agent space admits a case without one');
        self::assertSame(9, $planService->buildToolsTasks('tools', $fixtures, $options)->getByTaskId('MODEL|call|tools|profile.nowhere')->getScopeId());
        $options->skillFilter = 'Notes.Profile';
        self::assertCount(3, $planService->buildToolsTasks('tools', $fixtures, $options), 'the skill filter names the fixture by its skill');
        $options->skillFilter = 'Notes.Other';
        self::assertCount(0, $planService->buildToolsTasks('tools', $fixtures, $options));
    }

    public function testTheSkillFilterAlsoNamesAFixtureByItsFileName(): void
    {
        $planService = $this->planService();
        $fixtures = $this->fixtures(['/fixtures/Notes.Combined.json' => ['skill' => 'Notes.Profile', 'cases' => [['id' => 'a', 'agentSpaceId' => 1]]]]);
        $options = AgentEvalSuitePlanOptions::forModelsAndFlavors([null], ['call']);
        $options->skillFilter = 'Notes.Combined';

        $tasks = $planService->buildToolsTasks('multistep', $fixtures, $options);

        self::assertSame(['default|call|multistep|a'], $tasks->getTaskIds());
        self::assertNull($tasks->first()->modelName);
    }

    public function testSelectionCasesReadWhatTheirExpectedSkillReads(): void
    {
        $planService = $this->planService();
        $fixtures = $this->fixtures(['/fixtures/Selection.json' => ['cases' => [
            ['id' => 's1', 'agentSpaceId' => 3, 'expectedSkill' => 'Notes.Listings', 'restore' => []],
            ['id' => 's2', 'agentSpaceId' => 3, 'expectedSkill' => 'Notes.Keywords'],
            ['id' => 's3', 'agentSpaceId' => 3],
            ['id' => 's4'],
        ]]]);
        $options = AgentEvalSuitePlanOptions::forModelsAndFlavors(['MODEL'], ['judge']);

        $tasks = $planService->buildSelectionTasks($fixtures, $options);

        self::assertSame(['MODEL|call|selection|s1', 'MODEL|call|selection|s2', 'MODEL|call|selection|s3'], $tasks->getTaskIds(), 'the deterministic flavor only; a case without a scope is left out');
        self::assertSame(['profileAndListings', 'Notes.Keywords', 'selection'], array_map(static fn (AgentEvalSuiteTask $task): string => $task->resourceKey, $tasks->getElements()));
        foreach ($tasks->getElements() as $task) {
            self::assertSame(AgentEvalSuiteTask::ACCESS_READ, $task->access);
            self::assertSame(AgentEvalSuiteTask::KIND_SELECTION_CASE, $task->kind);
            self::assertSame('selection', $task->suite);
            self::assertSame(25.0, $task->estimatedSeconds);
        }
    }

    public function testRecallCasesWriteOneSharedResourceAndNameTheirOwnScope(): void
    {
        $planService = $this->planService();
        $fixtures = $this->fixtures(['/fixtures/Recall.json' => ['cases' => [['id' => 'r1', 'agentSpaceId' => 4], ['id' => 'r2']]]]);
        $options = AgentEvalSuitePlanOptions::forModelsAndFlavors(['MODEL'], ['call']);
        $options->fallbackAgentSpaceId = 9;

        $tasks = $planService->buildRecallTasks($fixtures, $options);

        self::assertSame(['MODEL|call|recall|r1'], $tasks->getTaskIds(), 'the fallback does not stand in for a recall case');
        self::assertSame(AgentEvalSuiteTask::ACCESS_WRITE, $tasks->first()->access);
        self::assertSame(AgentEvalSuitePlanService::RESOURCE_CONVERSATION_RECALL, $tasks->first()->resourceKey);
        self::assertSame(45.0, $tasks->first()->estimatedSeconds);
    }

    public function testAnApplicationPlansItsOwnKindAndExtendsTheDurationPriors(): void
    {
        $planService = $this->planService();
        $fixtures = $this->fixtures(['/fixtures/Waved.json' => ['cases' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]]]);
        $options = AgentEvalSuitePlanOptions::forModelsAndFlavors(['MODEL'], ['judge']);

        $tasks = $planService->buildTasksOfKind(AgentEvalSuitePlanServiceWithResourceGroupsDouble::KIND_WAVED_CASE, 'waved', $fixtures, $options);

        self::assertSame([0, 0, 1], array_map(static fn (AgentEvalSuiteTask $task): int => $task->waveIndex, $tasks->getElements()));
        self::assertSame(480.0, $tasks->first()->estimatedSeconds);
        $plan = AgentEvalSuiteSchedule::forProcesses(8)->summarizePlan($tasks);
        self::assertSame(960, $plan->estimatedWallSeconds, 'two waves one after the other');
        self::assertSame(['waved' => 2], $plan->wavesByWaveGroup);
        self::assertSame(2, $planService->buildTasksOfKind(AgentEvalSuiteTask::KIND_TOOLS_CASE, 'tools', $this->fixtures(['/fixtures/T.json' => ['cases' => [['id' => 'x', 'agentSpaceId' => 1]]]]), AgentEvalSuitePlanOptions::forModelsAndFlavors(['MODEL'], ['call', 'judge']))->count());
    }

    public function testTheModuleAloneGroupsNoFixtureAndPlansNoKindOfItsOwn(): void
    {
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);

        self::assertSame(AgentEvalSuitePlanService::class, $agentEvalSuitePlanService::class);
        self::assertSame([], $agentEvalSuitePlanService->getResourceGroupByFixture());
        self::assertSame([], $agentEvalSuitePlanService->getExclusiveFixtureNames());
        self::assertSame(0, $agentEvalSuitePlanService->buildTasksOfKind('anotherKind', 'other', $this->fixtures(['/fixtures/O.json' => ['cases' => [['id' => 'a', 'agentSpaceId' => 1]]]]), AgentEvalSuitePlanOptions::forModelsAndFlavors(['MODEL'], ['call']))->count());
        $taskOfAnUnknownKind = AgentEvalSuiteTask::newInstance();
        $taskOfAnUnknownKind->kind = 'anotherKind';
        self::assertSame(35.0, $agentEvalSuitePlanService->estimateSecondsOfTask(AgentEvalSuiteTask::newInstance()));
        self::assertSame(60.0, $agentEvalSuitePlanService->estimateSecondsOfTask($taskOfAnUnknownKind), 'a kind without a prior');
        self::assertContains('load_skills', $agentEvalSuitePlanService->getLoopToolNames());
    }

    public function testFixturesAreReadFromAFolder(): void
    {
        $folder = sys_get_temp_dir() . '/agent-eval-suite-fixtures-' . bin2hex(random_bytes(4));
        mkdir($folder);
        file_put_contents($folder . '/B.json', (string)json_encode(['skill' => 'Notes.B', 'cases' => [['id' => 'b']]]));
        file_put_contents($folder . '/A.json', (string)json_encode(['cases' => [['id' => 'a']], 'purposeName' => ' Notes.Review ']));
        file_put_contents($folder . '/NoCases.json', (string)json_encode(['skill' => 'Notes.C']));
        file_put_contents($folder . '/Broken.json', '{');
        try {
            $fixtures = $this->planService()->findFixturesInFolder($folder);
        } finally {
            array_map('unlink', glob($folder . '/*.json') ?: []);
            rmdir($folder);
        }

        self::assertSame(['A', 'B'], array_map(static fn (AgentEvalFixture $agentEvalFixture): string => $agentEvalFixture->getName(), $fixtures->getElements()));
        $fixtureA = $fixtures->first();
        self::assertSame('A', $fixtureA->getSkillName(), 'a fixture without a skill is named by its file');
        self::assertSame('Notes.Review', $fixtureA->resolvePurposeNameOfCase($fixtureA->getCases()->first()));
        self::assertSame('Notes.Own', $fixtureA->resolvePurposeNameOfCase(AgentEvalCase::fromArray(['purposeName' => ' Notes.Own '])), 'the case\'s own purpose wins');
        self::assertSame(['B'], array_map(static fn (AgentEvalFixture $agentEvalFixture): string => $agentEvalFixture->getName(), $fixtures->buildFixturesNamed(['B', 'C'])->getElements()));
    }

    /**
     * @param array $decodedFixturesByPath fixture path → decoded fixture
     */
    protected function fixtures(array $decodedFixturesByPath): AgentEvalFixtures
    {
        $agentEvalFixtures = AgentEvalFixtures::newInstance();
        foreach ($decodedFixturesByPath as $fixturePath => $decodedFixture) {
            $agentEvalFixture = AgentEvalFixture::fromDecodedFile((string)$fixturePath, $decodedFixture);
            $agentEvalFixtures->add($agentEvalFixture);
        }
        return $agentEvalFixtures;
    }
}
