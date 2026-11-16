<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalBaselineChoice;
use DDD\Domain\AI\Entities\Evals\AgentEvalBaselineComparison;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowsOfRun;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixtures;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalStoredRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteDecisionLayerSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteDefinition;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteDefinitions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteProgress;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecord;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordRun;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordRuns;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRetriedCase;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunningCase;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunState;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The whole evaluation suite in one run: every suite the configuration names (`AI.evalSuite.suites`) on every model
 * in every flavor, each case as its own child process of the evaluation command of its kind, scheduled by
 * {@see AgentEvalSuiteSchedule}: reads in a pool, writes in lanes per agent space and resource, the lanes beside the
 * pool, models and flavors interleaved.
 *
 * Correctness and performance are two verdicts: a run passes its cases, and, against a baseline run, it must not need
 * more model calls, turns, seconds or money than before. The exit code fails on either.
 *
 * The command only starts, watches and collects processes and prints: the planning is
 * {@see AgentEvalSuitePlanService}, the schedule {@see AgentEvalSuiteSchedule}, the files
 * {@see AgentEvalResultFilesService}, the numbers the record objects ({@see AgentEvalSuiteRecord}), the stored run
 * {@see AgentEvalSuiteRunsService}.
 *
 * Stopping a run: a `STOP` file in the run folder or a first SIGINT / SIGTERM stops new starts. While cases are still
 * queued, the stop drops the queue and ends every running case that changes no live state (running writes finish and
 * restore); once the queue is empty, the running cases all finish. A second signal ends every running case.
 *
 * Override points for an application (a subclass that repeats `#[AsCommand]` with this name):
 * {@see getDefaultModelName()}, {@see getRetriedInfrastructureErrors()}, {@see getChildCommandNameOfKind()},
 * {@see buildApplicationArgumentsOfTask()}, {@see buildPlanOptions()}, {@see buildFixturesOfSuite()},
 * {@see configureApplicationOptions()}, {@see isIsolateWholeScopeRequested()}, {@see getDecisionLayerLabel()},
 * {@see describeStopRequest()}.
 *
 * LIVE: real model and tool calls in the children.
 */
#[AsCommand(
    name: 'app:ai-agent:eval-suite',
    description: 'Run the whole eval suite in parallel (reads pooled, writes in lanes per agent space and resource); report correctness and performance, compare with a baseline (live).'
)]
class AgentEvalSuiteCommand extends AgentEvalConsoleCommand
{
    use AgentEvalArmOptionsTrait;

    /** @var string The option that keeps the AI budget cap (the budget is lifted for every child by default) */
    public const string OPTION_ENFORCE_BUDGET = 'enforce-budget';

    /** @var string The option that makes a write exclude every read of its agent space */
    public const string OPTION_ISOLATE_SCOPES = 'isolate-scopes';

    /** @var string The `--baseline` value that takes the newest comparable former suite run */
    public const string BASELINE_LATEST = 'latest';

    /** @var string The child command of a case of a tools-style fixture */
    public const string CHILD_COMMAND_TOOLS_CASE = 'app:ai-agent:eval-tools';

    /** @var string The child command of a skill-selection case */
    public const string CHILD_COMMAND_SELECTION_CASE = 'app:ai-agent:eval-selection';

    /** @var string The child command of a recall case */
    public const string CHILD_COMMAND_RECALL_CASE = 'app:ai-agent:eval-recall';

    /** @var string How the settings name the conversation's own model (a run without a model name; as its run key) */
    public const string DEFAULT_MODEL_LABEL = 'default';

    /** @var string The label in front of the decision-layer figures of the plan */
    public const string DECISION_LAYER_LABEL = 'decision';

    /** @var int The default of `--max-processes` */
    public const int DEFAULT_MAX_PROCESSES = 24;

    /** @var int The default of `--head-start-seconds` */
    public const int DEFAULT_HEAD_START_SECONDS = 45;

    /** @var int The default of `--stagger-ms` */
    public const int DEFAULT_STAGGER_MILLISECONDS = 500;

    /** @var float Seconds between two writes of the progress file */
    public const float PROGRESS_INTERVAL_SECONDS = 5.0;

    /** @var int Microseconds between two looks at the running processes */
    public const int POLL_INTERVAL_MICROSECONDS = 100_000;

    /** @var int Seconds a stopped process gets before it is killed */
    public const int STOP_TIMEOUT_SECONDS = 3;

    /** @var int The longest error tail of a child that left no result */
    public const int CHILD_ERROR_TAIL_LENGTH = 600;

    /** @var int The longest failure, error or detail text of a case line */
    public const int CASE_LINE_TEXT_LENGTH = 220;

    /**
     * @var string[] What makes a failed case an INFRASTRUCTURE failure (a database deadlock, a model transport
     * timeout): the case said nothing about the agent, so it runs once more. Matched against the row's error text.
     */
    public const array RETRIED_INFRASTRUCTURE_ERRORS = [
        'Deadlock found when trying to get lock',
        'Lock wait timeout exceeded',
        'cURL error 28',
        // the model provider reset the connection while the answer was read
        'cURL error 56',
        'MySQL server has gone away',
        // processes booting in the same moment race for the compiled container: a file of it is gone
        '/var/cache/',
    ];

    /** @var bool Whether the cases started (a stop signal before stops the process as usual) */
    protected bool $casesStarted = false;

    protected function configure(): void
    {
        $agentEvalSuiteDefinitions = $this->findSuiteDefinitions();
        $defaultSuiteNames = $agentEvalSuiteDefinitions->getDefaultSuiteNames();
        $this
            ->addOption('suites', null, InputOption::VALUE_OPTIONAL, 'Comma list of suites: ' . implode(', ', $agentEvalSuiteDefinitions->getSuiteNames()) . '. Default: every suite the configuration plans by default.', $defaultSuiteNames !== [] ? implode(',', $defaultSuiteNames) : null)
            ->addOption('models', null, InputOption::VALUE_OPTIONAL, 'Comma list of agent models (AIModel names). Default: the one default model.', $this->getDefaultModelName())
            ->addOption('flavors', null, InputOption::VALUE_OPTIONAL, 'Comma list of flavors: call (deterministic, single-shot) and judge (simulated owner + judge). A suite the configuration binds to some flavors runs in those only.', AgentEvalSuiteTask::FLAVOR_CALL . ',' . AgentEvalSuiteTask::FLAVOR_JUDGE)
            ->addOption('skill', null, InputOption::VALUE_OPTIONAL, 'Only the tools-style fixtures whose skill or file name matches')
            ->addOption('case', null, InputOption::VALUE_OPTIONAL, 'Only the case with this id, or the cases of a comma-separated list of ids (in every selected suite that has them)')
            ->addOption('max-processes', null, InputOption::VALUE_OPTIONAL, 'How many case processes run at the same time.', (string)static::DEFAULT_MAX_PROCESSES)
            ->addOption('head-start-seconds', null, InputOption::VALUE_OPTIONAL, 'The first case runs ALONE until it finished or this many seconds passed: it compiles both containers (console and the MCP executor\'s), which processes booting in the same moment would race for.', (string)static::DEFAULT_HEAD_START_SECONDS)
            ->addOption('stagger-ms', null, InputOption::VALUE_OPTIONAL, 'Milliseconds between two process starts after the head start.', (string)static::DEFAULT_STAGGER_MILLISECONDS)
            ->addOption(static::OPTION_ISOLATE_SCOPES, null, InputOption::VALUE_NONE, 'Conservative lanes: a write excludes EVERY read of its agent space, not only the reads of its resource. Slower; for a fixture whose reads depend on a resource another fixture writes.')
            ->addOption('include-async', null, InputOption::VALUE_NONE, 'Also run cases marked `executionMode: async` inline (debugging only, never a coverage claim).')
            ->addOption('transient', null, InputOption::VALUE_NONE, 'Do NOT persist the conversations of the tools-style and the selection cases. Without an id no decision site runs — the run then measures the loop without the decision layer.')
            ->addOption('judge-model', null, InputOption::VALUE_OPTIONAL, 'The judge (verdict) model of the judge flavor (default: the runner\'s).')
            ->addOption('driver-model', null, InputOption::VALUE_OPTIONAL, 'The simulated-owner (driver) model of the judge flavor (default: the runner\'s).')
            ->addOption('baseline', null, InputOption::VALUE_OPTIONAL, 'A former suite run to compare the performance with: its folder, or `latest` (the newest suite run before this one that shares at least 5 and at least half of the planned cases; the chosen run and the skipped ones are printed).')
            ->addOption('performance-tolerance', null, InputOption::VALUE_OPTIONAL, 'Percent a performance number may grow over the baseline before it counts as a regression.', (string)AgentEvalBaselineComparison::DEFAULT_TOLERANCE_PERCENT)
            ->addOption('label', null, InputOption::VALUE_OPTIONAL, 'A short label for the run folder (e.g. effort-low).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan (tasks, lanes, waves, the estimated wall time) and start nothing.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the suite record as JSON at the end instead of the report.');
        $this->configureBudgetOption();
        $this->configureEvalScopeOptions();
        $this->addEvalArmOptions();
        $this->configureApplicationOptions();
    }

