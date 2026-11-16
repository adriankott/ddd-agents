<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentEvalResult;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRunMeasurement;
use DDD\Domain\AI\Entities\Evals\AgentEvalExpectation;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixture;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixtures;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedCaseTexts;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalPerformanceBudget;
use DDD\Domain\AI\Entities\Evals\AgentEvalPlanDynamicsSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunFigures;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOutcome;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimRunResult;
use DDD\Domain\AI\Entities\Evals\AgentEvalSkillPreloadSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalStateAfterRunCheck;
use DDD\Domain\AI\Entities\Evals\AgentEvalStoredRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalPreconditionNotEstablishedException;
use DDD\Domain\AI\Services\Eval\AgentEvalCasePackagesService;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunningCase;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunningCases;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalToolsCaseRun;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalToolsRunSettings;
use DDD\Symfony\Commands\AI\ModelTierOptionTrait;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The tools evaluation: for each case of the tools fixtures, the agent runs the case's prompt and the case's
 * expectation checks the outcome — the expected skill loaded, the expected tool called (any of a list), its terminal
 * status, its arguments ({@see AgentEvalExpectation}), the case's performance budget and its state after the run. With
 * `--judge` the same cases run in the judged flavor: a simulated owner drives the case over several turns and a judge
 * model gives the verdict ({@see AgentEvalJudgedRunService}). Reports per case and overall, writes the run into the
 * results folder ({@see AgentEvalResultFilesService}) and stores its record ({@see AgentEvalSuiteRunsService}).
 *
 * Each case names its agent space; the scope options (`--agentSpaceId` and the application's own) name the space of the
 * cases that name none. A case that does not apply to its space is not run and counts neither as a pass nor as a fail.
 *
 * Two ways to run:
 *
 *  - in this process, one case after the other (a case filter, or `--parallel=1` without repetitions);
 *  - in child processes of this command, one per case run ({@see AgentEvalToolsRunSettings::runsInChildProcesses()}):
 *    the cases are planned and started by the suite schedule ({@see AgentEvalSuitePlanService::buildToolsTasks()},
 *    {@see AgentEvalSuiteSchedule}) over one model in one flavor — reads beside reads, a case that writes in the write
 *    lane of its resource — each case run `--repeat` times; the run folder holds one file per case run and the
 *    summary.
 *
 * Override points for an application's subclass (it repeats `#[AsCommand]` with this name): a case of its own shape
 * ({@see runCaseOfOwnShape()}), the scope of its plan options ({@see buildPlanOptionsOfRun()}), and the words of its
 * output (the `*_LABEL` and `*_SKIP_REASON` constants).
 *
 *   php bin/console app:ai-agent:eval-tools [--skill=<skill>] [--case=<case id>] [--judge] [--repeat=3] [--json] [--full]
 */
#[AsCommand(
    name: 'app:ai-agent:eval-tools',
    description: 'Run the per-tool eval set (skill + tool routing + status + arg checks); report pass rates (live).',
)]
class AgentEvalToolsCommand extends AgentEvalConsoleCommand
{
    use ModelTierOptionTrait, AgentEvalArmOptionsTrait;

    /** @var string The name under which the configuration names the default fixture folder (`AI.evalSuite.fixturePaths`) */
    public const string FIXTURE_PATH_NAME = 'tools';

    /** @var string The suite the cases of a run in child processes are planned under */
    public const string SUITE_NAME = 'tools';

    /** @var string What the output calls the decision model in front of its figures */
    public const string DECISION_MODEL_LABEL = 'decision model';

    /** @var string Why a case whose flow exists only on the job queue is skipped */
    public const string ASYNC_ONLY_SKIP_REASON = 'async-only case — covered by the job-queue runner (--include-async to force inline)';

    /** @var string Why a case without a scope is skipped */
    public const string MISSING_SCOPE_SKIP_REASON = 'no agent space (case or --agentSpaceId)';

    /** @var int Microseconds between two looks at the running child processes */
    public const int CHILD_POLL_MICROSECONDS = 100_000;

    /** @var int The characters of a final text a case line shows */
    public const int FINAL_TEXT_PREVIEW_LENGTH = 500;

    /** @var int The characters of the judge's reasons a case line shows */
    public const int VERDICT_REASONS_PREVIEW_LENGTH = 600;

