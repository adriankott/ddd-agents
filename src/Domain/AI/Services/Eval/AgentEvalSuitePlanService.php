<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalDurationPriors;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixture;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixtures;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteDefinition;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteDefinitions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Plans a parallel evaluation suite: fixtures in, tasks out ({@see AgentEvalSuiteTask}). Each task carries what the
 * schedule ({@see \DDD\Domain\AI\Entities\Evals\AgentEvalSuiteSchedule}) decides by: its access, its scope, its resource
 * group, its timeout and its duration prior. Reads fixture files; touches no database, no process, no clock.
 *
 * The module plans its own kinds of task: the cases of tools-style fixtures ({@see buildToolsTasks()}), the
 * skill-selection cases ({@see buildSelectionTasks()}) and the recall cases ({@see buildRecallTasks()}).
 *
 * Override points for an application (a same-path subclass under its own root namespace, with an alias of this id to
 * it in its service configuration; callers obtain this service through
 * `DDDService::instance()->getService(AgentEvalSuitePlanService::class)`):
 *
 *  - {@see createTask()} and {@see createPlanOptions()}: every task and every options object of a plan is created
 *    here (default: `newInstance()`, which gives the application's same-path class), so an application that keeps
 *    a value of its own on its task or options class gets its class everywhere;
 *  - {@see buildTasksOfApplicationKind()}: the tasks of the application's own kinds (default: none);
 *  - {@see assignScopeOfCaseToTask()}: the scope a case runs in when the application's fixtures name it by a key of
 *    their own (default: the case's `agentSpaceId`, else the options' fallback agent space);
 *  - {@see getDurationPriors()}: the duration priors, extended by the application's kinds;
 *  - {@see getResourceGroupByFixture()} and {@see getExclusiveFixtureNames()}: read from the configuration keys
 *    `AI.evalSuite.resourceGroupByFixture` and `AI.evalSuite.exclusiveFixtures` (empty in the module);
 *  - {@see getSuiteDefinitions()} and {@see findFixturePath()}: the suites a suite run can plan and the fixture
 *    files of the single evaluation commands, read from `AI.evalSuite.suites` and `AI.evalSuite.fixturePaths`
 *    (empty in the module: it ships no fixtures).
 */
class AgentEvalSuitePlanService extends Service
{
    /** @var string The configuration key of the resource group per fixture name */
    public const string CONFIG_KEY_RESOURCE_GROUP_BY_FIXTURE = 'AI.evalSuite.resourceGroupByFixture';

    /** @var string The configuration key of the fixtures whose writing cases run alone on their scope */
    public const string CONFIG_KEY_EXCLUSIVE_FIXTURES = 'AI.evalSuite.exclusiveFixtures';

    /** @var string The suite of the skill-selection cases */
    public const string SUITE_SELECTION = 'selection';

    /** @var string The suite of the recall cases */
    public const string SUITE_RECALL = 'recall';

    /** @var string The resource key of the recall cases: each seeds conversations the next one's search would find */
    public const string RESOURCE_CONVERSATION_RECALL = 'conversationRecall';

    /** @var string The resource key of a selection case whose fixture names no expected skill */
    public const string RESOURCE_SELECTION = 'selection';

    /** @var string The configuration key of the suites a suite run can plan */
    public const string CONFIG_KEY_SUITES = 'AI.evalSuite.suites';

    /** @var string The configuration key of the fixture file or folder of each single evaluation command */
    public const string CONFIG_KEY_FIXTURE_PATHS = 'AI.evalSuite.fixturePaths';

    /** @var int The wall-clock ceiling of a case's child process unless the case declares its own */
    public const int DEFAULT_CASE_TIMEOUT_SECONDS = 600;

    /** @var int The smallest wall-clock ceiling a case may declare */
    public const int MINIMUM_CASE_TIMEOUT_SECONDS = 60;

    /** @var float The duration prior of a task whose kind and flavor have none */
    public const float DEFAULT_ESTIMATED_SECONDS = 60.0;

    /**
     * A new task of the plan, the application's same-path class when it has one. Every task the planning builds is
     * created here; an application that keeps the scope on its own task class may refuse another class where it reads
     * the task.
     */
    public function createTask(): AgentEvalSuiteTask
    {
        return AgentEvalSuiteTask::newInstance();
    }

    /**
     * New options of a plan, the application's same-path class when it has one. A command creates the options of its
     * plan here ({@see buildPlanOptions()}).
     */
    public function createPlanOptions(): AgentEvalSuitePlanOptions
    {
        return AgentEvalSuitePlanOptions::newInstance();
    }

    /**
     * The options of a plan for the models and flavors of a run, created by {@see createPlanOptions()}.
     *
     * @param array $modelNames model names in order; null = the conversation default
     * @param string[] $flavors
     * @return AgentEvalSuitePlanOptions
     */
    public function buildPlanOptions(array $modelNames, array $flavors): AgentEvalSuitePlanOptions
    {
        $agentEvalSuitePlanOptions = $this->createPlanOptions();
        $agentEvalSuitePlanOptions->modelNames = array_values($modelNames);
        $agentEvalSuitePlanOptions->flavors = array_values(array_map('strval', $flavors));
        return $agentEvalSuitePlanOptions;
    }

    /**
     * The suites a suite run can plan (`AI.evalSuite.suites`), in the order of the configuration: per suite name its
     * fixture folder (relative to the kernel project directory unless absolute), the kind of its tasks, whether a run
     * without a suite list takes it (`default`, true when absent) and the flavors it runs in (`flavors`, every flavor
     * when absent). An entry without a folder or a kind is skipped. Empty in the module.
     */
    public function getSuiteDefinitions(): AgentEvalSuiteDefinitions
    {
        $agentEvalSuiteDefinitions = AgentEvalSuiteDefinitions::newInstance();
        $configuredSuites = Config::get(static::CONFIG_KEY_SUITES);
        foreach (is_array($configuredSuites) ? $configuredSuites : [] as $suiteName => $suiteFields) {
            if (!is_array($suiteFields) || !is_string($suiteFields['folder'] ?? null) || !is_string($suiteFields['kind'] ?? null)) {
                continue;
            }
            $agentEvalSuiteDefinition = AgentEvalSuiteDefinition::forSuite(
                (string)$suiteName,
                $this->resolveConfiguredPath($suiteFields['folder']),
                $suiteFields['kind'],
                (bool)($suiteFields['default'] ?? true),
                is_array($suiteFields['flavors'] ?? null) ? array_values(array_map('strval', $suiteFields['flavors'])) : []
            );
            $agentEvalSuiteDefinitions->add($agentEvalSuiteDefinition);
        }
        return $agentEvalSuiteDefinitions;
    }

    /**
     * The fixture file or folder of a single evaluation command (`AI.evalSuite.fixturePaths`, by the name the
     * command asks for, e.g. `tools` or `selection`), as an absolute path; null when the configuration names none
     * (the module names none: it ships no fixtures).
     */
    public function findFixturePath(string $fixturePathName): ?string
    {
        $fixturePaths = Config::get(static::CONFIG_KEY_FIXTURE_PATHS);
        $fixturePath = is_array($fixturePaths) ? ($fixturePaths[$fixturePathName] ?? null) : null;
        return is_string($fixturePath) && trim($fixturePath) !== '' ? $this->resolveConfiguredPath(trim($fixturePath)) : null;
    }

    /**
     * The fixture of one file: its decoded JSON document; null when the file is missing or holds no JSON object or
     * list (a top-level JSON list passes too and gives a fixture without named keys). The single-file counterpart of
     * {@see findFixturesInFolder()}, which also requires a `cases` list.
     */
    public function findFixtureAt(string $fixturePath): ?AgentEvalFixture
    {
        if (!is_file($fixturePath)) {
            return null;
        }
        $decodedFixture = json_decode((string)file_get_contents($fixturePath), true);
        return is_array($decodedFixture) ? AgentEvalFixture::fromDecodedFile($fixturePath, $decodedFixture) : null;
    }

    /**
     * The fixture files a path names: the file itself, or the `*.json` files of a folder sorted by name (as `glob()`
     * returns them); none for a path that does not exist.
     *
     * @return string[]
     */
    public function findFixtureFilesAt(string $fixturePath): array
    {
        if (is_file($fixturePath)) {
            return [$fixturePath];
        }
        return is_dir($fixturePath) ? (glob(rtrim($fixturePath, '/') . '/*.json') ?: []) : [];
    }

    /** A configured path as an absolute path: a relative one is taken relative to the kernel project directory. */
    protected function resolveConfiguredPath(string $configuredPath): string
    {
        return str_starts_with($configuredPath, '/') ? rtrim($configuredPath, '/') : DDDService::instance()->getRootDir() . '/' . rtrim($configuredPath, '/');
    }

    /**
     * The decoded fixtures of a folder, one per `*.json` file in the order of the folder listing; a file that is no
     * JSON object with a `cases` list is skipped.
     */
    public function findFixturesInFolder(string $folder): AgentEvalFixtures
    {
        $agentEvalFixtures = AgentEvalFixtures::newInstance();
        foreach ((glob($folder . '/*.json') ?: []) as $fixturePath) {
            $decodedFixture = json_decode((string)file_get_contents($fixturePath), true);
            if (is_array($decodedFixture) && is_array($decodedFixture['cases'] ?? null)) {
                $agentEvalFixture = AgentEvalFixture::fromDecodedFile($fixturePath, $decodedFixture);
                $agentEvalFixtures->add($agentEvalFixture);
            }
        }
        return $agentEvalFixtures;
    }

    /**
     * The tasks of one kind for the cases of `$agentEvalFixtures`: the module's kinds here, every other kind through
     * {@see buildTasksOfApplicationKind()}.
     *
     * @param string $kind the kind of the tasks ({@see AgentEvalSuiteTask}'s `KIND_*` or an application's own)
     * @param string $suite the suite the tasks belong to (the selection and recall kinds use their own suite)
     * @param AgentEvalFixtures $agentEvalFixtures the fixtures of the suite
     * @param AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions the models, flavors and filters of the run
     * @return AgentEvalSuiteTasks
     */
    public function buildTasksOfKind(string $kind, string $suite, AgentEvalFixtures $agentEvalFixtures, AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions): AgentEvalSuiteTasks
    {
        return match ($kind) {
            AgentEvalSuiteTask::KIND_TOOLS_CASE => $this->buildToolsTasks($suite, $agentEvalFixtures, $agentEvalSuitePlanOptions),
            AgentEvalSuiteTask::KIND_SELECTION_CASE => $this->buildSelectionTasks($agentEvalFixtures, $agentEvalSuitePlanOptions),
            AgentEvalSuiteTask::KIND_RECALL_CASE => $this->buildRecallTasks($agentEvalFixtures, $agentEvalSuitePlanOptions),
            default => $this->buildTasksOfApplicationKind($kind, $suite, $agentEvalFixtures, $agentEvalSuitePlanOptions),
        };
    }

    /**
     * The tasks of an application's own kind. The module plans none.
     *
     * @param string $kind the application's kind
     * @param string $suite the suite the tasks belong to
     * @param AgentEvalFixtures $agentEvalFixtures the fixtures of the suite
     * @param AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions the models, flavors and filters of the run
     * @return AgentEvalSuiteTasks
     */
    public function buildTasksOfApplicationKind(string $kind, string $suite, AgentEvalFixtures $agentEvalFixtures, AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions): AgentEvalSuiteTasks
    {
        return AgentEvalSuiteTasks::newInstance();
    }

    /**
     * The tasks of tools-style fixtures for every model × flavor × fixture × case, in that order. A fixture is skipped
     * when the skill filter names neither its skill nor its file name; a case when the case filter does not name it,
     * when its flow exists only on the job queue (unless such cases are planned), when it requires vision on a model
     * that cannot see, or when it has no scope.
     */
    public function buildToolsTasks(string $suite, AgentEvalFixtures $agentEvalFixtures, AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions): AgentEvalSuiteTasks
    {
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::newInstance();
        foreach ($agentEvalSuitePlanOptions->modelNames as $modelName) {
            foreach ($agentEvalSuitePlanOptions->flavors as $flavor) {
                foreach ($agentEvalFixtures->getElements() as $agentEvalFixture) {
                    if (!$agentEvalFixture->hasCases() || !$agentEvalSuitePlanOptions->matchesSkillFilter($agentEvalFixture)) {
                        continue;
                    }
                    foreach ($agentEvalFixture->getCases()->getElements() as $agentEvalCase) {
                        $agentEvalSuiteTask = $this->buildToolsTaskOfCase($suite, $agentEvalFixture, $agentEvalCase, $modelName, (string)$flavor, $agentEvalSuitePlanOptions);
                        if ($agentEvalSuiteTask !== null) {
                            $agentEvalSuiteTasks->add($agentEvalSuiteTask);
                        }
                    }
                }
            }
        }
        return $agentEvalSuiteTasks;
    }

    /** The task of one tools-style case on one model in one flavor; null when the case is not planned there. */
    protected function buildToolsTaskOfCase(
        string $suite,
        AgentEvalFixture $agentEvalFixture,
        AgentEvalCase $agentEvalCase,
        ?string $modelName,
        string $flavor,
        AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions
    ): ?AgentEvalSuiteTask {
        if (!$agentEvalSuitePlanOptions->matchesCaseFilter($agentEvalCase->getId())) {
            return null;
        }
        if (!$agentEvalSuitePlanOptions->includeAsyncOnlyCases && $agentEvalCase->isAsyncOnly()) {
            return null;
        }
        if ($agentEvalCase->requiresVision() && !$agentEvalSuitePlanOptions->modelCanSeeImages($modelName)) {
            return null;
        }
        $agentEvalSuiteTask = $this->createTask();
        if (!$this->assignScopeOfCaseToTask($agentEvalSuiteTask, $agentEvalCase, $agentEvalSuitePlanOptions, true)) {
            return null;
        }
        $agentEvalSuiteTask->kind = AgentEvalSuiteTask::KIND_TOOLS_CASE;
        $agentEvalSuiteTask->suite = $suite;
        $agentEvalSuiteTask->caseId = $agentEvalCase->getId();
        $agentEvalSuiteTask->fixturePath = $agentEvalFixture->getPath();
        $agentEvalSuiteTask->modelName = $modelName;
        $agentEvalSuiteTask->flavor = $flavor;
        $agentEvalSuiteTask->access = $this->resolveAccessOfCase($agentEvalCase, $agentEvalFixture->getName());
        $agentEvalSuiteTask->resourceKey = $this->resolveResourceKeyOfCase($agentEvalCase, $agentEvalFixture->getName());
        $agentEvalSuiteTask->timeoutSeconds = max(static::MINIMUM_CASE_TIMEOUT_SECONDS, $agentEvalCase->getTimeoutSeconds() ?? static::DEFAULT_CASE_TIMEOUT_SECONDS);
        $agentEvalSuiteTask->taskId = $agentEvalSuiteTask->getRunKey() . '|' . $suite . '|' . $agentEvalSuiteTask->caseId;
        $agentEvalSuiteTask->estimatedSeconds = $this->estimateSecondsOfTask($agentEvalSuiteTask);
        return $agentEvalSuiteTask;
    }

    /**
     * The skill-selection cases for every model × fixture × case: one owner message each, read-only, the deterministic
     * flavor only. A selection case reads whatever its expected skill reads: its resource group is the group of the
     * fixture named like the expected skill, else the expected skill itself.
     */
    public function buildSelectionTasks(AgentEvalFixtures $agentEvalFixtures, AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions): AgentEvalSuiteTasks
    {
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::newInstance();
        $resourceGroupByFixture = $this->getResourceGroupByFixture();
        foreach ($agentEvalSuitePlanOptions->modelNames as $modelName) {
            foreach ($agentEvalFixtures->getElements() as $agentEvalFixture) {
                foreach ($agentEvalFixture->getCases()->getElements() as $agentEvalCase) {
                    if (!$agentEvalSuitePlanOptions->matchesCaseFilter($agentEvalCase->getId())) {
                        continue;
                    }
                    $agentEvalSuiteTask = $this->createTask();
                    if (!$this->assignScopeOfCaseToTask($agentEvalSuiteTask, $agentEvalCase, $agentEvalSuitePlanOptions, true)) {
                        continue;
                    }
                    $expectedSkillName = $agentEvalCase->getExpectedSkillName();
                    $agentEvalSuiteTask->kind = AgentEvalSuiteTask::KIND_SELECTION_CASE;
                    $agentEvalSuiteTask->suite = static::SUITE_SELECTION;
                    $agentEvalSuiteTask->caseId = $agentEvalCase->getId();
                    $agentEvalSuiteTask->fixturePath = $agentEvalFixture->getPath();
                    $agentEvalSuiteTask->modelName = $modelName;
                    $agentEvalSuiteTask->flavor = AgentEvalSuiteTask::FLAVOR_CALL;
                    $agentEvalSuiteTask->access = AgentEvalSuiteTask::ACCESS_READ;
                    $agentEvalSuiteTask->resourceKey = (string)($resourceGroupByFixture[$expectedSkillName ?? ''] ?? $expectedSkillName ?? static::RESOURCE_SELECTION);
                    $agentEvalSuiteTask->taskId = $agentEvalSuiteTask->getRunKey() . '|' . static::SUITE_SELECTION . '|' . $agentEvalSuiteTask->caseId;
                    $agentEvalSuiteTask->estimatedSeconds = $this->estimateSecondsOfTask($agentEvalSuiteTask);
                    $agentEvalSuiteTasks->add($agentEvalSuiteTask);
                }
            }
        }
        return $agentEvalSuiteTasks;
    }

    /**
     * The recall cases for every model × fixture × case: each seeds past conversations on its scope and tears them
     * down — one after the other per scope (a case's search would find the neighbour's seed), so every recall case is
     * a write on one shared resource. A recall case must name its own scope.
     */
    public function buildRecallTasks(AgentEvalFixtures $agentEvalFixtures, AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions): AgentEvalSuiteTasks
    {
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::newInstance();
        foreach ($agentEvalSuitePlanOptions->modelNames as $modelName) {
            foreach ($agentEvalFixtures->getElements() as $agentEvalFixture) {
                foreach ($agentEvalFixture->getCases()->getElements() as $agentEvalCase) {
                    $agentEvalSuiteTask = $this->createTask();
                    if (!$this->assignScopeOfCaseToTask($agentEvalSuiteTask, $agentEvalCase, $agentEvalSuitePlanOptions, false)) {
                        continue;
                    }
                    if (!$agentEvalSuitePlanOptions->matchesCaseFilter($agentEvalCase->getId())) {
                        continue;
                    }
                    $agentEvalSuiteTask->kind = AgentEvalSuiteTask::KIND_RECALL_CASE;
                    $agentEvalSuiteTask->suite = static::SUITE_RECALL;
                    $agentEvalSuiteTask->caseId = $agentEvalCase->getId();
                    $agentEvalSuiteTask->fixturePath = $agentEvalFixture->getPath();
                    $agentEvalSuiteTask->modelName = $modelName;
                    $agentEvalSuiteTask->flavor = AgentEvalSuiteTask::FLAVOR_CALL;
                    $agentEvalSuiteTask->access = AgentEvalSuiteTask::ACCESS_WRITE;
                    $agentEvalSuiteTask->resourceKey = static::RESOURCE_CONVERSATION_RECALL;
                    $agentEvalSuiteTask->taskId = $agentEvalSuiteTask->getRunKey() . '|' . static::SUITE_RECALL . '|' . $agentEvalSuiteTask->caseId;
                    $agentEvalSuiteTask->estimatedSeconds = $this->estimateSecondsOfTask($agentEvalSuiteTask);
                    $agentEvalSuiteTasks->add($agentEvalSuiteTask);
                }
            }
        }
        return $agentEvalSuiteTasks;
    }

    /**
     * Sets the scope of `$agentEvalSuiteTask` from the case and returns whether it has one (a case without a scope is
     * not planned). The module's scope is the agent space: the case's `agentSpaceId`, else — when `$withFallback` — the
     * options' fallback agent space, else — when `$withFallback` and the run names an account — the account level of
     * that account ({@see AgentEvalSuitePlanOptions::$fallbackAccountId}, {@see AgentEvalSuiteTask::$accountId}).
     *
     * An application whose fixtures name the scope by a key of its own overrides this method and sets that value on its
     * subclass of the task ({@see AgentEvalSuiteTask::getScopeId()}).
     *
     * @param AgentEvalSuiteTask $agentEvalSuiteTask the task being planned
     * @param AgentEvalCase $agentEvalCase its case
     * @param AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions the run's options (with the fallback scope)
     * @param bool $withFallback whether the options' fallback scope stands in for a case that names none
     * @return bool
     */
    public function assignScopeOfCaseToTask(
        AgentEvalSuiteTask $agentEvalSuiteTask,
        AgentEvalCase $agentEvalCase,
        AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions,
        bool $withFallback
    ): bool {
        $agentSpaceId = $this->resolveAgentSpaceIdOfCaseForPlanning($agentEvalCase) ?? ($withFallback ? $agentEvalSuitePlanOptions->fallbackAgentSpaceId : null);
        $agentEvalSuiteTask->agentSpaceId = $agentSpaceId;
        // no space at all: a run that names an account plans the case at account level for it
        $agentEvalSuiteTask->accountId = $agentSpaceId === null && $withFallback ? $agentEvalSuitePlanOptions->fallbackAccountId : null;
        return $agentSpaceId !== null || $agentEvalSuiteTask->accountId !== null;
    }

    /**
     * The agent space a case names for planning: its number, or the space its reference resolves to
     * ({@see AgentEvalRunHooksService::resolveAgentSpaceReference()}). A reference that does not resolve plans like a
     * case without a space; its run then fails with the reference's message
     * ({@see AgentEvalRunHooksService::resolveAgentSpaceIdOfCase()}), never on another space.
     */
    protected function resolveAgentSpaceIdOfCaseForPlanning(AgentEvalCase $agentEvalCase): ?int
    {
        $agentSpaceName = $agentEvalCase->getAgentSpaceReferenceName();
        if ($agentSpaceName === null) {
            return $agentEvalCase->getAgentSpaceId();
        }
        /** @var AgentEvalRunHooksService $agentEvalRunHooksService */
        $agentEvalRunHooksService = DDDService::instance()->getService(AgentEvalRunHooksService::class);
        $agentSpaceId = $agentEvalRunHooksService->resolveAgentSpaceReference($agentSpaceName);
        return $agentSpaceId !== null && $agentSpaceId > 0 ? $agentSpaceId : null;
    }

    /**
     * How a case touches its live scope ({@see AgentEvalCase::resolveSuiteAccess()}): its fixture is exclusive when it
     * is one of {@see getExclusiveFixtureNames()}; the loop's own tools ({@see getLoopToolNames()}) never make a case a
     * write.
     */
    public function resolveAccessOfCase(AgentEvalCase $agentEvalCase, string $fixtureName): string
    {
        return $agentEvalCase->resolveSuiteAccess(in_array($fixtureName, $this->getExclusiveFixtureNames(), true), $this->getLoopToolNames());
    }

    /** The resource group of a case ({@see AgentEvalCase::resolveResourceKey()}) under {@see getResourceGroupByFixture()}. */
    public function resolveResourceKeyOfCase(AgentEvalCase $agentEvalCase, string $fixtureName): string
    {
        return $agentEvalCase->resolveResourceKey($fixtureName, $this->getResourceGroupByFixture());
    }

    /**
     * Fixture name → the resource group its cases read and write (`AI.evalSuite.resourceGroupByFixture`). Fixtures that
     * share a group never write at the same time on one scope; a fixture not listed is its own resource.
     *
     * @return string[]
     */
    public function getResourceGroupByFixture(): array
    {
        $resourceGroupByFixture = Config::get(static::CONFIG_KEY_RESOURCE_GROUP_BY_FIXTURE);
        return is_array($resourceGroupByFixture) ? array_map('strval', $resourceGroupByFixture) : [];
    }

    /**
     * The fixtures whose writing cases change state other cases' behaviour depends on (`AI.evalSuite.exclusiveFixtures`):
     * such a case runs alone on its scope.
     *
     * @return string[]
     */
    public function getExclusiveFixtureNames(): array
    {
        $exclusiveFixtureNames = Config::get(static::CONFIG_KEY_EXCLUSIVE_FIXTURES);
        return is_array($exclusiveFixtureNames) ? array_values(array_map('strval', $exclusiveFixtureNames)) : [];
    }

    /**
     * The loop's own tools: they change the conversation, never the scope, so a case that expects one does not write.
     *
     * @return string[]
     */
    public function getLoopToolNames(): array
    {
        return AIConversationMessage::META_TOOL_NAMES;
    }

    /**
     * The seconds one case takes per kind and flavor: the priors of the plan's wall-time estimate. An application adds
     * its own kinds to the parent's priors ({@see AgentEvalDurationPriors::addPrior()}).
     */
    public function getDurationPriors(): AgentEvalDurationPriors
    {
        $agentEvalDurationPriors = AgentEvalDurationPriors::newInstance();
        $agentEvalDurationPriors->addPrior(AgentEvalSuiteTask::KIND_TOOLS_CASE, AgentEvalSuiteTask::FLAVOR_CALL, 35.0);
        $agentEvalDurationPriors->addPrior(AgentEvalSuiteTask::KIND_TOOLS_CASE, AgentEvalSuiteTask::FLAVOR_JUDGE, 90.0);
        $agentEvalDurationPriors->addPrior(AgentEvalSuiteTask::KIND_SELECTION_CASE, AgentEvalSuiteTask::FLAVOR_CALL, 25.0);
        $agentEvalDurationPriors->addPrior(AgentEvalSuiteTask::KIND_RECALL_CASE, AgentEvalSuiteTask::FLAVOR_CALL, 45.0);
        return $agentEvalDurationPriors;
    }

    /** The prior of one task's duration in seconds ({@see getDurationPriors()}, else {@see DEFAULT_ESTIMATED_SECONDS}). */
    public function estimateSecondsOfTask(AgentEvalSuiteTask $agentEvalSuiteTask): float
    {
        return $this->getDurationPriors()->findSecondsOfKindAndFlavor($agentEvalSuiteTask->kind, $agentEvalSuiteTask->flavor) ?? static::DEFAULT_ESTIMATED_SECONDS;
    }
}