    /**
     * Override point: the options of the application's own kinds and words, declared after the module's. The default:
     * none.
     *
     * @return void
     */
    protected function configureApplicationOptions(): void
    {
    }

    /**
     * The budget option of the suite: `--enforce-budget` keeps the cap; without it every child gets `--ignore-budget`
     * ({@see buildBudgetArgumentsForChild()}).
     *
     * @return void
     */
    protected function configureBudgetOption(): void
    {
        $this->addOption(static::OPTION_ENFORCE_BUDGET, null, InputOption::VALUE_NONE, 'Keep the AI-budget cap of the test accounts (default: lifted for the run, as for every eval).');
    }

    /** The budget is lifted for the run unless `--enforce-budget` was passed. */
    protected function isBudgetBypassRequested(InputInterface $input): bool
    {
        return !($input->hasOption(static::OPTION_ENFORCE_BUDGET) && (bool)$input->getOption(static::OPTION_ENFORCE_BUDGET));
    }

    /** The suite stops gracefully on SIGINT / SIGTERM once its cases run. */
    protected function handlesStopSignals(): bool
    {
        return true;
    }

    /**
     * Before the first case started, a stop signal ends the process as it always did (exit code 128 + signal); once
     * cases run, it is counted ({@see AgentEvalConsoleCommand::handleSignal()}) and the run stops gracefully.
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if (!$this->casesStarted) {
            return 128 + $signal;
        }
        return parent::handleSignal($signal, $previousExitCode);
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2G');
        set_time_limit(0);
        $asJson = (bool)$input->getOption('json');
        $agentEvalSuiteDefinitions = $this->findSuiteDefinitions();
        $suites = $this->parseList((string)$input->getOption('suites'), $agentEvalSuiteDefinitions->getSuiteNames(), 'suites');
        $modelNames = $this->parseModelNames($input);
        $flavors = $this->parseList((string)$input->getOption('flavors'), [AgentEvalSuiteTask::FLAVOR_CALL, AgentEvalSuiteTask::FLAVOR_JUDGE], 'flavors');
        $evalArms = $this->resolveEvalArmOptions($input);
        $visionCapabilityByModelName = $this->buildVisionCapabilityByModelName($modelNames);
        $agentEvalSuiteTasks = $this->planTasks($input, $agentEvalSuiteDefinitions, $suites, $modelNames, $flavors, $visionCapabilityByModelName);
        if ($agentEvalSuiteTasks->count() === 0) {
            $output->writeln('<error>No case matched — nothing to run.</error>');
            return Command::INVALID;
        }
        $agentEvalSuiteSchedule = AgentEvalSuiteSchedule::forProcesses(
            max(1, (int)$input->getOption('max-processes')),
            max(0, (int)$input->getOption('stagger-ms')) / 1000,
            $this->isIsolateWholeScopeRequested($input)
        );
        $agentEvalSuitePlanSummary = $agentEvalSuiteSchedule->summarizePlan($agentEvalSuiteTasks);
        if ($agentEvalSuitePlanSummary->unschedulableTasks > 0) {
            throw new RuntimeException($agentEvalSuitePlanSummary->unschedulableTasks . ' case(s) can never start under the lane rules — the plan is inconsistent');
        }
        $agentEvalSuiteRecordSettings = $this->buildRunSettings($input, $modelNames, $flavors, $suites, $evalArms, $agentEvalSuiteSchedule);
        if (!$asJson) {
            $this->writePlan($output, $agentEvalSuitePlanSummary, $agentEvalSuiteRecordSettings);
        }
        if ((bool)$input->getOption('dry-run')) {
            if ($asJson) {
                $output->writeln($this->getResultFilesService()->encodeJsonDocument(['settings' => $agentEvalSuiteRecordSettings->toArray(), 'plan' => $agentEvalSuitePlanSummary->toArray()]));
            }
            return Command::SUCCESS;
        }
        return $this->runSuite($input, $output, $agentEvalSuiteTasks, $agentEvalSuiteSchedule, $agentEvalSuitePlanSummary, $agentEvalSuiteRecordSettings);
    }

    // ------------------------------------------------------------------ the plan

    /** The suites a run can plan (the planning service's, from the configuration). */
    protected function findSuiteDefinitions(): AgentEvalSuiteDefinitions
    {
        return $this->getPlanService()->getSuiteDefinitions();
    }

    /**
     * Override point: the agent model a run without `--models` takes. The default: null, the conversation's own.
     *
     * @return string|null
     */
    protected function getDefaultModelName(): ?string
    {
        return null;
    }

    /**
     * The models of the run in order: the names of `--models`, else the default model (null = the conversation's own).
     *
     * @param InputInterface $input
     * @return array model names; null = the conversation default
     */
    protected function parseModelNames(InputInterface $input): array
    {
        $modelsOption = $input->getOption('models');
        if ($modelsOption === null) {
            return [$this->getDefaultModelName()];
        }
        return $this->parseList((string)$modelsOption, null, 'models');
    }

    /**
     * Model name → whether it can see images (the conversation default is not looked up).
     *
     * @param array $modelNames model names; null = the conversation default
     * @return bool[]
     * @throws RuntimeException for a model the catalog does not know
     */
    protected function buildVisionCapabilityByModelName(array $modelNames): array
    {
        $visionCapabilityByModelName = [];
        $aIModelsService = $this->getAIModelsService();
        foreach ($modelNames as $modelName) {
            if ($modelName === null) {
                continue;
            }
            $aIModel = $aIModelsService->getAIModelByName($modelName);
            if ($aIModel === null) {
                throw new RuntimeException("Unknown model: $modelName");
            }
            $visionCapabilityByModelName[$modelName] = $aIModel->hasVisionCapabilities === true;
        }
        return $visionCapabilityByModelName;
    }

    /**
     * The tasks of every selected suite in the order of `$suites`: per suite its fixtures ({@see buildFixturesOfSuite()})
     * planned in the flavors it runs in (a suite that runs in none of the run's flavors is not planned).
     *
     * @param InputInterface $input
     * @param AgentEvalSuiteDefinitions $agentEvalSuiteDefinitions
     * @param string[] $suites
     * @param array $modelNames model names; null = the conversation default
     * @param string[] $flavors
     * @param bool[] $visionCapabilityByModelName
     * @return AgentEvalSuiteTasks
     */
    protected function planTasks(InputInterface $input, AgentEvalSuiteDefinitions $agentEvalSuiteDefinitions, array $suites, array $modelNames, array $flavors, array $visionCapabilityByModelName): AgentEvalSuiteTasks
    {
        $agentEvalSuitePlanService = $this->getPlanService();
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::newInstance();
        foreach ($suites as $suite) {
            $agentEvalSuiteDefinition = $agentEvalSuiteDefinitions->getBySuiteName($suite);
            $plannedFlavors = $agentEvalSuiteDefinition?->buildPlannedFlavors($flavors) ?? [];
            if ($plannedFlavors === []) {
                continue;
            }
            $agentEvalFixtures = $this->buildFixturesOfSuite($agentEvalSuiteDefinition, $input);
            $agentEvalSuitePlanOptions = $this->buildPlanOptions($input, $modelNames, $plannedFlavors, $visionCapabilityByModelName);
            $tasksOfSuite = $agentEvalSuitePlanService->buildTasksOfKind($agentEvalSuiteDefinition->taskKind, $agentEvalSuiteDefinition->suiteName, $agentEvalFixtures, $agentEvalSuitePlanOptions);
            $agentEvalSuiteTasks->addTasksOf($tasksOfSuite);
        }
        return $agentEvalSuiteTasks;
    }