    protected function configure(): void
    {
        $this->configureEvalScopeOptions();
        $this
            ->addOption('fixture', null, InputOption::VALUE_OPTIONAL, 'A single fixture JSON path (default: every fixture of the configured tools fixture folder)')
            ->addOption('skill', null, InputOption::VALUE_OPTIONAL, 'Only run the fixture whose "skill" matches this id')
            ->addOption('case', null, InputOption::VALUE_OPTIONAL, 'Only run the case with this id')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Show tool arguments/results even for passing cases')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON results instead of the report')
            ->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Override the agent model by catalog name; default = the conversation default')
            ->addOption('repeat', null, InputOption::VALUE_OPTIONAL, 'Run every selected case N times (each repetition = its own child process, file <caseId>.rep<k>.json) and report per case how many of the N runs passed (metrics.repetitions). Works with --case too. Default 1.', '1')
            ->addOption('parallel', null, InputOption::VALUE_OPTIONAL, 'Run cases in child processes, N at a time: reads beside reads, a case that writes in the write lane of its resource. Default 10; pass --parallel=1 to run the cases one after the other in this process.', '10')
            ->addOption('no-save', null, InputOption::VALUE_NONE, 'Do not write the run into the results folder and store no record (a child process: its parent writes the case files).')
            ->addOption('no-prune', null, InputOption::VALUE_NONE, 'Skip the retention sweep of the results folder (run entries older than ' . $this->getResultFilesService()->getRetentionDays() . ' days are deleted at every start).')
            ->addOption('judge', null, InputOption::VALUE_NONE, 'Judged flavor of the same cases: a simulated owner drives each case over several turns and a judge model gives an outcome and grounding verdict (the case\'s goal / userFacts / successRubric, else defaults from its prompt and expected tool).')
            ->addOption('judge-model', null, InputOption::VALUE_OPTIONAL, 'The judge (verdict) model of --judge (default: the judged run\'s default).')
            ->addOption('owner-device', null, InputOption::VALUE_OPTIONAL, 'The simulated owner\'s device, stamped on every owner message: ' . implode(', ', OwnerDevice::SURFACES) . '. Default: none.')
            ->addOption('driver-model', null, InputOption::VALUE_OPTIONAL, 'The simulated owner (driver) model of --judge (default: the judged run\'s default).')
            ->addOption('include-async', null, InputOption::VALUE_NONE, 'Also run cases marked `executionMode: async` inline (debugging only); without it they are skipped.')
            ->addOption('transient', null, InputOption::VALUE_NONE, 'Do not store the eval conversations: no decision site runs, and what a case creates cannot be cleaned up after it. Ignored when an arm option is passed.')
            ->addOption('persist-conversations', null, InputOption::VALUE_NONE, 'Store the eval conversations (marked as eval runs; the default). The decision layer runs only in a stored conversation. Handed to every child process.')
            ->addOption('result-file', null, InputOption::VALUE_OPTIONAL, 'Also write the result JSON to this file (what a parent process reads instead of parsing the output).');
        $this->configureBudgetOption();
        $this->addModelTierOptions();
        $this->addEvalArmOptions();
    }

    /**
     * Reads the run's settings and fixtures, sweeps the results folder, runs the cases (in this process or in child
     * processes), then prints the roll-ups, writes the run's files, stores its record and prints the report (or the
     * result document with `--json`). Exits with success when every case that counts passed.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        // heavy aggregate tools exceed the console default
        ini_set('memory_limit', static::CHILD_PROCESS_MEMORY_LIMIT);
        $runSettings = $this->buildRunSettings($input);
        $agentEvalFixtures = $this->findFixturesOfRun($runSettings);
        if ($agentEvalFixtures->count() === 0) {
            $output->writeln('<error>No tool-eval fixtures found</error>');
            return Command::FAILURE;
        }
        if (!$runSettings->noSave && !$runSettings->noPrune) {
            $this->sweepResultsFolder($runSettings, $output);
        }
        $agentEvalResultFilesService = $this->getResultFilesService();
        $runSettings->startedAtTimestamp = time();
        $runSettings->runName = $agentEvalResultFilesService->getToolsRunName($runSettings->startedAtTimestamp, $runSettings->modelName, $runSettings->scopeTag);

        $agentEvalCaseRows = $runSettings->runsInChildProcesses()
            ? $this->runCasesInChildProcesses($runSettings, $agentEvalFixtures, $input, $output)
            : $this->runCasesInProcess($runSettings, $agentEvalFixtures, $output);

        $agentEvalRunSummary = $this->buildRunSummary($runSettings, $agentEvalCaseRows);
        if (!$runSettings->jsonOutput) {
            $this->printRollUpLines($agentEvalRunSummary, $output);
        }
        $savedPath = $this->writeRunFiles($runSettings, $agentEvalRunSummary);
        if ($runSettings->jsonOutput) {
            $output->writeln($agentEvalResultFilesService->encodeJsonDocument($agentEvalRunSummary->toArray(true)));
            return $agentEvalRunSummary->hasEveryCasePassed() ? Command::SUCCESS : Command::FAILURE;
        }
        $this->printRunReport($agentEvalRunSummary, $savedPath, $output);
        return $agentEvalRunSummary->hasEveryCasePassed() ? Command::SUCCESS : Command::FAILURE;
    }

    // ------------------------------------------------------------------ the settings and the fixtures of the run

    /**
     * The settings of the run, read from the command line.
     *
     * @param InputInterface $input
     * @return AgentEvalToolsRunSettings
     * @throws InvalidArgumentException on an unknown owner device
     * @throws Throwable when a scope option names no space
     */
    protected function buildRunSettings(InputInterface $input): AgentEvalToolsRunSettings
    {
        $runSettings = AgentEvalToolsRunSettings::newInstance();
        $runSettings->modelName = $this->resolveEffectiveModelName($input);
        $runSettings->flavor = (bool)$input->getOption('judge') ? AgentEvalSuiteTask::FLAVOR_JUDGE : AgentEvalSuiteTask::FLAVOR_CALL;
        $runSettings->judgeModelName = $this->readNonEmptyStringOption($input, 'judge-model');
        $runSettings->driverModelName = $this->readNonEmptyStringOption($input, 'driver-model');
        $runSettings->skillFilter = $this->readNonEmptyStringOption($input, 'skill');
        $runSettings->caseFilter = $this->readNonEmptyStringOption($input, 'case');
        $runSettings->fixtureOption = $this->readNonEmptyStringOption($input, 'fixture');
        $runSettings->resultFile = $this->readNonEmptyStringOption($input, 'result-file');
        $runSettings->fullOutput = (bool)$input->getOption('full');
        $runSettings->jsonOutput = (bool)$input->getOption('json');
        $runSettings->noSave = (bool)$input->getOption('no-save');
        $runSettings->noPrune = (bool)$input->getOption('no-prune');
        $runSettings->repeat = max(1, (int)$input->getOption('repeat'));
        $runSettings->parallel = max(1, (int)$input->getOption('parallel'));
        $runSettings->includeAsyncOnlyCases = (bool)$input->getOption('include-async');
        $runSettings->evalArms = $this->resolveEvalArmOptions($input);
        $runSettings->agentEvalArms = $this->buildEvalArms($runSettings->evalArms);
        // stored unless `--transient`: the decision layer runs only in a stored conversation; an arm stores them
        $runSettings->persistConversations = !(bool)$input->getOption('transient') || (bool)$input->getOption('persist-conversations') || $runSettings->evalArms !== [];
        $runSettings->ownerDeviceSurface = (string)($input->getOption('owner-device') ?? '');
        $runSettings->simulatedOwnerDevice = $this->buildSimulatedOwnerDevice($runSettings->ownerDeviceSurface);
        $runSettings->fallbackAgentSpaceId = $this->resolveFallbackAgentSpaceId($input);
        $runSettings->modelCanSeeImages = $this->modelCanSeeImages($runSettings->modelName);
        $runSettings->scopeTag = $this->getResultFilesService()->getScopeTagOfToolsRun($runSettings->skillFilter, $runSettings->caseFilter);
        return $runSettings;
    }

    /**
     * The simulated owner device of a surface name; null for an empty name.
     *
     * @throws InvalidArgumentException on a surface name that is no device
     */
    protected function buildSimulatedOwnerDevice(string $ownerDeviceSurface): ?OwnerDevice
    {
        if ($ownerDeviceSurface === '') {
            return null;
        }
        $simulatedOwnerDevice = OwnerDevice::simulated($ownerDeviceSurface);
        if ($simulatedOwnerDevice === null) {
            throw new InvalidArgumentException('--owner-device must be one of ' . implode(', ', OwnerDevice::SURFACES) . ', got "' . $ownerDeviceSurface . '".');
        }
        return $simulatedOwnerDevice;
    }

    /** Whether the model under test can see images: a case that requires vision runs only then (the default model cannot). */
    protected function modelCanSeeImages(?string $modelName): bool
    {
        if ($modelName === null || $modelName === '') {
            return false;
        }
        return $this->getAIModelsService()->getAIModelByName($modelName)?->hasVisionCapabilities === true;
    }

    /**
     * The fixtures of the run: the `--fixture` file, else every fixture of the configured tools folder; a file that is
     * no JSON object with a `cases` list is left out.
     */
    protected function findFixturesOfRun(AgentEvalToolsRunSettings $runSettings): AgentEvalFixtures
    {
        $agentEvalFixtures = AgentEvalFixtures::newInstance();
        foreach ($this->findFixtureFilesOfRun($runSettings) as $fixturePath) {
            $decodedFixture = json_decode((string)file_get_contents($fixturePath), true);
            if (is_array($decodedFixture) && is_array($decodedFixture['cases'] ?? null)) {
                $agentEvalFixture = AgentEvalFixture::fromDecodedFile($fixturePath, $decodedFixture);
                $agentEvalFixtures->add($agentEvalFixture);
            }
        }
        return $agentEvalFixtures;
    }

    /**
     * The fixture files of the run: the `--fixture` file as an absolute path (a child process gets it as the planning
     * read it), else the `*.json` files of the folder the configuration names for {@see FIXTURE_PATH_NAME}.
     *
     * @return string[]
     */
    protected function findFixtureFilesOfRun(AgentEvalToolsRunSettings $runSettings): array
    {
        if ($runSettings->fixtureOption !== null) {
            return is_file($runSettings->fixtureOption) ? [(string)realpath($runSettings->fixtureOption)] : [];
        }
        $agentEvalSuitePlanService = $this->getSuitePlanService();
        $fixturePath = $agentEvalSuitePlanService->findFixturePath(static::FIXTURE_PATH_NAME);
        return $fixturePath !== null ? $agentEvalSuitePlanService->findFixtureFilesAt($fixturePath) : [];
    }

    /** Whether a fixture belongs to the run: no skill filter, or the fixture's skill is the filter. */
    protected function matchesSkillFilter(AgentEvalToolsRunSettings $runSettings, AgentEvalFixture $agentEvalFixture): bool
    {
        return $runSettings->skillFilter === null || $agentEvalFixture->getSkillName() === $runSettings->skillFilter;
    }

    /** The retention sweep of the results folder, with its line for the operator. */
    protected function sweepResultsFolder(AgentEvalToolsRunSettings $runSettings, OutputInterface $output): void
    {
        $reportLine = $this->getResultFilesService()->sweepRunEntriesOlderThanRetention()->buildReportLine();
        if ($reportLine !== null && !$runSettings->jsonOutput) {
            $output->writeln('<fg=gray>' . $reportLine . '</>');
        }
    }

    /**
     * The options of the run's plan: one model in one flavor, the vision of the model, the job-queue cases, the case
     * filter and the fallback agent space (the skill filter is applied to the fixtures before the planning). An
     * application whose plan options carry a scope of its own fills it here.
     */
    protected function buildPlanOptionsOfRun(AgentEvalToolsRunSettings $runSettings): AgentEvalSuitePlanOptions
    {
        $agentEvalSuitePlanOptions = $this->getSuitePlanService()->buildPlanOptions([$runSettings->modelName], [$runSettings->flavor]);
        $agentEvalSuitePlanOptions->visionCapabilityByModelName = [(string)$runSettings->modelName => $runSettings->modelCanSeeImages];
        $agentEvalSuitePlanOptions->includeAsyncOnlyCases = $runSettings->includeAsyncOnlyCases;
        $agentEvalSuitePlanOptions->caseFilter = $runSettings->caseFilter;
        $agentEvalSuitePlanOptions->fallbackAgentSpaceId = $runSettings->fallbackAgentSpaceId;
        return $agentEvalSuitePlanOptions;
    }

    /** The scope the planning assigns a case (the case's own, else the fallback); null when it has none. */
    protected function findScopeIdOfCase(AgentEvalCase $agentEvalCase, AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions): ?int
    {
        $agentEvalSuitePlanService = $this->getSuitePlanService();
        $agentEvalSuiteTask = $agentEvalSuitePlanService->createTask();
        $agentEvalSuitePlanService->assignScopeOfCaseToTask($agentEvalSuiteTask, $agentEvalCase, $agentEvalSuitePlanOptions, true);
        return $agentEvalSuiteTask->getScopeId();
    }

    /**
     * The SKIP line of a case the run leaves out — its flow exists only on the job queue, it requires vision the model
     * lacks, or it has no scope — or null for a case that runs.
     */
    protected function buildSkipLineOfCase(AgentEvalToolsRunSettings $runSettings, AgentEvalCase $agentEvalCase, ?int $scopeId): ?string
    {
        if ($agentEvalCase->isAsyncOnly() && !$runSettings->includeAsyncOnlyCases) {
            return sprintf('  <comment>SKIP</comment> %-32s %s', $agentEvalCase->getId(), static::ASYNC_ONLY_SKIP_REASON);
        }
        if ($agentEvalCase->requiresVision() && !$runSettings->modelCanSeeImages) {
            return sprintf('  <comment>SKIP</comment> %-32s needs a vision-capable model; %s cannot see images', $agentEvalCase->getId(), $runSettings->modelName ?? 'the default model');
        }
        if ($scopeId === null) {
            return sprintf('  <error>SKIP</error> %-32s %s — %s', $agentEvalCase->getId(), static::MISSING_SCOPE_SKIP_REASON, $agentEvalCase->getPrompt());
        }
        return null;
    }

    /**
     * The options of one case's run: the run's arms, whether its conversations are stored, the simulated owner device,
     * the model, the read-only switch, the judge's and the driver's model, and the run's progress reporter (the
     * restore and teardown lines on the error output).
     */
    protected function buildRunOptionsOfCase(AgentEvalToolsRunSettings $runSettings, bool $everyWriteToolBlocked): AgentEvalJudgedRunOptions
    {
        $agentEvalJudgedRunOptions = AgentEvalJudgedRunOptions::forArms($this->buildEvalArms($runSettings->evalArms));
        $agentEvalJudgedRunOptions->judgeModelName = $runSettings->judgeModelName;
        $agentEvalJudgedRunOptions->driverModelName = $runSettings->driverModelName;
        $agentEvalJudgedRunOptions->persistConversations = $runSettings->persistConversations;
        $agentEvalJudgedRunOptions->simulatedOwnerDevice = $runSettings->simulatedOwnerDevice;
        $agentEvalJudgedRunOptions->aiModelName = $runSettings->modelName;
        $agentEvalJudgedRunOptions->everyWriteToolBlocked = $everyWriteToolBlocked;
        $agentEvalJudgedRunOptions->progressReporter = $this->getProgressReporter();
        return $agentEvalJudgedRunOptions;
    }

    // ------------------------------------------------------------------ the run in this process

    /**
     * Drives the cases of the run one after the other in this process: per fixture of the run its skill line, per
     * case of it the case's row (or its SKIP line).
     */
    protected function runCasesInProcess(AgentEvalToolsRunSettings $runSettings, AgentEvalFixtures $agentEvalFixtures, OutputInterface $output): AgentEvalCaseRows
    {
        $agentEvalCaseRows = AgentEvalCaseRows::newInstance();
        $agentEvalSuitePlanOptions = $this->buildPlanOptionsOfRun($runSettings);
        foreach ($agentEvalFixtures->getElements() as $agentEvalFixture) {
            if (!$this->matchesSkillFilter($runSettings, $agentEvalFixture)) {
                continue;
            }
            if (!$runSettings->jsonOutput) {
                $output->writeln("\n<info>{$agentEvalFixture->getSkillName()}</info>");
            }
            foreach ($agentEvalFixture->getCases()->getElements() as $agentEvalCase) {
                if ($runSettings->caseFilter !== null && $agentEvalCase->getId() !== $runSettings->caseFilter) {
                    continue;
                }
                $agentEvalToolsCaseRun = $this->prepareCaseRun($runSettings, $agentEvalFixture, $agentEvalCase, $agentEvalSuitePlanOptions, $output);
                if ($agentEvalToolsCaseRun !== null) {
                    $agentEvalCaseRow = $this->runCase($runSettings, $agentEvalToolsCaseRun, $output);
                    $agentEvalCaseRows->add($agentEvalCaseRow);
                }
            }
        }
        return $agentEvalCaseRows;
    }

    /**
     * The run of one case, with its scope and its options; null (its SKIP line printed) for a case the run leaves out.
     * A case whose access is a read runs with every write tool refused.
     */
    protected function prepareCaseRun(
        AgentEvalToolsRunSettings $runSettings,
        AgentEvalFixture $agentEvalFixture,
        AgentEvalCase $agentEvalCase,
        AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions,
        OutputInterface $output
    ): ?AgentEvalToolsCaseRun {
        $scopeId = $this->findScopeIdOfCase($agentEvalCase, $agentEvalSuitePlanOptions);
        $skipLine = $this->buildSkipLineOfCase($runSettings, $agentEvalCase, $scopeId);
        if ($skipLine !== null) {
            if (!$runSettings->jsonOutput) {
                $output->writeln($skipLine);
            }
            return null;
        }
        $everyWriteToolBlocked = $this->getSuitePlanService()->resolveAccessOfCase($agentEvalCase, $agentEvalFixture->getName()) === AgentEvalSuiteTask::ACCESS_READ;
        return AgentEvalToolsCaseRun::forCaseOfFixture($agentEvalCase, $agentEvalFixture, $scopeId, $this->buildRunOptionsOfCase($runSettings, $everyWriteToolBlocked));
    }

    /**
     * The row of one case: a case that does not apply to its space is not run; else the case of the application's own
     * shape ({@see runCaseOfOwnShape()}), the judged run or the deterministic run.
     */
    protected function runCase(AgentEvalToolsRunSettings $runSettings, AgentEvalToolsCaseRun $agentEvalToolsCaseRun, OutputInterface $output): AgentEvalCaseRow
    {
        $agentEvalNotApplicableReason = $this->getRunnerService()->resolveScopeAndFindNotApplicableReasonForCase(
            $agentEvalToolsCaseRun->agentEvalCase,
            $runSettings->fallbackAgentSpaceId,
            $agentEvalToolsCaseRun->runOptions
        );
        if ($agentEvalNotApplicableReason !== null) {
            return $this->buildNotApplicableRow($runSettings, $agentEvalToolsCaseRun, $runSettings->flavor, $agentEvalNotApplicableReason, $output);
        }
        $agentEvalToolsCaseRun->markRunStarted();
        $ownShapeRow = $this->runCaseOfOwnShape($runSettings, $agentEvalToolsCaseRun, $output);
        if ($ownShapeRow !== null) {
            return $ownShapeRow;
        }
        return $runSettings->isJudged()
            ? $this->runJudgedCase($runSettings, $agentEvalToolsCaseRun, $output)
            : $this->runDeterministicCase($runSettings, $agentEvalToolsCaseRun, $output)
        ;
    }

    /**
     * Override point: the row of a case of the application's own shape (e.g. a scripted flow its own runner drives),
     * run in place of both flavors; null for every other case. The default: null.
     */
    protected function runCaseOfOwnShape(AgentEvalToolsRunSettings $runSettings, AgentEvalToolsCaseRun $agentEvalToolsCaseRun, OutputInterface $output): ?AgentEvalCaseRow
    {
        return null;
    }

    /** The row of a case that does not apply to its space (neither a pass nor a fail), with its N/A line. */
    protected function buildNotApplicableRow(
        AgentEvalToolsRunSettings $runSettings,
        AgentEvalToolsCaseRun $agentEvalToolsCaseRun,
        string $flavor,
        AgentEvalNotApplicableReason $agentEvalNotApplicableReason,
        OutputInterface $output
    ): AgentEvalCaseRow {
        if (!$runSettings->jsonOutput) {
            $output->writeln(sprintf('  <comment>N/A </comment> %-32s %s', $agentEvalToolsCaseRun->getCaseId(), $agentEvalNotApplicableReason->getDetail()));
        }
        return AgentEvalCaseRow::buildNotApplicableOfCase($agentEvalToolsCaseRun->buildNotApplicableRowHeader($flavor), $agentEvalNotApplicableReason);
    }

    /** The row of a case whose run ended in an error (`$flavor` null: the deterministic row names none), with its ERROR line. */
    protected function buildErrorRow(AgentEvalToolsRunSettings $runSettings, AgentEvalToolsCaseRun $agentEvalToolsCaseRun, ?string $flavor, Throwable $throwable, OutputInterface $output): AgentEvalCaseRow
    {
        if (!$runSettings->jsonOutput) {
            $output->writeln(sprintf('  <error>ERROR</error> %-32s %s', $agentEvalToolsCaseRun->getCaseId(), $throwable->getMessage()));
        }
        return AgentEvalCaseRow::buildFailedOfCase($agentEvalToolsCaseRun->buildRowHeader(), $flavor, $throwable->getMessage());
    }

    /** What a case's run measured: its conversation, the wall milliseconds since it started and its message trace. */
    protected function measureRunOfCase(AgentEvalToolsCaseRun $agentEvalToolsCaseRun, AIConversation $conversation): AgentEvalCaseRunMeasurement
    {
        $agentEvalCaseRunMeasurement = AgentEvalCaseRunMeasurement::forConversation($conversation, $agentEvalToolsCaseRun->measureDurationMs());
        $agentEvalCaseRunMeasurement->messagesTrace = $this->getRunnerService()->buildMessagesTraceWithoutConversationBackReference($agentEvalCaseRunMeasurement->messagesOfTree);
        return $agentEvalCaseRunMeasurement;
    }

    /**
     * The failures of the case's performance budget (`expect.performance`) for the run figures `$agentEvalRunFigures`.
     *
     * @return string[]
     */
    protected function evaluatePerformanceBudgetOfCase(AgentEvalCase $agentEvalCase, AgentEvalRunFigures $agentEvalRunFigures): array
    {
        $performanceBudgetBlock = $agentEvalCase->getExpect()['performance'] ?? null;
        return AgentEvalPerformanceBudget::fromBudgetBlock(is_array($performanceBudgetBlock) ? $performanceBudgetBlock : [])->evaluateFigures($agentEvalRunFigures);
    }

    /**
     * Checks the case's state after the run and cleans up what the agent created ({@see AgentEvalRunnerService::evaluateStateAfterRun()});
     * the judged flavor runs the cleanup half only. No check without `expect.stateAfterRun`.
     *
     * @return string[] the failures of the check
     */
    protected function evaluateStateAfterRunOfCase(AgentEvalToolsCaseRun $agentEvalToolsCaseRun, AIConversation $conversation, AgentEvalScope $agentEvalScope, bool $cleanupOnly): array
    {
        $stateAfterRunBlock = $agentEvalToolsCaseRun->agentEvalCase->getStateAfterRun();
        if ($stateAfterRunBlock === null) {
            return [];
        }
        if ($cleanupOnly) {
            $stateAfterRunBlock = ['cleanup' => $stateAfterRunBlock['cleanup'] ?? null] + array_intersect_key($stateAfterRunBlock, ['argsFromToolResult' => 1]);
        }
        return $this->getRunnerService()->evaluateStateAfterRun(
            $conversation,
            $agentEvalScope,
            AgentEvalStateAfterRunCheck::fromStateAfterRunBlock($stateAfterRunBlock),
            $this->getCasePackagesService()->findPackageClassesOfCase($agentEvalToolsCaseRun->agentEvalCase),
            $agentEvalToolsCaseRun->runOptions
        );
    }

    // ------------------------------------------------------------------ the judged flavor

    /**
     * The judged run of a case: a simulated owner drives it over several turns and the judge gives the verdict (a case
     * with a `restore` block inside its restore bracket); the cleanup of its state check; the teardown of what its
     * conversation left, also after an error.
     */
    protected function runJudgedCase(AgentEvalToolsRunSettings $runSettings, AgentEvalToolsCaseRun $agentEvalToolsCaseRun, OutputInterface $output): AgentEvalCaseRow
    {
        $agentEvalRunnerService = $this->getRunnerService();
        $agentEvalCase = $agentEvalToolsCaseRun->agentEvalCase;
        try {
            $agentEvalScope = $agentEvalRunnerService->resolveEvalScope($agentEvalCase, $runSettings->fallbackAgentSpaceId);
            $agentEvalSimCase = AgentEvalSimCase::fromEvaluatedCase($agentEvalCase, AgentEvalJudgedCaseTexts::forCase($agentEvalCase));
            $agentEvalJudgedRunService = $this->getJudgedRunService();
            $agentEvalSimRunResult = $agentEvalCase->getRestore() !== null
                ? $agentEvalJudgedRunService->runSimWithRestore($agentEvalScope, $agentEvalSimCase, $agentEvalToolsCaseRun->runOptions)
                : $agentEvalJudgedRunService->runSim($agentEvalScope, $agentEvalSimCase, $agentEvalToolsCaseRun->runOptions);
            $this->evaluateStateAfterRunOfCase($agentEvalToolsCaseRun, $agentEvalSimRunResult->getConversation(), $agentEvalScope, true);
        } catch (AgentEvalPreconditionNotEstablishedException $preconditionNotEstablished) {
            // a preparation step failed and the agent was not run: neither a pass nor a fail
            $agentEvalNotApplicableReason = AgentEvalNotApplicableReason::buildPreconditionNotEstablishedReason($preconditionNotEstablished->getMessage());
            return $this->buildNotApplicableRow($runSettings, $agentEvalToolsCaseRun, AgentEvalSuiteTask::FLAVOR_JUDGE, $agentEvalNotApplicableReason, $output);
        } catch (Throwable $throwable) {
            return $this->buildErrorRow($runSettings, $agentEvalToolsCaseRun, AgentEvalSuiteTask::FLAVOR_JUDGE, $throwable, $output);
        } finally {
            $agentEvalRunnerService->tearDownResidueOfRun($agentEvalToolsCaseRun->runOptions);
        }
        $agentEvalCaseRunMeasurement = $this->measureRunOfCase($agentEvalToolsCaseRun, $agentEvalSimRunResult->getConversation());
        $agentEvalCaseRow = AgentEvalCaseRow::buildForJudgedRun(
            $agentEvalToolsCaseRun->buildRowHeader(),
            $agentEvalSimRunResult,
            $agentEvalCaseRunMeasurement,
            $this->evaluatePerformanceBudgetOfCase($agentEvalCase, $agentEvalCaseRunMeasurement->runFigures)
        );
        if (!$runSettings->jsonOutput) {
            $this->printJudgedCaseLines($runSettings, $agentEvalToolsCaseRun, $agentEvalCaseRow, $agentEvalSimRunResult, $agentEvalCaseRunMeasurement, $output);
        }
        return $agentEvalCaseRow;
    }

    /** The lines of a judged case: the verdict, the verdict's four flags, the skills and tools, the ungrounded claims, the reasons. */
    protected function printJudgedCaseLines(
        AgentEvalToolsRunSettings $runSettings,
        AgentEvalToolsCaseRun $agentEvalToolsCaseRun,
        AgentEvalCaseRow $agentEvalCaseRow,
        AgentEvalSimRunResult $agentEvalSimRunResult,
        AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement,
        OutputInterface $output
    ): void {
        $agentEvalJudgeVerdict = $agentEvalSimRunResult->getAgentEvalJudgeVerdict();
        $messagesOfTree = $agentEvalCaseRunMeasurement->messagesOfTree;
        $output->writeln(sprintf(
            '  %s %-32s <fg=gray>judge·%st</> — %s',
            $agentEvalCaseRow->isPassed() ? '<info>PASS</info>' : '<error>FAIL</error>',
            $agentEvalToolsCaseRun->getCaseId(),
            (string)$agentEvalSimRunResult->getTurns(),
            $agentEvalToolsCaseRun->agentEvalCase->getPrompt()
        ));
        $output->writeln(sprintf(
            '        <fg=gray>goalMet=%s grounded=%s toolAppropriate=%s clarificationOk=%s</>',
            $this->formatTriState($agentEvalJudgeVerdict->getValue('goalMet')),
            $this->formatTriState($agentEvalJudgeVerdict->getValue('grounded')),
            $this->formatTriState($agentEvalJudgeVerdict->getValue('toolAppropriate')),
            $this->formatTriState($agentEvalJudgeVerdict->getValue('clarificationOk'))
        ));
        // an empty skill list on a tool-expecting case: the agent never loaded the skill, so it never saw the tool
        $loadedSkillNames = $messagesOfTree->getLoadedSkillNames();
        $output->writeln(sprintf(
            '        <fg=gray>loadedSkills=[%s] calledTools=[%s]</>',
            $loadedSkillNames === [] ? '<fg=red>none</>' : implode(',', $loadedSkillNames),
            implode(',', $messagesOfTree->buildToolCallHistory()->getCalledToolNames())
        ));
        foreach ((array)($agentEvalJudgeVerdict->getValue('ungroundedClaims') ?? []) as $ungroundedClaim) {
            $output->writeln(sprintf('        <fg=red>⚠ ungrounded: %s</>', (string)$ungroundedClaim));
        }
        $verdictReasons = trim((string)($agentEvalJudgeVerdict->getValue('reasons') ?? ''));
        if (($runSettings->fullOutput || !$agentEvalCaseRow->isPassed()) && $verdictReasons !== '') {
            $output->writeln(sprintf('        <fg=yellow>%s</>', $this->previewText($verdictReasons, static::VERDICT_REASONS_PREVIEW_LENGTH)));
        }
    }

    // ------------------------------------------------------------------ the deterministic flavor

    /**
     * The deterministic run of a case (a case with a `restore` block inside its restore bracket): the expectation's
     * result, completed by the failures of the performance budget and of the state check after the run; the teardown
     * of what its conversation left, also after an error.
     */
    protected function runDeterministicCase(AgentEvalToolsRunSettings $runSettings, AgentEvalToolsCaseRun $agentEvalToolsCaseRun, OutputInterface $output): AgentEvalCaseRow
    {
        $agentEvalRunnerService = $this->getRunnerService();
        $agentEvalCase = $agentEvalToolsCaseRun->agentEvalCase;
        try {
            $agentEvalScope = $agentEvalRunnerService->resolveEvalScope($agentEvalCase, $runSettings->fallbackAgentSpaceId);
            $agentEvalRunOutcome = $agentEvalRunnerService->runCase($agentEvalScope, $agentEvalCase, $agentEvalToolsCaseRun->runOptions);
            $conversation = $agentEvalRunOutcome->getConversation();
            // a call that leaves out an argument the platform fills counts as carrying the run's value
            $agentEvalResult = AgentEvalExpectation::fromExpectBlock($agentEvalCase->getExpect())->buildEvalResult(
                $conversation,
                $this->getRunHooksService()->getHarnessToolCallArguments($agentEvalScope)
            );
            $agentEvalRunFigures = AgentEvalRunFigures::fromConversation($conversation);
            $agentEvalResult = $this->addFailuresToResult($agentEvalResult, $this->evaluatePerformanceBudgetOfCase($agentEvalCase, $agentEvalRunFigures));
            $agentEvalResult = $this->addFailuresToResult($agentEvalResult, $this->evaluateStateAfterRunOfCase($agentEvalToolsCaseRun, $conversation, $agentEvalScope, false));
        } catch (AgentEvalPreconditionNotEstablishedException $preconditionNotEstablished) {
            // a preparation step failed and the agent was not run: neither a pass nor a fail
            $agentEvalNotApplicableReason = AgentEvalNotApplicableReason::buildPreconditionNotEstablishedReason($preconditionNotEstablished->getMessage());
            return $this->buildNotApplicableRow($runSettings, $agentEvalToolsCaseRun, AgentEvalSuiteTask::FLAVOR_CALL, $agentEvalNotApplicableReason, $output);
        } catch (Throwable $throwable) {
            return $this->buildErrorRow($runSettings, $agentEvalToolsCaseRun, null, $throwable, $output);
        } finally {
            $agentEvalRunnerService->tearDownResidueOfRun($agentEvalToolsCaseRun->runOptions);
        }
        $agentEvalCaseRunMeasurement = $this->measureRunOfCase($agentEvalToolsCaseRun, $conversation);
        // the figures the budget judged are the figures the row stores
        $agentEvalCaseRunMeasurement->runFigures = $agentEvalRunFigures;
        $agentEvalSkillPreloadSummary = $this->buildSkillPreloadSummaryOfRun($agentEvalToolsCaseRun, $conversation);
        $agentEvalPlanDynamicsSummary = $this->buildPlanDynamicsSummaryOfRun($agentEvalToolsCaseRun, $conversation);
        $agentEvalCaseRow = AgentEvalCaseRow::buildForDeterministicRun(
            $agentEvalToolsCaseRun->buildRowHeader(),
            $agentEvalResult,
            $agentEvalCaseRunMeasurement,
            $agentEvalRunOutcome,
            $agentEvalToolsCaseRun->runOptions->persistConversations,
            $agentEvalSkillPreloadSummary,
            $agentEvalPlanDynamicsSummary
        );
        if (!$runSettings->jsonOutput) {
            $this->printDeterministicCaseLines($runSettings, $agentEvalToolsCaseRun, $agentEvalResult, $agentEvalCaseRunMeasurement, $agentEvalRunOutcome, $output);
            $this->printDecisionSummaryLines($agentEvalSkillPreloadSummary, $agentEvalPlanDynamicsSummary, $agentEvalCaseRunMeasurement, $agentEvalRunOutcome, $output);
            $this->printTraceLinesOfFailedOrFullCase($runSettings, $agentEvalResult, $agentEvalCaseRunMeasurement, $output);
        }
        return $agentEvalCaseRow;
    }

    /**
     * `$agentEvalResult` failed with `$failures` appended to its own; unchanged without a failure.
     *
     * @param string[] $failures
     */
    protected function addFailuresToResult(AgentEvalResult $agentEvalResult, array $failures): AgentEvalResult
    {
        if ($failures === []) {
            return $agentEvalResult;
        }
        return new AgentEvalResult(false, array_merge($agentEvalResult->failures, $failures), $agentEvalResult->matchedTool, $agentEvalResult->conversation);
    }

    /** The skill-preload figures of a stored run (every stored run measures them; a transient run none). */
    protected function buildSkillPreloadSummaryOfRun(AgentEvalToolsCaseRun $agentEvalToolsCaseRun, AIConversation $conversation): ?AgentEvalSkillPreloadSummary
    {
        if (!$agentEvalToolsCaseRun->runOptions->persistConversations) {
            return null;
        }
        return AgentEvalSkillPreloadSummary::fromConversation($conversation, $agentEvalToolsCaseRun->agentEvalCase->getExpectedPreloadSkillNamesByTurn());
    }

    /** The plan-dynamics figures of a stored run with a plan-dynamics arm or a case that expects a plan; null otherwise. */
    protected function buildPlanDynamicsSummaryOfRun(AgentEvalToolsCaseRun $agentEvalToolsCaseRun, AIConversation $conversation): ?AgentEvalPlanDynamicsSummary
    {
        $planDynamicsArm = $agentEvalToolsCaseRun->runOptions->getAgentEvalArms()->planDynamicsArm;
        $expectPlan = $agentEvalToolsCaseRun->agentEvalCase->getApplicationValue('expectPlan');
        if (!$agentEvalToolsCaseRun->runOptions->persistConversations || ($planDynamicsArm === null && $expectPlan === null)) {
            return null;
        }
        return AgentEvalPlanDynamicsSummary::fromConversation($conversation, $expectPlan !== null ? (string)$expectPlan : null, $planDynamicsArm);
    }

    /** The lines of a deterministic case: PASS / FAIL with the expected tool and the prompt, then every failure. */
    protected function printDeterministicCaseLines(
        AgentEvalToolsRunSettings $runSettings,
        AgentEvalToolsCaseRun $agentEvalToolsCaseRun,
        AgentEvalResult $agentEvalResult,
        AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement,
        AgentEvalRunOutcome $agentEvalRunOutcome,
        OutputInterface $output
    ): void {
        $output->writeln(sprintf(
            '  %s %-32s <fg=gray>%s</> — %s',
            $agentEvalResult->passed ? '<info>PASS</info>' : '<error>FAIL</error>',
            $agentEvalToolsCaseRun->getCaseId(),
            $agentEvalToolsCaseRun->getToolLabel(),
            $agentEvalToolsCaseRun->agentEvalCase->getPrompt()
        ));
        foreach ($agentEvalResult->failures as $failure) {
            $output->writeln("        <fg=red>✗ $failure</>");
        }
    }

    /** The lines of the decision figures of a deterministic case: the reload, the skill preload per turn, the plan dynamics. */
    protected function printDecisionSummaryLines(
        ?AgentEvalSkillPreloadSummary $agentEvalSkillPreloadSummary,
        ?AgentEvalPlanDynamicsSummary $agentEvalPlanDynamicsSummary,
        AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement,
        AgentEvalRunOutcome $agentEvalRunOutcome,
        OutputInterface $output
    ): void {
        $conversationId = $agentEvalCaseRunMeasurement->getConversationId();
        $conversationNote = $conversationId !== null ? ' · conv ' . $conversationId : '';
        if ($agentEvalSkillPreloadSummary !== null) {
            $output->writeln('        <fg=gray>reload: ' . json_encode($agentEvalRunOutcome->getReloadDiagnostics()) . '</>');
            foreach ($agentEvalSkillPreloadSummary->turns as $preloadTurn) {
                $output->writeln(sprintf(
                    '        <fg=cyan>preload t%d: %s — %s pick %s%s%s · model first load %s%s</>',
                    (int)$preloadTurn['turn'] + 1,
                    (string)$preloadTurn['verdict'],
                    static::DECISION_MODEL_LABEL,
                    $preloadTurn['pick'] !== '' ? (string)$preloadTurn['pick'] : 'none',
                    $preloadTurn['pickedByRerank'] ? ' (rerank)' : '',
                    $preloadTurn['pickConfidence'] !== null ? sprintf(' (conf %.2f · margin %.2f)', (float)$preloadTurn['pickConfidence'], (float)($preloadTurn['pickMargin'] ?? 0)) : '',
                    $preloadTurn['modelFirstLoad'] !== '' ? (string)$preloadTurn['modelFirstLoad'] : 'none',
                    $conversationNote
                ));
            }
        }
        if ($agentEvalPlanDynamicsSummary !== null) {
            $output->writeln(sprintf(
                '        <fg=cyan>plan (%s): expect %s · band %s%s · plans %d · ticks %d · %s%s%s</>',
                (string)$agentEvalPlanDynamicsSummary->arm,
                (string)($agentEvalPlanDynamicsSummary->expectPlan ?? '?'),
                (string)($agentEvalPlanDynamicsSummary->band ?? 'notAsked'),
                $agentEvalPlanDynamicsSummary->needsWorkingPlanProbability !== null ? sprintf(' (P %.2f)', (float)$agentEvalPlanDynamicsSummary->needsWorkingPlanProbability) : '',
                $agentEvalPlanDynamicsSummary->plansCreated,
                $agentEvalPlanDynamicsSummary->ticks,
                $agentEvalPlanDynamicsSummary->verdict,
                $agentEvalPlanDynamicsSummary->retiredFinishedPlan ? ' · retired' : ($agentEvalPlanDynamicsSummary->wouldRetireFinishedPlan ? ' · would retire' : ''),
                $conversationNote
            ));
        }
    }

    /**
     * The trace lines of a failed case (of every case with `--full`): the loaded skills, every tool call with its
     * arguments and status, the final text (empty = the agent stalled).
     */
    protected function printTraceLinesOfFailedOrFullCase(AgentEvalToolsRunSettings $runSettings, AgentEvalResult $agentEvalResult, AgentEvalCaseRunMeasurement $agentEvalCaseRunMeasurement, OutputInterface $output): void
    {
        if (!$runSettings->fullOutput && $agentEvalResult->passed) {
            return;
        }
        $messagesOfTree = $agentEvalCaseRunMeasurement->messagesOfTree;
        $loadedSkillNames = $messagesOfTree->getLoadedSkillNames();
        $output->writeln(sprintf('        <fg=gray>loadedSkills=[%s]</>', $loadedSkillNames === [] ? '<fg=red>none</>' : implode(',', $loadedSkillNames)));
        foreach ($messagesOfTree->buildToolCallHistory()->getToolCallRows() as $toolCallRow) {
            $output->writeln(sprintf(
                '        <fg=gray>→ %s(%s) [%s]</>',
                (string)$toolCallRow->toolCall->name,
                $toolCallRow->toolCall->argumentsAsJson(),
                $toolCallRow->toolCall->status ?? '?'
            ));
        }
        $finalText = trim((string)$messagesOfTree->getFinalAssistantText());
        $shownFinalText = $finalText === '' ? '<empty — no final text>' : $this->previewText($finalText, static::FINAL_TEXT_PREVIEW_LENGTH);
        $output->writeln(sprintf('        <fg=yellow>finalText: %s</>', $shownFinalText));
    }

    // ------------------------------------------------------------------ the run in child processes

    /**
     * Runs every planned case in a child process of this command, scheduled by the suite schedule over one model in
     * one flavor: the cases of the run's fixtures as tasks ({@see AgentEvalSuitePlanService::buildToolsTasks()}), each
     * run `--repeat` times, `--parallel` processes at a time. Every finished case run is a row (a timed-out or silent
     * child a failed one) and a file in the run folder, `<caseId>[.rep<k>].json`.
     */
    protected function runCasesInChildProcesses(AgentEvalToolsRunSettings $runSettings, AgentEvalFixtures $agentEvalFixtures, InputInterface $input, OutputInterface $output): AgentEvalCaseRows
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        $runDirectory = $agentEvalResultFilesService->prepareToolsRunDirectory($runSettings->runName);
        $plannedTasks = $this->planTasksOfRun($runSettings, $agentEvalFixtures, $output);
        if (!$runSettings->jsonOutput) {
            $readingTaskCount = $plannedTasks->buildReadingTasks()->count();
            $output->writeln(sprintf(
                '<info>Parallel:</info> %d read-only case(s) @ concurrency %d + %d sequential case(s) (edit/memory-flow)%s',
                $readingTaskCount,
                $runSettings->parallel,
                $plannedTasks->count() - $readingTaskCount,
                $runSettings->repeat > 1 ? sprintf(', each run %d times', $runSettings->repeat) : ''
            ));
        }
        $agentEvalSuiteSchedule = AgentEvalSuiteSchedule::forProcesses($runSettings->parallel);
        $queuedTasks = $agentEvalSuiteSchedule->orderTasksForStart($plannedTasks->buildRepeatedTasks($runSettings->repeat));
        $runningCases = AgentEvalSuiteRunningCases::newInstance();
        $agentEvalCaseRows = AgentEvalCaseRows::newInstance();
        while ($queuedTasks->count() > 0 || $runningCases->count() > 0) {
            foreach ($agentEvalSuiteSchedule->selectRunnableTasks($queuedTasks, $runningCases->buildTasks())->getElements() as $startingTask) {
                $runningCase = $this->startChildProcessOfTask($startingTask, $input, $runSettings);
                $runningCases->add($runningCase);
                $queuedTasks->removeTask($startingTask);
            }
            foreach ($runningCases->getElements() as $runningCase) {
                $agentEvalCaseRow = $this->findRowOfFinishedChild($runningCase);
                if ($agentEvalCaseRow === null) {
                    continue;
                }
                $finishedTask = $runningCase->task;
                $runningCases->remove($runningCase);
                $agentEvalCaseRows->add($agentEvalCaseRow);
                $agentEvalResultFilesService->writeCaseFileOfToolsRun($runDirectory, $finishedTask->caseId, $finishedTask->repetition, $agentEvalCaseRow);
                if (!$runSettings->jsonOutput) {
                    $output->writeln($this->buildFinishedChildLine($finishedTask, $agentEvalCaseRow));
                }
            }
            if ($queuedTasks->count() > 0 || $runningCases->count() > 0) {
                usleep(static::CHILD_POLL_MICROSECONDS);
            }
        }
        return $agentEvalCaseRows;
    }

    /**
     * The tasks of the run's cases: the SKIP line of every case the planning leaves out (job queue, vision, no scope),
     * then the planning of the rest under {@see SUITE_NAME}.
     */
    protected function planTasksOfRun(AgentEvalToolsRunSettings $runSettings, AgentEvalFixtures $agentEvalFixtures, OutputInterface $output): AgentEvalSuiteTasks
    {
        $agentEvalSuitePlanOptions = $this->buildPlanOptionsOfRun($runSettings);
        $fixturesOfRun = AgentEvalFixtures::newInstance();
        foreach ($agentEvalFixtures->getElements() as $agentEvalFixture) {
            if (!$this->matchesSkillFilter($runSettings, $agentEvalFixture)) {
                continue;
            }
            $fixtureOfRun = $agentEvalFixture;
            $fixturesOfRun->add($fixtureOfRun);
            foreach ($agentEvalFixture->getCases()->getElements() as $agentEvalCase) {
                if (!$agentEvalSuitePlanOptions->matchesCaseFilter($agentEvalCase->getId())) {
                    continue;
                }
                $skipLine = $this->buildSkipLineOfCase($runSettings, $agentEvalCase, $this->findScopeIdOfCase($agentEvalCase, $agentEvalSuitePlanOptions));
                if ($skipLine !== null && !$runSettings->jsonOutput) {
                    $output->writeln($skipLine);
                }
            }
        }
        return $this->getSuitePlanService()->buildToolsTasks(static::SUITE_NAME, $fixturesOfRun, $agentEvalSuitePlanOptions);
    }

    /** Starts the child process of a task and returns it as a running case. */
    protected function startChildProcessOfTask(AgentEvalSuiteTask $agentEvalSuiteTask, InputInterface $input, AgentEvalToolsRunSettings $runSettings): AgentEvalSuiteRunningCase
    {
        $childProcess = $this->buildChildProcessOfTask($agentEvalSuiteTask, $input, $runSettings);
        $childProcess->start();
        return AgentEvalSuiteRunningCase::forStartedProcess($agentEvalSuiteTask, $childProcess, microtime(true), '');
    }

    /** The child process of a task, in the kernel project directory, ended at the task's ceiling. */
    protected function buildChildProcessOfTask(AgentEvalSuiteTask $agentEvalSuiteTask, InputInterface $input, AgentEvalToolsRunSettings $runSettings): Process
    {
        $childProcess = new Process($this->buildChildProcessArguments($agentEvalSuiteTask, $input, $runSettings), DDDService::instance()->getRootDir());
        $childProcess->setTimeout((float)$agentEvalSuiteTask->timeoutSeconds);
        return $childProcess;
    }

    /**
     * The command line of a task's child process: this command for the task's case and fixture with `--json` and
     * `--no-save` (the parent writes the case file), then the scope options as given, the model, the owner device, the
     * judged flavor with its models, the budget, the job-queue cases, the storing of the conversations and the arms.
     *
     * @return string[]
     */
    protected function buildChildProcessArguments(AgentEvalSuiteTask $agentEvalSuiteTask, InputInterface $input, AgentEvalToolsRunSettings $runSettings): array
    {
        $childArguments = $this->buildChildConsoleCommandLine($this->getChildCommandName());
        array_push($childArguments, '--case=' . $agentEvalSuiteTask->caseId, '--json', '--no-save', '--fixture=' . $agentEvalSuiteTask->fixturePath);
        array_push($childArguments, ...$this->buildScopeArgumentsForChild($input));
        if ($runSettings->modelName !== null && $runSettings->modelName !== '') {
            $childArguments[] = '--model=' . $runSettings->modelName;
        }
        if ($runSettings->ownerDeviceSurface !== '') {
            $childArguments[] = '--owner-device=' . $runSettings->ownerDeviceSurface;
        }
        if ($runSettings->isJudged()) {
            $childArguments[] = '--judge';
            if ($runSettings->judgeModelName !== null) {
                $childArguments[] = '--judge-model=' . $runSettings->judgeModelName;
            }
            if ($runSettings->driverModelName !== null) {
                $childArguments[] = '--driver-model=' . $runSettings->driverModelName;
            }
        }
        array_push($childArguments, ...$this->buildBudgetArgumentsForChild($input));
        if ($runSettings->includeAsyncOnlyCases) {
            $childArguments[] = '--include-async';
        }
        $childArguments[] = $runSettings->persistConversations ? '--persist-conversations' : '--transient';
        array_push($childArguments, ...static::buildEvalArmArguments($runSettings->evalArms));
        return $childArguments;
    }

    /**
     * The row of a running case whose child process ended, stamped with its repetition: the first case of the child's
     * result document, or a failed row when it printed none or ran into its ceiling (the process is then ended). Null
     * while the child still runs. The ceiling is checked only while the child runs: a child that ended before it is
     * polled keeps its result, however late the poll comes.
     */
    protected function findRowOfFinishedChild(AgentEvalSuiteRunningCase $agentEvalSuiteRunningCase): ?AgentEvalCaseRow
    {
        $agentEvalSuiteTask = $agentEvalSuiteRunningCase->task;
        try {
            if ($agentEvalSuiteRunningCase->process->isRunning()) {
                $agentEvalSuiteRunningCase->process->checkTimeout();
                return null;
            }
            $agentEvalCaseRow = $this->buildRowOfChildOutput($agentEvalSuiteTask->caseId, $agentEvalSuiteRunningCase->process->getOutput());
        } catch (ProcessTimedOutException) {
            $agentEvalCaseRow = AgentEvalCaseRow::buildForTimedOutChildProcess($agentEvalSuiteTask->caseId, $agentEvalSuiteTask->timeoutSeconds);
        }
        $agentEvalCaseRow->stampRepetition($agentEvalSuiteTask->repetition);
        return $agentEvalCaseRow;
    }

    /** The first case row of a child's printed result document (anything before its first `{` is skipped); a failed row without one. */
    protected function buildRowOfChildOutput(string $caseId, string $childOutput): AgentEvalCaseRow
    {
        $documentStart = strpos($childOutput, '{');
        $resultDocument = $documentStart !== false ? json_decode(substr($childOutput, $documentStart), true) : null;
        if (!is_array($resultDocument) || !is_array($resultDocument['cases'][0] ?? null)) {
            return AgentEvalCaseRow::buildForUnparseableChildOutput($caseId);
        }
        return AgentEvalCaseRow::fromArray($resultDocument['cases'][0]);
    }

    /** The line of a finished case run: `done` / `timed out`, `(seq)` for a case that writes, then `<caseId>[#<k>]`. */
    protected function buildFinishedChildLine(AgentEvalSuiteTask $agentEvalSuiteTask, AgentEvalCaseRow $agentEvalCaseRow): string
    {
        $runOfCase = $agentEvalSuiteTask->caseId . ($agentEvalSuiteTask->isRepetition() ? AgentEvalSuiteTask::REPETITION_SEPARATOR . $agentEvalSuiteTask->repetition : '');
        $writingNote = $agentEvalSuiteTask->access === AgentEvalSuiteTask::ACCESS_READ ? '' : ' (seq)';
        if ($agentEvalCaseRow->getError() === AgentEvalCaseRow::CHILD_PROCESS_TIMED_OUT_ERROR) {
            return "  <fg=red>timed out{$writingNote}</> $runOfCase";
        }
        return "  <fg=gray>done{$writingNote}</> $runOfCase";
    }

    // ------------------------------------------------------------------ the summary, the files, the record, the report

    /** The summary of the run in the tools format (the decision figures under the run's arms, `default` when it chose none). */
    protected function buildRunSummary(AgentEvalToolsRunSettings $runSettings, AgentEvalCaseRows $agentEvalCaseRows): AgentEvalRunSummary
    {
        return AgentEvalRunSummary::buildForToolsRun(
            $agentEvalCaseRows,
            $runSettings->modelName,
            $runSettings->flavor,
            $runSettings->evalArms,
            $runSettings->repeat,
            $runSettings->getPlanDynamicsArm(static::ARM_VALUE_DEFAULT),
            $runSettings->getSkillPreloadArm(static::ARM_VALUE_DEFAULT)
        );
    }

    /** The roll-up lines below the cases: the repetitions per case, the plan dynamics, the skill preload — each when measured. */
    protected function printRollUpLines(AgentEvalRunSummary $agentEvalRunSummary, OutputInterface $output): void
    {
        if ($agentEvalRunSummary->repetitionsByCase !== null) {
            $output->writeln(sprintf("\n<fg=cyan>Repetitions (%d per case):</>", $agentEvalRunSummary->repeat));
            foreach ($agentEvalRunSummary->repetitionsByCase->getElements() as $agentEvalCaseRepetition) {
                $output->writeln(sprintf(
                    '  %-56s %d/%d passed%s',
                    $agentEvalCaseRepetition->caseId,
                    $agentEvalCaseRepetition->passed,
                    $agentEvalCaseRepetition->runs,
                    $agentEvalCaseRepetition->errors > 0 ? sprintf(' · %d errors', $agentEvalCaseRepetition->errors) : ''
                ));
            }
        }
        $agentEvalPlanDynamicsRollUp = $agentEvalRunSummary->planDynamicsRollUp;
        if ($agentEvalPlanDynamicsRollUp !== null) {
            $output->writeln(sprintf(
                "\n<fg=cyan>Plan dynamics (arm %s): %d cases — plans on direct %d/%d, missing on required %d/%d, retired %d · %.2f plan calls, %.2f tool calls, %.1f s per case</>",
                $agentEvalPlanDynamicsRollUp->arm,
                $agentEvalPlanDynamicsRollUp->cases,
                $agentEvalPlanDynamicsRollUp->plansOnDirect,
                $agentEvalPlanDynamicsRollUp->directCases,
                $agentEvalPlanDynamicsRollUp->missingOnRequired,
                $agentEvalPlanDynamicsRollUp->requiredCases,
                $agentEvalPlanDynamicsRollUp->retiredPlans,
                $agentEvalPlanDynamicsRollUp->avgPlanCallsPerCase,
                $agentEvalPlanDynamicsRollUp->avgToolCallCount,
                $agentEvalPlanDynamicsRollUp->avgDurationMs / 1000
            ));
        }
        $agentEvalSkillPreloadRollUp = $agentEvalRunSummary->skillPreloadRollUp;
        if ($agentEvalSkillPreloadRollUp !== null) {
            $judgedTurns = $agentEvalSkillPreloadRollUp->judgedTurns;
            $output->writeln(sprintf(
                "\n<fg=cyan>Skill preload (arm %s): %d judged turns — %s hits %d (%.1f %%), wrong picks %d, skips %d, not asked %d · model first-load hits %d (%.1f %%)</>",
                $agentEvalSkillPreloadRollUp->arm,
                $judgedTurns,
                static::DECISION_MODEL_LABEL,
                $agentEvalSkillPreloadRollUp->hits,
                $judgedTurns > 0 ? 100 * $agentEvalSkillPreloadRollUp->hits / $judgedTurns : 0,
                $agentEvalSkillPreloadRollUp->wrongPicks,
                $agentEvalSkillPreloadRollUp->skips,
                $agentEvalSkillPreloadRollUp->notAsked,
                $agentEvalSkillPreloadRollUp->modelFirstLoadHits,
                $judgedTurns > 0 ? 100 * $agentEvalSkillPreloadRollUp->modelFirstLoadHits / $judgedTurns : 0
            ));
        }
    }

    /**
     * Writes the run: the result file a parent reads; unless `--no-save`, the summary (`_summary.json` in the run
     * folder of a run in child processes, else the loose run file) and the run's record. Returns what the report names
     * as saved (the run folder with a trailing slash, or the loose file); null when nothing was saved.
     */
    protected function writeRunFiles(AgentEvalToolsRunSettings $runSettings, AgentEvalRunSummary $agentEvalRunSummary): ?string
    {
        $agentEvalResultFilesService = $this->getResultFilesService();
        if ($runSettings->resultFile !== null) {
            $agentEvalResultFilesService->writeResultFile($runSettings->resultFile, $agentEvalRunSummary);
        }
        if ($runSettings->noSave) {
            return null;
        }
        $runWroteFolder = $runSettings->runsInChildProcesses();
        $summaryFile = $agentEvalResultFilesService->writeToolsRunSummary($runSettings->runName, $runWroteFolder, $agentEvalRunSummary);
        $savedPath = $runWroteFolder ? $agentEvalResultFilesService->getToolsRunDirectory($runSettings->runName) . '/' : $summaryFile;
        if ($runSettings->storesRecord()) {
            $this->getSuiteRunsService()->recordSuiteRun(
                (string)$this->getName(),
                $runSettings->scopeTag,
                $runSettings->startedAtTimestamp,
                $this->buildStoredRunOptions($runSettings),
                basename($runWroteFolder ? rtrim($savedPath, '/') : $summaryFile),
                $agentEvalRunSummary->caseRows
            );
        }
        return $savedPath;
    }

    /** The options the run's record stores: `model`, `flavor`, `arms`, `repeat`, `skill`, `case`. */
    protected function buildStoredRunOptions(AgentEvalToolsRunSettings $runSettings): AgentEvalStoredRunOptions
    {
        return AgentEvalStoredRunOptions::forSingleModelRun($runSettings->modelName, $runSettings->flavor, $runSettings->evalArms)
            ->addRepeat($runSettings->repeat)
            ->addSkillFilter($runSettings->skillFilter)
            ->addCaseFilter($runSettings->caseFilter);
    }

    /**
     * The report of the run: the pass rate (with the cases that did not apply), the model-under-test cost (the judge's
     * and the driver's calls run in a conversation of their own and are not in it), the performance and speed
     * figures, and where the run was saved.
     */
    protected function printRunReport(AgentEvalRunSummary $agentEvalRunSummary, ?string $savedPath, OutputInterface $output): void
    {
        $passRate = $agentEvalRunSummary->total > 0 ? round($agentEvalRunSummary->passed / $agentEvalRunSummary->total * 100, 1) : 0.0;
        $output->writeln('');
        $output->writeln(sprintf(
            'Overall: <info>%d/%d (%s%%)</info>%s',
            $agentEvalRunSummary->passed,
            $agentEvalRunSummary->total,
            $passRate,
            $agentEvalRunSummary->notApplicable > 0 ? $this->buildNotApplicableNote($agentEvalRunSummary->notApplicable) : ''
        ));
        $output->writeln(sprintf(
            'Model cost: <info>$%.4f</info> total · <info>$%.4f</info>/case · %s/pass · %d tok  <fg=gray>(%s — excl. judge/driver)</>',
            $agentEvalRunSummary->totalCostUsd,
            $agentEvalRunSummary->avgCostPerCaseUsd,
            $agentEvalRunSummary->costPerPassUsd !== null ? sprintf('$%.4f', $agentEvalRunSummary->costPerPassUsd) : 'n/a',
            $agentEvalRunSummary->totalTokens,
            (string)$agentEvalRunSummary->modelName
        ));
        $performanceRollUp = $agentEvalRunSummary->performanceRollUp;
        $output->writeln(sprintf(
            'Performance: <info>%.2f</info> model calls/case · <info>%.2f</info> owner turns/case · <info>%.1f s</info>/owner turn (median %.1f, p90 %.1f) · %.1f s agent time/case',
            $performanceRollUp->avgModelCallsPerCase,
            $performanceRollUp->avgOwnerTurnsPerCase,
            $performanceRollUp->avgSecondsPerOwnerTurn,
            $performanceRollUp->medianSecondsPerOwnerTurn,
            $performanceRollUp->p90SecondsPerOwnerTurn,
            $performanceRollUp->avgAgentSecondsPerCase
        ));
        foreach ($performanceRollUp->buildSpeedKpiLines() as $speedKpiLine) {
            $output->writeln(ucfirst($speedKpiLine));
        }
        if ($savedPath !== null) {
            $output->writeln(sprintf('Saved: <fg=gray>%s</>', $savedPath));
        }
    }

    /** The note of the overall line on the cases that did not apply, in the application's word for a space. */
    protected function buildNotApplicableNote(int $notApplicableCount): string
    {
        return sprintf(' · %d not applicable on their %s (not counted)', $notApplicableCount, AgentApplicationWording::current()->agentSpaceName);
    }

    /** ✓ / ✗ / ? for a flag of the verdict that may be missing. */
    protected function formatTriState(mixed $flagValue): string
    {
        if ($flagValue === null) {
            return '<fg=gray>?</>';
        }
        return $flagValue ? '<info>✓</info>' : '<error>✗</error>';
    }

    /** `$text`, cut to `$maxLength` characters with ` …` when longer. */
    protected function previewText(string $text, int $maxLength): string
    {
        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength) . ' …' : $text;
    }

    // ------------------------------------------------------------------ services (the application's classes when it replaces them)

    protected function getRunnerService(): AgentEvalRunnerService
    {
        /** @var AgentEvalRunnerService $agentEvalRunnerService */
        $agentEvalRunnerService = DDDService::instance()->getService(AgentEvalRunnerService::class);
        return $agentEvalRunnerService;
    }

    protected function getJudgedRunService(): AgentEvalJudgedRunService
    {
        /** @var AgentEvalJudgedRunService $agentEvalJudgedRunService */
        $agentEvalJudgedRunService = DDDService::instance()->getService(AgentEvalJudgedRunService::class);
        return $agentEvalJudgedRunService;
    }

    protected function getRunHooksService(): AgentEvalRunHooksService
    {
        /** @var AgentEvalRunHooksService $agentEvalRunHooksService */
        $agentEvalRunHooksService = DDDService::instance()->getService(AgentEvalRunHooksService::class);
        return $agentEvalRunHooksService;
    }

    protected function getResultFilesService(): AgentEvalResultFilesService
    {
        /** @var AgentEvalResultFilesService $agentEvalResultFilesService */
        $agentEvalResultFilesService = DDDService::instance()->getService(AgentEvalResultFilesService::class);
        return $agentEvalResultFilesService;
    }

    protected function getSuitePlanService(): AgentEvalSuitePlanService
    {
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        return $agentEvalSuitePlanService;
    }

    protected function getSuiteRunsService(): AgentEvalSuiteRunsService
    {
        /** @var AgentEvalSuiteRunsService $agentEvalSuiteRunsService */
        $agentEvalSuiteRunsService = DDDService::instance()->getService(AgentEvalSuiteRunsService::class);
        return $agentEvalSuiteRunsService;
    }

    protected function getCasePackagesService(): AgentEvalCasePackagesService
    {
        /** @var AgentEvalCasePackagesService $agentEvalCasePackagesService */
        $agentEvalCasePackagesService = DDDService::instance()->getService(AgentEvalCasePackagesService::class);
        return $agentEvalCasePackagesService;
    }
}