    /**
     * Override point: the fixtures a suite plans. The default: every fixture of its folder.
     *
     * @param AgentEvalSuiteDefinition $agentEvalSuiteDefinition
     * @param InputInterface $input
     * @return AgentEvalFixtures
     */
    protected function buildFixturesOfSuite(AgentEvalSuiteDefinition $agentEvalSuiteDefinition, InputInterface $input): AgentEvalFixtures
    {
        return $this->getPlanService()->findFixturesInFolder($agentEvalSuiteDefinition->fixtureFolder);
    }

    /**
     * Override point: the options one suite is planned with, created by the planning service (the application's class
     * when it has one). The default sets the vision capabilities, the job-queue cases, the skill and case filters and
     * the fallback agent space (`--agentSpaceId`, as given: the suite only forwards it, it never reads the space).
     *
     * @param InputInterface $input
     * @param array $modelNames model names; null = the conversation default
     * @param string[] $flavors the flavors the suite is planned in
     * @param bool[] $visionCapabilityByModelName
     * @return AgentEvalSuitePlanOptions
     */
    protected function buildPlanOptions(InputInterface $input, array $modelNames, array $flavors, array $visionCapabilityByModelName): AgentEvalSuitePlanOptions
    {
        $agentEvalSuitePlanOptions = $this->getPlanService()->buildPlanOptions($modelNames, $flavors);
        $agentEvalSuitePlanOptions->visionCapabilityByModelName = $visionCapabilityByModelName;
        $agentEvalSuitePlanOptions->includeAsyncOnlyCases = (bool)$input->getOption('include-async');
        $agentEvalSuitePlanOptions->skillFilter = $this->stringOption($input, 'skill');
        $agentEvalSuitePlanOptions->caseFilter = $this->stringOption($input, 'case');
        $agentSpaceIdValue = $input->hasOption(static::OPTION_AGENT_SPACE_ID) ? $input->getOption(static::OPTION_AGENT_SPACE_ID) : null;
        $agentEvalSuitePlanOptions->fallbackAgentSpaceId = is_numeric($agentSpaceIdValue) ? (int)$agentSpaceIdValue : null;
        return $agentEvalSuitePlanOptions;
    }

    /**
     * Override point: whether a write excludes every read of its agent space (`--isolate-scopes`).
     *
     * @param InputInterface $input
     * @return bool
     */
    protected function isIsolateWholeScopeRequested(InputInterface $input): bool
    {
        return (bool)$input->getOption(static::OPTION_ISOLATE_SCOPES);
    }

    /**
     * The settings of the run as the suite states them: the models, flavors, suites and arms, whether the
     * conversations are stored, the processes, the isolation, the configured reasoning effort per model and the
     * decision layer as the platform runs it.
     *
     * @param InputInterface $input
     * @param array $modelNames model names; null = the conversation default
     * @param string[] $flavors
     * @param string[] $suites
     * @param string[] $evalArms option name → value
     * @param AgentEvalSuiteSchedule $agentEvalSuiteSchedule
     * @return AgentEvalSuiteRecordSettings
     */
    protected function buildRunSettings(InputInterface $input, array $modelNames, array $flavors, array $suites, array $evalArms, AgentEvalSuiteSchedule $agentEvalSuiteSchedule): AgentEvalSuiteRecordSettings
    {
        $agentEvalSuiteRecordSettings = AgentEvalSuiteRecordSettings::forDecisionLayer(AgentEvalSuiteDecisionLayerSettings::fromDecisionModelSettings(AgentDecisionModelSettings::current()));
        $agentEvalSuiteRecordSettings->modelNames = array_map(static fn (?string $modelName): string => $modelName ?? static::DEFAULT_MODEL_LABEL, $modelNames);
        $agentEvalSuiteRecordSettings->flavors = $flavors;
        $agentEvalSuiteRecordSettings->suites = $suites;
        $agentEvalSuiteRecordSettings->arms = $evalArms;
        $agentEvalSuiteRecordSettings->persistedConversations = !(bool)$input->getOption('transient');
        $agentEvalSuiteRecordSettings->maxProcesses = $agentEvalSuiteSchedule->maxProcesses;
        $agentEvalSuiteRecordSettings->isolateWholeScope = $agentEvalSuiteSchedule->isolateWholeScope;
        $configuredReasoningEffortByModel = [];
        $aIModelsService = $this->getAIModelsService();
        foreach ($modelNames as $modelName) {
            $configuredReasoningEffortByModel[$modelName ?? static::DEFAULT_MODEL_LABEL] = $modelName !== null
                ? $aIModelsService->getAIModelByName($modelName)?->agenticUseCase?->reasoningEffort
                : null;
        }
        $agentEvalSuiteRecordSettings->configuredReasoningEffortByModel = $configuredReasoningEffortByModel;
        return $agentEvalSuiteRecordSettings;
    }

    /**
     * Override point: the label in front of the decision-layer figures of the plan.
     *
     * @return string
     */
    protected function getDecisionLayerLabel(): string
    {
        return static::DECISION_LAYER_LABEL;
    }

    /** The plan as it is printed before the run (and alone in a dry run). */
    protected function writePlan(OutputInterface $output, AgentEvalSuitePlanSummary $agentEvalSuitePlanSummary, AgentEvalSuiteRecordSettings $agentEvalSuiteRecordSettings): void
    {
        $writingTasksByLane = $agentEvalSuitePlanSummary->writingTasksByLane;
        $output->writeln(sprintf('<info>Eval suite</info> — %d case runs · models [%s] · flavors [%s]', $agentEvalSuitePlanSummary->tasks, implode(', ', $agentEvalSuiteRecordSettings->modelNames), implode(', ', $agentEvalSuiteRecordSettings->flavors)));
        $output->writeln('  suites:  ' . $this->renderCounts($agentEvalSuitePlanSummary->tasksBySuite));
        $output->writeln('  access:  ' . $this->renderCounts($agentEvalSuitePlanSummary->tasksByAccess));
        $output->writeln('  lanes:   ' . ($writingTasksByLane === [] ? 'none' : $this->renderCounts(array_slice($writingTasksByLane, 0, 8, true)) . (count($writingTasksByLane) > 8 ? sprintf(' … (%d lanes)', count($writingTasksByLane)) : '')));
        if ($agentEvalSuitePlanSummary->wavesByWaveGroup !== []) {
            $output->writeln('  waves:   ' . $this->renderCounts($agentEvalSuitePlanSummary->wavesByWaveGroup));
        }
        $output->writeln(sprintf(
            '  time:    one after the other %s · planned %s with %d processes (bound by %s)',
            $this->renderDuration($agentEvalSuitePlanSummary->sequentialWallSeconds),
            $this->renderDuration($agentEvalSuitePlanSummary->estimatedWallSeconds),
            $agentEvalSuiteRecordSettings->maxProcesses,
            $agentEvalSuitePlanSummary->boundBy
        ));
        $arms = $agentEvalSuiteRecordSettings->arms;
        $output->writeln(sprintf(
            '  effort:  %s',
            isset($arms[static::ARM_FIXED_REASONING_EFFORT])
                ? 'FIXED ' . $arms[static::ARM_FIXED_REASONING_EFFORT]
                : 'platform default — configured ' . $this->renderCounts(array_map(static fn (?string $effort): string => $effort ?? 'none', $agentEvalSuiteRecordSettings->configuredReasoningEffortByModel)) . ', the effort site may raise a turn'
        ));
        $decisionLayer = $agentEvalSuiteRecordSettings->decisionLayer;
        $output->writeln(sprintf(
            '  %-8s platform %s — %s · turn closing %s%s',
            $this->getDecisionLayerLabel() . ':',
            $decisionLayer->mode,
            $this->renderCounts($decisionLayer->siteModes),
            $decisionLayer->turnClosingMode,
            $agentEvalSuiteRecordSettings->persistedConversations ? '' : ' · <comment>TRANSIENT run: no decision site runs</comment>'
        ));
        $output->writeln('  arms:    ' . ($arms === [] ? 'none — every switch as the platform runs it' : $this->renderCounts($arms)));
        $output->writeln('');
    }

    // ------------------------------------------------------------------ the run

    /**
     * Runs the planned tasks in a new run folder, compares with the baseline, writes the record, stores the run and
     * prints the outcome.
     *
     * @return int SUCCESS when every case passed and the performance did not regress, FAILURE otherwise
     */
    protected function runSuite(
        InputInterface $input,
        OutputInterface $output,
        AgentEvalSuiteTasks $agentEvalSuiteTasks,
        AgentEvalSuiteSchedule $agentEvalSuiteSchedule,
        AgentEvalSuitePlanSummary $agentEvalSuitePlanSummary,
        AgentEvalSuiteRecordSettings $agentEvalSuiteRecordSettings
    ): int {
        $asJson = (bool)$input->getOption('json');
        $agentEvalResultFilesService = $this->getResultFilesService();
        $label = $this->stringOption($input, 'label');
        $suiteDirectory = $agentEvalResultFilesService->createSuiteRunDirectory(time(), $label);
        $baselineReference = $this->stringOption($input, 'baseline');
        $agentEvalBaselineChoice = $agentEvalResultFilesService->findBaselineChoice($baselineReference, $suiteDirectory, $agentEvalSuiteTasks);
        $baselineChoiceText = $agentEvalBaselineChoice?->describe($baselineReference !== static::BASELINE_LATEST);
        if ($baselineChoiceText !== null && !$asJson) {
            $output->writeln($baselineChoiceText);
        }
        $startedAtMicrotime = microtime(true);
        $agentEvalSuiteRunState = AgentEvalSuiteRunState::forQueue(
            $agentEvalSuiteSchedule->orderTasksForStart($agentEvalSuiteTasks),
            $agentEvalSuiteSchedule,
            $suiteDirectory,
            $agentEvalSuiteRecordSettings->arms,
            max(0, (int)$input->getOption('head-start-seconds'))
        );
        $this->runTasks($input, $output, $agentEvalSuiteRunState);
        $wallSeconds = (int)round(microtime(true) - $startedAtMicrotime);

        $agentEvalSuiteRecordRuns = $this->buildRecordRuns($input, $agentEvalSuiteRunState, $agentEvalBaselineChoice);
        $agentEvalSuiteRecord = AgentEvalSuiteRecord::forFinishedRun(
            (int)$startedAtMicrotime,
            $wallSeconds,
            $this->isStopRequested(),
            $agentEvalSuiteRecordSettings,
            $agentEvalSuitePlanSummary,
            $agentEvalBaselineChoice,
            $baselineChoiceText,
            $agentEvalSuiteRecordRuns
        );
        $agentEvalResultFilesService->writeRecordOfSuiteRun($suiteDirectory, $agentEvalSuiteRecord);
        $this->recordStoredRun($label, (int)$startedAtMicrotime, $agentEvalSuiteRecord, $suiteDirectory, $agentEvalSuiteRunState);
        if ($asJson) {
            $output->writeln($agentEvalResultFilesService->encodeJsonDocument($agentEvalSuiteRecord->toArray()));
        } else {
            $this->writeReport($output, $agentEvalSuiteRecord, $suiteDirectory);
        }
        return $agentEvalSuiteRecord->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    /** Starts, watches and collects the case processes until the queue is empty and nothing runs. */
    protected function runTasks(InputInterface $input, OutputInterface $output, AgentEvalSuiteRunState $agentEvalSuiteRunState): void
    {
        $this->casesStarted = true;
        while ($agentEvalSuiteRunState->hasWork()) {
            $this->applyStopRequests($input, $output, $agentEvalSuiteRunState);
            $this->collectFinishedCases($input, $output, $agentEvalSuiteRunState);
            $this->startRunnableCases($input, $agentEvalSuiteRunState);
            $this->writeProgressWhenDue($agentEvalSuiteRunState);
            usleep(static::POLL_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * A `STOP` file counts as the first stop signal. On a stop while cases are still queued, the queue is dropped and
     * every running case that changes no live state is ended (writes finish and restore); a stop that finds the queue
     * empty ends nothing and the running cases finish. On a second signal every running case is ended.
     */
    protected function applyStopRequests(InputInterface $input, OutputInterface $output, AgentEvalSuiteRunState $agentEvalSuiteRunState): void
    {
        if (!$this->isStopRequested() && $this->getResultFilesService()->isStopRequestedForSuiteRun($agentEvalSuiteRunState->suiteDirectory)) {
            $this->requestStop();
        }
        if ($this->isStopRequested() && $agentEvalSuiteRunState->queue->count() > 0) {
            if (!(bool)$input->getOption('json')) {
                $output->writeln('<comment>' . $this->describeStopRequest($agentEvalSuiteRunState->queue->count()) . '</comment>');
            }
            $agentEvalSuiteRunState->queue->clear();
            foreach ($agentEvalSuiteRunState->runningCases->getElements() as $agentEvalSuiteRunningCase) {
                if (!$agentEvalSuiteRunningCase->task->writesLiveState()) {
                    $agentEvalSuiteRunningCase->process->stop(static::STOP_TIMEOUT_SECONDS);
                }
            }
        }
        if ($this->isKillRequested()) {
            foreach ($agentEvalSuiteRunState->runningCases->getElements() as $agentEvalSuiteRunningCase) {
                $agentEvalSuiteRunningCase->process->stop(static::STOP_TIMEOUT_SECONDS);
            }
        }
    }

    /**
     * Override point: the line printed when a stop drops `$droppedCaseCount` queued cases.
     *
     * @param int $droppedCaseCount
     * @return string
     */
    protected function describeStopRequest(int $droppedCaseCount): string
    {
        return sprintf('Stop requested — %d queued case(s) dropped; every case that changes no live state is ended, running writes finish and restore (a second signal ends them too).', $droppedCaseCount);
    }

    /** Collects every case whose child process ended or ran out of time: a retry, or a row of its run. */
    protected function collectFinishedCases(InputInterface $input, OutputInterface $output, AgentEvalSuiteRunState $agentEvalSuiteRunState): void
    {
        foreach ($agentEvalSuiteRunState->runningCases->getElements() as $agentEvalSuiteRunningCase) {
            $timedOut = false;
            try {
                if ($agentEvalSuiteRunningCase->process->isRunning()) {
                    $agentEvalSuiteRunningCase->process->checkTimeout();
                    continue;
                }
            } catch (ProcessTimedOutException) {
                $timedOut = true;
            }
            $agentEvalCaseRow = $this->collectRow($agentEvalSuiteRunningCase, $timedOut, $agentEvalSuiteRunningCase->getRunSeconds(microtime(true)), $agentEvalSuiteRunState->suiteDirectory);
            $finishedTask = $agentEvalSuiteRunningCase->task;
            $agentEvalSuiteRunState->runningCases->remove($agentEvalSuiteRunningCase);
            if ($this->queueRetryOfCase($input, $output, $agentEvalSuiteRunState, $finishedTask, $agentEvalCaseRow)) {
                continue;
            }
            $this->keepRowOfCase($input, $output, $agentEvalSuiteRunState, $finishedTask, $agentEvalCaseRow);
        }
    }

    /**
     * The row of a finished child: its result file's first case, else a failed row with the reason (timed out,
     * stopped, no result); the harness lines of its error output, the task's own facts; written with its message trace
     * as the case file, returned without it.
     */
    protected function collectRow(AgentEvalSuiteRunningCase $agentEvalSuiteRunningCase, bool $timedOut, float $processSeconds, string $suiteDirectory): AgentEvalCaseRow
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $agentEvalSuiteTask = $agentEvalSuiteRunningCase->task;
        $agentEvalCaseRow = $agentEvalResultFilesService->findCaseRowOfChildResultFile($agentEvalSuiteRunningCase->resultFile)
            ?? AgentEvalCaseRow::buildFailedWithError([], $this->describeMissingResult($agentEvalSuiteRunningCase, $timedOut));
        $agentEvalCaseRow->removeEmptyError();
        // what the harness did around the agent (setup, capture, restore, after): a refused restore leaves the agent
        // space changed for the next case, so it belongs to the row
        $agentEvalCaseRow->recordHarnessLogOfErrorOutput($this->readErrorOutputOf($agentEvalSuiteRunningCase->process));
        $agentEvalCaseRow->stampTask($agentEvalSuiteTask, $processSeconds);
        $agentEvalResultFilesService->writeCaseFileOfSuiteTask($suiteDirectory, $agentEvalSuiteTask, $agentEvalCaseRow);
        $agentEvalCaseRow->removeKey('messages');
        return $agentEvalCaseRow;
    }

    /** Why a child left no result: it ran out of time, the run was stopped, or it ended without one (its error tail). */
    protected function describeMissingResult(AgentEvalSuiteRunningCase $agentEvalSuiteRunningCase, bool $timedOut): string
    {
        $agentEvalSuiteTask = $agentEvalSuiteRunningCase->task;
        if ($timedOut) {
            return "the case's child process exceeded its {$agentEvalSuiteTask->timeoutSeconds}s ceiling and was ended (raise the case's timeoutSeconds when the flow is legitimately that long)";
        }
        if ($this->isStopRequested()) {
            return 'ended by the stop request';
        }
        $process = $agentEvalSuiteRunningCase->process;
        $childOutput = '';
        try {
            $childOutput = trim($process->getErrorOutput()) !== '' ? $process->getErrorOutput() : $process->getOutput();
        } catch (Throwable) {
            // the output of a killed process may be unreadable: the reason stands on its own
        }
        return 'the child process left no result (exit code ' . ($process->getExitCode() ?? '?') . '): '
            . mb_substr(trim((string)preg_replace('/\s+/', ' ', $childOutput)), -static::CHILD_ERROR_TAIL_LENGTH);
    }

    /** The error output of a child; empty when it cannot be read. */
    protected function readErrorOutputOf(Process $process): string
    {
        try {
            return $process->getErrorOutput();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Override point: the texts that mark an infrastructure error (the case runs once more).
     *
     * @return string[]
     */
    protected function getRetriedInfrastructureErrors(): array
    {
        return static::RETRIED_INFRASTRUCTURE_ERRORS;
    }

    /**
     * Queues the task once more at the head of the queue when its row ended on an infrastructure error, it was not
     * retried before, no stop was asked for and no restore step was refused (a second attempt on a changed agent space
     * could pass). Prints the `RETRY` line.
     *
     * @return bool whether the task runs once more
     */
    protected function queueRetryOfCase(InputInterface $input, OutputInterface $output, AgentEvalSuiteRunState $agentEvalSuiteRunState, AgentEvalSuiteTask $agentEvalSuiteTask, AgentEvalCaseRow $agentEvalCaseRow): bool
    {
        $infrastructureError = $agentEvalCaseRow->findInfrastructureError($this->getRetriedInfrastructureErrors());
        if ($infrastructureError === null
            || $agentEvalSuiteRunState->retriedCases->getByTaskId($agentEvalSuiteTask->taskId) !== null
            || $this->isStopRequested()
            || $agentEvalCaseRow->isRestoreFailed()
        ) {
            return false;
        }
        $agentEvalSuiteRetriedCase = AgentEvalSuiteRetriedCase::forFirstAttempt($agentEvalSuiteTask->taskId, $infrastructureError, $agentEvalCaseRow);
        $agentEvalSuiteRunState->retriedCases->add($agentEvalSuiteRetriedCase);
        $agentEvalSuiteRunState->queue->prependTask($agentEvalSuiteTask);
        if (!(bool)$input->getOption('json')) {
            $output->writeln(sprintf('  <comment>RETRY</comment> %-7s %-10s %-44s <fg=gray>%s</>', $agentEvalSuiteTask->flavor, $agentEvalSuiteTask->suite, mb_substr($agentEvalSuiteTask->caseId, 0, 44), $infrastructureError));
        }
        return true;
    }

    /** Keeps the row of a finished task in its run (a second attempt carries its first), counts it and prints its line. */
    protected function keepRowOfCase(InputInterface $input, OutputInterface $output, AgentEvalSuiteRunState $agentEvalSuiteRunState, AgentEvalSuiteTask $agentEvalSuiteTask, AgentEvalCaseRow $agentEvalCaseRow): void
    {
        $agentEvalSuiteRetriedCase = $agentEvalSuiteRunState->retriedCases->getByTaskId($agentEvalSuiteTask->taskId);
        if ($agentEvalSuiteRetriedCase !== null) {
            $agentEvalCaseRow->markAsRetriedAfter($agentEvalSuiteRetriedCase->infrastructureError, $agentEvalSuiteRetriedCase->firstAttemptRow);
            $this->getResultFilesService()->stampRetryOnCaseFileOfSuiteTask($agentEvalSuiteRunState->suiteDirectory, $agentEvalSuiteTask, $agentEvalSuiteRetriedCase->infrastructureError);
        }
        $agentEvalSuiteRunState->rowsByRun->addRowOfTask($agentEvalSuiteTask, $agentEvalCaseRow);
        $agentEvalSuiteRunState->countFinishedRow($agentEvalCaseRow);
        if (!(bool)$input->getOption('json')) {
            $this->writeFinishedCaseLines($output, $agentEvalSuiteRunState, $agentEvalSuiteTask, $agentEvalCaseRow);
        }
    }

    /** The line of a finished case with its first failures, its error or why it does not apply. */
    protected function writeFinishedCaseLines(OutputInterface $output, AgentEvalSuiteRunState $agentEvalSuiteRunState, AgentEvalSuiteTask $agentEvalSuiteTask, AgentEvalCaseRow $agentEvalCaseRow): void
    {
        $output->writeln(sprintf(
            '  %s %-7s %-10s %-44s %5.0f s%s  <fg=gray>[%d/%d · %d running · %d queued]</>',
            match (true) {
                $agentEvalCaseRow->isNotApplicable() => '<comment>N/A </comment>',
                $agentEvalCaseRow->isPassed() => '<info>PASS</info>',
                $agentEvalCaseRow->getValue('error') !== null => '<error>ERR </error>',
                default => '<error>FAIL</error>',
            },
            $agentEvalSuiteTask->flavor,
            $agentEvalSuiteTask->suite,
            mb_substr($agentEvalSuiteTask->caseId, 0, 44),
            $agentEvalCaseRow->getProcessSeconds(),
            $agentEvalCaseRow->hasPerformance() ? sprintf(' · %d calls · %d turns', (int)$agentEvalCaseRow->getPerformanceNumber('modelCalls'), (int)$agentEvalCaseRow->getPerformanceNumber('ownerTurns')) : '',
            $agentEvalSuiteRunState->finishedCount,
            $agentEvalSuiteRunState->totalTasks,
            $agentEvalSuiteRunState->runningCases->count(),
            $agentEvalSuiteRunState->queue->count()
        ));
        foreach (array_slice((array)($agentEvalCaseRow->getValue('failures') ?? []), 0, 3) as $failure) {
            $output->writeln('         <fg=red>✗ ' . mb_substr((string)$failure, 0, static::CASE_LINE_TEXT_LENGTH) . '</>');
        }
        if ($agentEvalCaseRow->getValue('error') !== null) {
            $output->writeln('         <fg=red>! ' . mb_substr((string)$agentEvalCaseRow->getValue('error'), 0, static::CASE_LINE_TEXT_LENGTH) . '</>');
        }
        if ($agentEvalCaseRow->isNotApplicable()) {
            $output->writeln('         <fg=gray>' . mb_substr($agentEvalCaseRow->getNotApplicableDetail(), 0, static::CASE_LINE_TEXT_LENGTH) . '</>');
        }
    }

    /**
     * Starts what may start: the first case alone (head start), afterwards one start per stagger interval (every
     * runnable task at once without one).
     */
    protected function startRunnableCases(InputInterface $input, AgentEvalSuiteRunState $agentEvalSuiteRunState): void
    {
        if (!$agentEvalSuiteRunState->isStartDue(microtime(true))) {
            return;
        }
        $runnableTasks = $agentEvalSuiteRunState->schedule->selectRunnableTasks($agentEvalSuiteRunState->queue, $agentEvalSuiteRunState->runningCases->buildTasks());
        $startingTasks = $runnableTasks->getElements();
        if ($agentEvalSuiteRunState->startsOneAtATime()) {
            $startingTasks = array_slice($startingTasks, 0, 1);
        }
        foreach ($startingTasks as $agentEvalSuiteTask) {
            $this->startCase($input, $agentEvalSuiteRunState, $agentEvalSuiteTask);
        }
    }

    /** Starts the child process of one task and moves the task from the queue to the running cases. */
    protected function startCase(InputInterface $input, AgentEvalSuiteRunState $agentEvalSuiteRunState, AgentEvalSuiteTask $agentEvalSuiteTask): void
    {
        $resultFile = $this->getResultFilesService()->prepareChildResultFileOfSuiteTask($agentEvalSuiteRunState->suiteDirectory, $agentEvalSuiteTask);
        $process = new Process($this->buildProcessArguments($agentEvalSuiteTask, $input, $resultFile, $agentEvalSuiteRunState->arms), DDDService::instance()->getRootDir());
        $process->setTimeout((float)$agentEvalSuiteTask->timeoutSeconds);
        $process->start();
        $agentEvalSuiteRunningCase = AgentEvalSuiteRunningCase::forStartedProcess($agentEvalSuiteTask, $process, microtime(true), $resultFile);
        $agentEvalSuiteRunState->runningCases->add($agentEvalSuiteRunningCase);
        $agentEvalSuiteRunState->queue->removeTask($agentEvalSuiteTask);
        $agentEvalSuiteRunState->recordStart(microtime(true));
    }

    /** Rewrites the progress file every {@see PROGRESS_INTERVAL_SECONDS}. */
    protected function writeProgressWhenDue(AgentEvalSuiteRunState $agentEvalSuiteRunState): void
    {
        if (microtime(true) - $agentEvalSuiteRunState->lastProgressAtMicrotime < static::PROGRESS_INTERVAL_SECONDS) {
            return;
        }
        $agentEvalSuiteRunState->lastProgressAtMicrotime = microtime(true);
        $agentEvalSuiteProgress = AgentEvalSuiteProgress::takenAt(time());
        $agentEvalSuiteProgress->total = $agentEvalSuiteRunState->totalTasks;
        $agentEvalSuiteProgress->finished = $agentEvalSuiteRunState->finishedCount;
        $agentEvalSuiteProgress->passed = $agentEvalSuiteRunState->passedCount;
        $agentEvalSuiteProgress->notApplicable = $agentEvalSuiteRunState->notApplicableCount;
        $agentEvalSuiteProgress->queued = $agentEvalSuiteRunState->queue->count();
        $agentEvalSuiteProgress->runningTasks = $agentEvalSuiteRunState->runningCases->buildRunningTasks(microtime(true));
        $this->getResultFilesService()->writeProgressOfSuiteRun($agentEvalSuiteRunState->suiteDirectory, $agentEvalSuiteProgress);
    }

    // ------------------------------------------------------------------ the child processes

    /**
     * Override point: the command a child of a task of kind `$kind` runs.
     *
     * @param string $kind
     * @return string
     * @throws InvalidArgumentException for a kind without a child command
     */
    protected function getChildCommandNameOfKind(string $kind): string
    {
        return match ($kind) {
            AgentEvalSuiteTask::KIND_TOOLS_CASE => static::CHILD_COMMAND_TOOLS_CASE,
            AgentEvalSuiteTask::KIND_SELECTION_CASE => static::CHILD_COMMAND_SELECTION_CASE,
            AgentEvalSuiteTask::KIND_RECALL_CASE => static::CHILD_COMMAND_RECALL_CASE,
            default => throw new InvalidArgumentException("No child command for the task kind $kind"),
        };
    }

    /**
     * The command line of one case's child process: the case, its fixture and result file, the model, the budget; a
     * recall case adds `--json` only (it seeds and stores on its own, it takes no arms); a tools or selection case adds
     * `--json`, whether its conversations are stored and the scope options as given; a tools case the switches of the
     * tools command; then the application's arguments, the judge's models and the arms.
     *
     * @param AgentEvalSuiteTask $agentEvalSuiteTask
     * @param InputInterface $input
     * @param string $resultFile the file the child writes its result to
     * @param string[] $evalArms option name → value
     * @return string[]
     */
    protected function buildProcessArguments(AgentEvalSuiteTask $agentEvalSuiteTask, InputInterface $input, string $resultFile, array $evalArms): array
    {
        $processArguments = array_merge(
            $this->buildChildConsoleCommandLine($this->getChildCommandNameOfKind($agentEvalSuiteTask->kind)),
            ['--case=' . $agentEvalSuiteTask->caseId, '--fixture=' . $agentEvalSuiteTask->fixturePath, '--result-file=' . $resultFile]
        );
        if ($agentEvalSuiteTask->modelName !== null) {
            $processArguments[] = '--model=' . $agentEvalSuiteTask->modelName;
        }
        $processArguments = array_merge($processArguments, $this->buildBudgetArgumentsForChild($input));
        if ($agentEvalSuiteTask->kind === AgentEvalSuiteTask::KIND_RECALL_CASE) {
            return array_merge($processArguments, ['--json']);
        }
        if ($agentEvalSuiteTask->kind === AgentEvalSuiteTask::KIND_TOOLS_CASE || $agentEvalSuiteTask->kind === AgentEvalSuiteTask::KIND_SELECTION_CASE) {
            $processArguments[] = '--json';
            $processArguments[] = (bool)$input->getOption('transient') ? '--transient' : '--persist-conversations';
            $processArguments = array_merge($processArguments, $this->buildScopeArgumentsForChild($input));
        }
        if ($agentEvalSuiteTask->kind === AgentEvalSuiteTask::KIND_TOOLS_CASE) {
            $processArguments[] = '--no-save';
            $processArguments[] = '--no-prune';
            if ((bool)$input->getOption('include-async')) {
                $processArguments[] = '--include-async';
            }
            if ($agentEvalSuiteTask->flavor === AgentEvalSuiteTask::FLAVOR_JUDGE) {
                $processArguments[] = '--judge';
            }
        }
        $processArguments = array_merge($processArguments, $this->buildApplicationArgumentsOfTask($agentEvalSuiteTask, $input, $resultFile));
        if ($agentEvalSuiteTask->flavor === AgentEvalSuiteTask::FLAVOR_JUDGE) {
            foreach (['judge-model', 'driver-model'] as $modelOptionName) {
                $modelOptionValue = $this->stringOption($input, $modelOptionName);
                if ($modelOptionValue !== null) {
                    $processArguments[] = '--' . $modelOptionName . '=' . $modelOptionValue;
                }
            }
        }
        return array_merge($processArguments, static::buildEvalArmArguments($evalArms));
    }

    /**
     * Override point: the arguments a child of the application's own kind gets after the module's (before the judge's
     * models and the arms). The default: none.
     *
     * @param AgentEvalSuiteTask $agentEvalSuiteTask
     * @param InputInterface $input
     * @param string $resultFile
     * @return string[]
     */
    protected function buildApplicationArgumentsOfTask(AgentEvalSuiteTask $agentEvalSuiteTask, InputInterface $input, string $resultFile): array
    {
        return [];
    }

    // ------------------------------------------------------------------ the record

    /**
     * Per run (in the order its first case finished): the run summary written to `<model>-<flavor>/_summary.json` and
     * the comparison with the baseline run's summary of the same folder.
     */
    protected function buildRecordRuns(InputInterface $input, AgentEvalSuiteRunState $agentEvalSuiteRunState, ?AgentEvalBaselineChoice $agentEvalBaselineChoice): AgentEvalSuiteRecordRuns
    {
        $toleranceOption = $input->getOption('performance-tolerance');
        $tolerancePercent = is_numeric($toleranceOption) ? (float)$toleranceOption : AgentEvalBaselineComparison::DEFAULT_TOLERANCE_PERCENT;
        $agentEvalResultFilesService = $this->getResultFilesService();
        $agentEvalSuiteRecordRuns = AgentEvalSuiteRecordRuns::newInstance();
        foreach ($agentEvalSuiteRunState->rowsByRun->getElements() as $agentEvalCaseRowsOfRun) {
            $agentEvalRunSummary = AgentEvalRunSummary::buildForSuite($agentEvalCaseRowsOfRun->caseRows, $agentEvalCaseRowsOfRun->modelName, $agentEvalCaseRowsOfRun->flavor, $agentEvalSuiteRunState->arms);
            $agentEvalResultFilesService->writeRunSummaryOfSuiteRun($agentEvalSuiteRunState->suiteDirectory, $agentEvalCaseRowsOfRun->modelName, $agentEvalCaseRowsOfRun->flavor, $agentEvalRunSummary);
            $agentEvalBaselineComparison = $this->buildBaselineComparison($agentEvalBaselineChoice, $agentEvalCaseRowsOfRun, $agentEvalRunSummary, $tolerancePercent);
            $agentEvalSuiteRecordRun = AgentEvalSuiteRecordRun::forRun($agentEvalCaseRowsOfRun->runKey, $agentEvalRunSummary, $agentEvalBaselineComparison);
            $agentEvalSuiteRecordRuns->add($agentEvalSuiteRecordRun);
        }
        return $agentEvalSuiteRecordRuns;
    }

    /** The comparison of a run with the baseline run's summary of its folder; null without a baseline or such a summary. */
    protected function buildBaselineComparison(?AgentEvalBaselineChoice $agentEvalBaselineChoice, AgentEvalCaseRowsOfRun $agentEvalCaseRowsOfRun, AgentEvalRunSummary $agentEvalRunSummary, float $tolerancePercent): ?AgentEvalBaselineComparison
    {
        if ($agentEvalBaselineChoice?->directory === null) {
            return null;
        }
        $baselineRunSummary = $this->getResultFilesService()->findBaselineRunSummary($agentEvalBaselineChoice->directory, $agentEvalCaseRowsOfRun->modelName, $agentEvalCaseRowsOfRun->flavor);
        if ($baselineRunSummary === null) {
            return null;
        }
        return AgentEvalBaselineComparison::compareCaseRows($agentEvalRunSummary->caseRows, $baselineRunSummary->caseRows, $tolerancePercent);
    }

    /** The run's record in the database, for readers without this host's files; best effort, prints nothing. */
    protected function recordStoredRun(?string $label, int $startedAtTimestamp, AgentEvalSuiteRecord $agentEvalSuiteRecord, string $suiteDirectory, AgentEvalSuiteRunState $agentEvalSuiteRunState): void
    {
        /** @var AgentEvalSuiteRunsService $agentEvalSuiteRunsService */
        $agentEvalSuiteRunsService = DDDService::instance()->getService(AgentEvalSuiteRunsService::class);
        $agentEvalSuiteRunsService->recordSuiteRun(
            (string)$this->getName(),
            $label,
            $startedAtTimestamp,
            AgentEvalStoredRunOptions::forSuiteRecord($agentEvalSuiteRecord),
            basename($suiteDirectory),
            $agentEvalSuiteRunState->rowsByRun->buildAllRows()
        );
    }

    // ------------------------------------------------------------------ the report

    /** The end-of-run report: per run its figures, then the two verdicts and the times. */
    protected function writeReport(OutputInterface $output, AgentEvalSuiteRecord $agentEvalSuiteRecord, string $suiteDirectory): void
    {
        $output->writeln('');
        foreach ($agentEvalSuiteRecord->runs->getElements() as $agentEvalSuiteRecordRun) {
            $this->writeRunReport($output, $agentEvalSuiteRecordRun);
            $this->writeBaselineComparisonLines($output, $agentEvalSuiteRecordRun->baselineComparison);
            $this->writeCaseListLines($output, $agentEvalSuiteRecordRun);
        }
        $output->writeln('');
        $this->writeVerdictLines($output, $agentEvalSuiteRecord, $suiteDirectory);
    }

    /** One run's pass rate, its figures per suite, its performance and its speed. */
    protected function writeRunReport(OutputInterface $output, AgentEvalSuiteRecordRun $agentEvalSuiteRecordRun): void
    {
        $agentEvalRunSummary = $agentEvalSuiteRecordRun->runSummary;
        $output->writeln(sprintf(
            '<info>%s · %s</info>: %d/%d passed (%.1f %%)%s%s',
            $agentEvalSuiteRecordRun->modelName,
            $agentEvalSuiteRecordRun->flavor,
            $agentEvalRunSummary->passed,
            $agentEvalRunSummary->total,
            $agentEvalRunSummary->total > 0 ? 100 * $agentEvalRunSummary->passed / $agentEvalRunSummary->total : 0,
            $agentEvalRunSummary->errors > 0 ? sprintf(' · <error>%d error(s)</error>', $agentEvalRunSummary->errors) : '',
            $agentEvalRunSummary->notApplicable > 0 ? sprintf(' · %d not applicable on their %s (not counted)', $agentEvalRunSummary->notApplicable, $this->getAgentSpaceWord()) : ''
        ));
        foreach ($agentEvalSuiteRecordRun->buildFiguresBySuite()->getElements() as $agentEvalFiguresOfSuite) {
            $output->writeln(sprintf(
                '    %-11s %3d/%-3d · %.2f calls/case · %.1f s/owner turn · $%.4f/case',
                $agentEvalFiguresOfSuite->suite,
                $agentEvalFiguresOfSuite->passed,
                $agentEvalFiguresOfSuite->total,
                $agentEvalFiguresOfSuite->performanceRollUp->avgModelCallsPerCase ?? 0.0,
                $agentEvalFiguresOfSuite->performanceRollUp->avgSecondsPerOwnerTurn ?? 0.0,
                $agentEvalFiguresOfSuite->performanceRollUp->avgCostPerCaseUsd ?? 0.0
            ));
        }
        $agentEvalPerformanceRollUp = $agentEvalRunSummary->performanceRollUp;
        $output->writeln(sprintf(
            '    performance: %.2f model calls/case · %.2f owner turns/case · %.1f s/owner turn (median %.1f, p90 %.1f) · %.0f tokens/case · $%.4f/case · decision layer %d ms/owner turn',
            $agentEvalPerformanceRollUp->avgModelCallsPerCase,
            $agentEvalPerformanceRollUp->avgOwnerTurnsPerCase,
            $agentEvalPerformanceRollUp->avgSecondsPerOwnerTurn,
            $agentEvalPerformanceRollUp->medianSecondsPerOwnerTurn,
            $agentEvalPerformanceRollUp->p90SecondsPerOwnerTurn,
            $agentEvalPerformanceRollUp->avgTotalTokensPerCase,
            $agentEvalPerformanceRollUp->avgCostPerCaseUsd,
            $agentEvalPerformanceRollUp->avgDecisionModelMsPerOwnerTurn
        ));
        foreach ($agentEvalPerformanceRollUp->buildSpeedKpiLines() as $speedKpiLine) {
            $output->writeln('    ' . $speedKpiLine);
        }
    }

    /** What grew or shrank against the baseline, and the cases whose model calls grew; nothing when not comparable. */
    protected function writeBaselineComparisonLines(OutputInterface $output, ?AgentEvalBaselineComparison $agentEvalBaselineComparison): void
    {
        if ($agentEvalBaselineComparison === null || !$agentEvalBaselineComparison->comparable) {
            return;
        }
        $output->writeln(sprintf('    against the baseline (%d common cases, tolerance %.0f %%):', $agentEvalBaselineComparison->commonCases, $agentEvalBaselineComparison->tolerancePercent));
        foreach ($agentEvalBaselineComparison->regressions->getElements() as $agentEvalMetricChange) {
            $output->writeln(sprintf('      <error>slower</error>  %-26s %s → %s (%+.1f %%)', $agentEvalMetricChange->metric, $agentEvalMetricChange->baselineValue, $agentEvalMetricChange->currentValue, $agentEvalMetricChange->changePercent));
        }
        foreach ($agentEvalBaselineComparison->improvements->getElements() as $agentEvalMetricChange) {
            $output->writeln(sprintf('      <info>better</info>  %-26s %s → %s (%+.1f %%)', $agentEvalMetricChange->metric, $agentEvalMetricChange->baselineValue, $agentEvalMetricChange->currentValue, $agentEvalMetricChange->changePercent));
        }
        foreach (array_slice($agentEvalBaselineComparison->caseRegressions->getElements(), 0, 10) as $agentEvalCaseRegression) {
            $output->writeln(sprintf('      <comment>case</comment>    %-40s %d → %d model calls', $agentEvalCaseRegression->caseId, $agentEvalCaseRegression->baselineModelCalls, $agentEvalCaseRegression->modelCalls));
        }
        if ($agentEvalBaselineComparison->regressions->count() === 0 && $agentEvalBaselineComparison->improvements->count() === 0) {
            $output->writeln('      inside the tolerance');
        }
    }

    /** The cases a reader looks at first: refused restores, retries, cases that do not apply, failures. */
    protected function writeCaseListLines(OutputInterface $output, AgentEvalSuiteRecordRun $agentEvalSuiteRecordRun): void
    {
        $agentEvalCaseRows = $agentEvalSuiteRecordRun->runSummary->caseRows;
        $restoreFailedCases = $agentEvalCaseRows->describeRestoreFailedCases();
        if ($restoreFailedCases !== []) {
            $output->writeln(sprintf('    <error>%d case(s) left their %s MUTATED — a restore step was refused:</error> %s', count($restoreFailedCases), $this->getAgentSpaceWord(), implode(', ', $restoreFailedCases)));
        }
        $retriedCases = $agentEvalCaseRows->describeRetriedCases();
        if ($retriedCases !== []) {
            $output->writeln(sprintf('    %d case(s) ran twice after an infrastructure error: %s', count($retriedCases), implode(', ', $retriedCases)));
        }
        foreach (array_slice($agentEvalCaseRows->describeNotApplicableCases(), 0, 40) as $notApplicableCase) {
            $output->writeln('    <fg=gray>– ' . mb_substr($notApplicableCase, 0, static::CASE_LINE_TEXT_LENGTH) . '</>');
        }
        foreach (array_slice($agentEvalCaseRows->describeFailedCases(), 0, 40) as $failedCase) {
            $output->writeln('    <fg=red>✗ ' . $failedCase . '</>');
        }
    }

    /** The two verdicts, and the wall time against the case time and the plan. */
    protected function writeVerdictLines(OutputInterface $output, AgentEvalSuiteRecord $agentEvalSuiteRecord, string $suiteDirectory): void
    {
        $correctnessVerdict = $agentEvalSuiteRecord->getCorrectnessVerdict();
        $output->writeln(sprintf(
            'Verdict: correctness <%s>%s</> (%d/%d) · performance %s',
            $correctnessVerdict === AgentEvalSuiteRecord::VERDICT_PASSED ? 'info' : 'error',
            $correctnessVerdict,
            $agentEvalSuiteRecord->getPassed(),
            $agentEvalSuiteRecord->getTotal(),
            match ($agentEvalSuiteRecord->getPerformanceVerdict()) {
                AgentEvalSuiteRecord::VERDICT_OK => '<info>OK</info>',
                AgentEvalSuiteRecord::VERDICT_REGRESSED => '<error>REGRESSED</error>',
                default => 'not compared (no baseline)',
            }
        ));
        $output->writeln(sprintf(
            'Wall time %s for %s of case time (× %s) — planned %s. Saved: <fg=gray>%s/</>',
            $this->renderDuration($agentEvalSuiteRecord->wallSeconds),
            $this->renderDuration((int)round($agentEvalSuiteRecord->processSeconds)),
            (string)($agentEvalSuiteRecord->getSpeedUp() ?? '?'),
            $this->renderDuration($agentEvalSuiteRecord->planSummary->estimatedWallSeconds),
            $suiteDirectory
        ));
    }

    // ------------------------------------------------------------------ helpers

    /** What the application calls an agent space in a sentence ({@see AgentApplicationWording::$agentSpaceName}). */
    protected function getAgentSpaceWord(): string
    {
        return AgentApplicationWording::current()->agentSpaceName;
    }

    /**
     * The values of a comma list, trimmed, empty and repeated ones dropped; with `$allowedValues` each value is matched
     * without regard to case and written as allowed.
     *
     * @param string $list
     * @param string[]|null $allowedValues null = any non-empty value
     * @param string $optionName
     * @return string[]
     * @throws RuntimeException when the list is empty or names an unknown value
     */
    protected function parseList(string $list, ?array $allowedValues, string $optionName): array
    {
        $values = array_values(array_unique(array_filter(array_map('trim', explode(',', $list)), static fn (string $value): bool => $value !== '')));
        if ($values === []) {
            throw new RuntimeException("--$optionName is empty");
        }
        if ($allowedValues !== null) {
            $allowedValuesByLowerCase = array_combine(array_map('strtolower', $allowedValues), $allowedValues);
            foreach ($values as $index => $value) {
                if (!isset($allowedValuesByLowerCase[strtolower($value)])) {
                    throw new RuntimeException("--$optionName: unknown value '$value' (allowed: " . implode(', ', $allowedValues) . ')');
                }
                $values[$index] = $allowedValuesByLowerCase[strtolower($value)];
            }
        }
        return $values;
    }

    /** The trimmed value of an option; null when it is not given or empty. */
    protected function stringOption(InputInterface $input, string $optionName): ?string
    {
        $optionValue = $input->getOption($optionName);
        return is_string($optionValue) && trim($optionValue) !== '' ? trim($optionValue) : null;
    }

    /**
     * `name count · name count`, a count that is no scalar written as JSON.
     *
     * @param array $counts name → count (a scalar map)
     * @return string
     */
    protected function renderCounts(array $counts): string
    {
        $countTexts = [];
        foreach ($counts as $name => $count) {
            $countTexts[] = $name . ' ' . (is_scalar($count) ? (string)$count : json_encode($count));
        }
        return implode(' · ', $countTexts);
    }

    /** `1 h 05 min` from an hour on, else `4 min 07 s`. */
    protected function renderDuration(int $seconds): string
    {
        return $seconds >= 3600
            ? sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60))
            : sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
    }

    /** The planning service (the application's class, when it replaces it). */
    protected function getPlanService(): AgentEvalSuitePlanService
    {
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        return $agentEvalSuitePlanService;
    }

    /** The result files service (the application's class, when it replaces it). */
    protected function getResultFilesService(): AgentEvalResultFilesService
    {
        /** @var AgentEvalResultFilesService $agentEvalResultFilesService */
        $agentEvalResultFilesService = DDDService::instance()->getService(AgentEvalResultFilesService::class);
        return $agentEvalResultFilesService;
    }

    /** The model catalog of `mgamadeus/ddd-ai`. */
    protected function getAIModelsService(): AIModelsService
    {
        /** @var AIModelsService $aIModelsService */
        $aIModelsService = DDDService::instance()->getService(AIModelsService::class);
        return $aIModelsService;
    }
}
