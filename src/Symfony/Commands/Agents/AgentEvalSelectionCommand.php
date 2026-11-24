<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowHeader;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRunMeasurement;
use DDD\Domain\AI\Entities\Evals\AgentEvalCases;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Domain\AI\Entities\Evals\AgentEvalStoredRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalPreconditionNotEstablishedException;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalCaseScopeAndFixtureTrait;
use DDD\Symfony\Commands\AI\ModelTierOptionTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The live skill-selection evaluation: for each case of the fixture the agent answers the case's prompt, and the case
 * passes when one of the skills it expects (`expectedSkill`, `A|B` for either) was loaded at any step. Prints one line
 * per case and the selection accuracy; `--json` prints the run summary instead, `--result-file` writes it for a parent
 * process (a suite run), and a run without a result file is stored as a run of this command.
 *
 * Each case names its own agent space (`agentSpaceId`, or the scope key of the application); the scope options name the
 * space of the cases that name none. Nothing the agent would change reaches the space: every write tool is refused.
 * LIVE: real model and tool calls.
 *
 * Override points of an application: {@see getDefaultFixturePath()}, {@see findFallbackScopeValueOfRow()},
 * {@see describeMissingScope()}.
 *
 *   php bin/console app:agent:eval-selection [--agentSpaceId=…] [--fixture=…] [--case=…] [--json]
 */
#[AsCommand(
    name: 'app:agent:eval-selection',
    aliases: ['app:ai-agent:eval-selection'],
    description: 'Run the skill-selection eval set and report accuracy (live model calls).'
)]
class AgentEvalSelectionCommand extends AgentEvalConsoleCommand
{
    use ModelTierOptionTrait, AgentEvalArmOptionsTrait, AgentEvalCaseScopeAndFixtureTrait;

    /** @var string The name of the default fixture in the configured fixture paths (`AI.evalSuite.fixturePaths`) */
    public const string FIXTURE_PATH_NAME = 'selection';

    /** @var string The memory limit of a run */
    public const string MEMORY_LIMIT = '2G';

    /** @var string The flavor of every selection run */
    public const string FLAVOR = AgentEvalSuiteTask::FLAVOR_CALL;

    /** @var bool Whether the run prints the JSON document instead of the lines */
    protected bool $printsJson = false;

    /** @var string|null The model of the run; null = the conversation default */
    protected ?string $modelName = null;

    /** @var string[] The arms of the run: option name → value */
    protected array $evalArms = [];

    /** @var bool Whether the run stores its conversations */
    protected bool $persistsConversations = true;

    /** @var int|null The agent space the scope options name for the cases that name none */
    protected ?int $fallbackAgentSpaceId = null;

    protected function configure(): void
    {
        $this
            ->addOption('fixture', null, InputOption::VALUE_OPTIONAL, 'Absolute path to the eval fixture JSON (default: the configured selection fixture)')
            ->addOption('case', null, InputOption::VALUE_OPTIONAL, 'Only run the case with this id')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON results instead of the report')
            ->addOption('result-file', null, InputOption::VALUE_OPTIONAL, 'Also write the result JSON to this file (what a parent process reads instead of parsing the output).')
            ->addOption('transient', null, InputOption::VALUE_NONE, 'Do NOT persist the eval conversations: no decision site runs. Ignored when an arm option is passed.')
            ->addOption('persist-conversations', null, InputOption::VALUE_NONE, 'PERSIST the eval conversations (marked as eval runs). The decision layer runs only in a persisted conversation. Implied by every arm option.')
            ->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Override the agent AIModel by name; default = the conversation default, resolved through the AIModelsService AGENTIC scope');
        $this->addModelTierOptions();
        $this->addEvalArmOptions();
        $this->configureBudgetOption();
        $this->configureEvalScopeOptions();
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', static::MEMORY_LIMIT);
        $runStartedAtTimestamp = time();
        // this evaluation judges what the agent SELECTS: nothing it would change may reach the agent space
        MCPToolExecutorService::$everyWriteToolBlocked = true;
        $this->modelName = $this->resolveEffectiveModelName($input);
        $this->printsJson = (bool)$input->getOption('json');
        $caseFilter = $input->getOption('case');
        $caseFilter = is_string($caseFilter) && $caseFilter !== '' ? $caseFilter : null;
        $this->evalArms = $this->resolveEvalArmOptions($input);
        $this->persistsConversations = !(bool)$input->getOption('transient') || (bool)$input->getOption('persist-conversations') || $this->evalArms !== [];
        $this->fallbackAgentSpaceId = $this->resolveFallbackAgentSpaceId($input);
        $this->rememberFallbackAccountIdOfRun($input);

        $fixturePath = $this->resolveFixturePath($input);
        $agentEvalCases = $this->findCasesOfFixture($fixturePath);
        if ($agentEvalCases->count() === 0) {
            $output->writeln('<error>No eval cases found in fixture</error>');
            return Command::FAILURE;
        }
        $agentEvalCaseRows = $this->runCases($agentEvalCases, basename($fixturePath, '.json'), $caseFilter, $input, $output);
        if ($agentEvalCaseRows->count() === 0) {
            $output->writeln('<comment>No case matched.</comment>');
            return Command::INVALID;
        }
        $agentEvalRunSummary = AgentEvalRunSummary::buildForSuite($agentEvalCaseRows, $this->modelName, static::FLAVOR, $this->evalArms);
        $this->writeRunResult($input, $agentEvalRunSummary, $fixturePath, $runStartedAtTimestamp, $caseFilter);
        if ($this->printsJson) {
            $agentEvalResultFilesService = $this->getAgentEvalResultFilesService();
            $output->writeln($agentEvalResultFilesService->encodeJsonDocument($agentEvalRunSummary->toArray(true)));
            return Command::SUCCESS;
        }
        $this->printAccuracy($output, $agentEvalCaseRows->countPassed(), $agentEvalRunSummary->total);
        return Command::SUCCESS;
    }

    /**
     * Runs every case of the fixture that the case filter lets through and has a scope, in the order of the file.
     *
     * @param AgentEvalCases $agentEvalCases the cases of the fixture, in their order
     * @param string $fixtureName the file name of the fixture without `.json` (a case without an id is named after it)
     * @param string|null $caseFilter
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return AgentEvalCaseRows
     */
    protected function runCases(AgentEvalCases $agentEvalCases, string $fixtureName, ?string $caseFilter, InputInterface $input, OutputInterface $output): AgentEvalCaseRows
    {
        $agentEvalCaseRows = AgentEvalCaseRows::newInstance();
        foreach (array_values($agentEvalCases->getElements()) as $casePosition => $agentEvalCase) {
            // a case without an id is addressed by its position in the fixture
            $caseId = $agentEvalCase->hasKey('id') && $agentEvalCase->getApplicationValue('id') !== null ? $agentEvalCase->getId() : $fixtureName . '.' . $casePosition;
            if ($caseFilter !== null && $caseId !== $caseFilter) {
                continue;
            }
            $agentEvalCaseRow = $this->runCase($agentEvalCase, $caseId, $casePosition, $input, $output);
            if ($agentEvalCaseRow !== null) {
                $agentEvalCaseRows->add($agentEvalCaseRow);
            }
        }
        return $agentEvalCaseRows;
    }

    /**
     * Runs one case and prints its line: null for a case without a scope (skipped), the not-applicable row for a case
     * its agent space cannot serve, the error row when the run threw, else the hit or miss row.
     */
    protected function runCase(AgentEvalCase $agentEvalCase, string $caseId, int $casePosition, InputInterface $input, OutputInterface $output): ?AgentEvalCaseRow
    {
        $prompt = $agentEvalCase->getPrompt();
        $expectedSkills = (string)($agentEvalCase->getExpectedSkillName() ?? '');
        $caseScopeValue = $this->findScopeValueNamedByCase($agentEvalCase);
        if ($caseScopeValue === null && $this->fallbackAgentSpaceId === null && $this->fallbackAccountIdOfRun === null) {
            $this->printCaseLine($output, sprintf('  [%d] <error>SKIP</error> %s — %s', $casePosition, $this->describeMissingScope(), $prompt));
            return null;
        }
        $agentEvalRunOptions = $this->buildRunOptionsOfCase();
        $agentEvalRunnerService = $this->getAgentEvalRunnerService();
        $agentEvalNotApplicableReason = $agentEvalRunnerService->resolveScopeWithFallbackAccountAndFindNotApplicableReasonForCase(
            AgentEvalCase::fromArray(['expect' => ['anyOfSkills' => array_map('trim', explode('|', $expectedSkills))]] + $agentEvalCase->toArray()),
            $this->fallbackAgentSpaceId,
            $this->fallbackAccountIdOfRun,
            $agentEvalRunOptions
        );
        if ($agentEvalNotApplicableReason !== null) {
            $scopeValueOfRow = $caseScopeValue ?? $this->findFallbackScopeValueOfRow($input, $this->fallbackAgentSpaceId);
            $agentEvalCaseRowHeader = $this->buildRowHeader($expectedSkills, $caseId)->addFlavor(static::FLAVOR)->addScope($scopeValueOfRow);
            $this->printCaseLine($output, sprintf('  [%d] <comment>N/A </comment> %s', $casePosition, $agentEvalNotApplicableReason->getDetail()));
            return AgentEvalCaseRow::buildNotApplicableOfCase($agentEvalCaseRowHeader, $agentEvalNotApplicableReason);
        }
        $startedAtMicrotime = microtime(true);
        try {
            // the prompt only: no follow-ups, purpose or channel
            $promptOnlyCase = AgentEvalCase::fromArray($this->buildScopeFieldsOfCase($agentEvalCase) + ['prompt' => $prompt]);
            $agentEvalScope = $agentEvalRunnerService->resolveEvalScopeWithFallbackAccount($promptOnlyCase, $this->fallbackAgentSpaceId, $this->fallbackAccountIdOfRun);
            // a record the prompt names by reference is resolved in the scope; an unknown record makes the case not applicable
            $promptOnlyCase = $agentEvalRunnerService->resolveRecordReferencesOfCase($agentEvalScope, $promptOnlyCase);
            $conversation = $agentEvalRunnerService->run($agentEvalScope, $promptOnlyCase, $agentEvalRunOptions)->getConversation();
        } catch (AgentEvalPreconditionNotEstablishedException $preconditionNotEstablished) {
            $agentEvalNotApplicableReason = AgentEvalNotApplicableReason::buildPreconditionNotEstablishedReason($preconditionNotEstablished->getMessage());
            $this->printCaseLine($output, sprintf('  [%d] <comment>N/A </comment> %s', $casePosition, $agentEvalNotApplicableReason->getDetail()));
            return AgentEvalCaseRow::buildNotApplicableOfCase($this->buildRowHeader($expectedSkills, $caseId)->addFlavor(static::FLAVOR), $agentEvalNotApplicableReason);
        } catch (Throwable $throwable) {
            $this->printCaseLine($output, sprintf('  [%d] <error>ERROR</error> %s', $casePosition, $throwable->getMessage()));
            return AgentEvalCaseRow::buildFailedOfCase($this->buildRowHeader($expectedSkills, $caseId), null, $throwable->getMessage());
        }
        $agentEvalCaseRunMeasurement = AgentEvalCaseRunMeasurement::forConversation($conversation, AgentEvalCaseRunMeasurement::measureDurationMsSince($startedAtMicrotime));
        $agentEvalCaseRow = AgentEvalCaseRow::buildForSelectionRun($this->buildRowHeader($expectedSkills, $caseId), $expectedSkills, $agentEvalCaseRunMeasurement, $agentEvalCase->getForbiddenSkillNames());
        $this->printCaseLine($output, sprintf(
            '  [%d] %s expected=%s loaded=[%s] — %s',
            $casePosition,
            $agentEvalCaseRow->isPassed() ? '<info>HIT </info>' : '<comment>MISS</comment>',
            $expectedSkills,
            implode(', ', (array)$agentEvalCaseRow->getValue('loadedSkills')),
            $prompt
        ));
        return $agentEvalCaseRow;
    }

    /** One options object per case: the run's arms, persistence and model, every write tool refused. */
    protected function buildRunOptionsOfCase(): AgentEvalRunOptions
    {
        $agentEvalRunOptions = AgentEvalRunOptions::forArms($this->buildEvalArms($this->evalArms));
        $agentEvalRunOptions->persistConversations = $this->persistsConversations;
        $agentEvalRunOptions->aiModelName = $this->modelName;
        $agentEvalRunOptions->everyWriteToolBlocked = true;
        $agentEvalRunOptions->progressReporter = $this->getProgressReporter();
        return $agentEvalRunOptions;
    }

    /** The head of a selection row: `skill` (the expected skills as written), `case`. */
    protected function buildRowHeader(string $expectedSkills, string $caseId): AgentEvalCaseRowHeader
    {
        return AgentEvalCaseRowHeader::withoutFields()->addField('skill', $expectedSkills)->addField('case', $caseId);
    }

    /** Prints a line of a case, unless the run prints its JSON document. */
    protected function printCaseLine(OutputInterface $output, string $caseLine): void
    {
        if (!$this->printsJson) {
            $output->writeln($caseLine);
        }
    }

    /**
     * Writes the run summary to the result file a parent reads; without one, stores the run (best effort, silent): a
     * case started by a suite run leaves its record to the suite, which records the whole run.
     */
    protected function writeRunResult(InputInterface $input, AgentEvalRunSummary $agentEvalRunSummary, string $fixturePath, int $runStartedAtTimestamp, ?string $caseFilter): void
    {
        $resultFile = $input->getOption('result-file');
        if (is_string($resultFile) && $resultFile !== '') {
            $agentEvalResultFilesService = $this->getAgentEvalResultFilesService();
            $agentEvalResultFilesService->writeResultFile($resultFile, $agentEvalRunSummary);
            return;
        }
        $agentEvalSuiteRunsService = $this->getAgentEvalSuiteRunsService();
        $agentEvalSuiteRunsService->recordSuiteRun(
            (string)$this->getName(),
            basename($fixturePath, '.json'),
            $runStartedAtTimestamp,
            AgentEvalStoredRunOptions::forSingleModelRun($this->modelName, static::FLAVOR, $this->evalArms)->addCaseFilter($caseFilter),
            null,
            $agentEvalRunSummary->caseRows
        );
    }

    /** Prints `Selection accuracy: h/t (x%)` after an empty line. */
    protected function printAccuracy(OutputInterface $output, int $hitCount, int $caseCount): void
    {
        $accuracy = $caseCount > 0 ? round($hitCount / $caseCount * 100, 1) : 0.0;
        $output->writeln('');
        $output->writeln(sprintf('Selection accuracy: <info>%d/%d (%s%%)</info>', $hitCount, $caseCount, $accuracy));
    }

    /** The fixture of the run: `--fixture`, else {@see getDefaultFixturePath()}; empty when neither names one. */
    protected function resolveFixturePath(InputInterface $input): string
    {
        $fixtureOption = $input->getOption('fixture');
        if (is_string($fixtureOption) && $fixtureOption !== '') {
            return $fixtureOption;
        }
        return (string)$this->getDefaultFixturePath();
    }

    /**
     * Every JSON object of the fixture's `cases` list, in the order of the file, an entry without an id included (it
     * is named after its position); none for a missing or unreadable file.
     */
    protected function findCasesOfFixture(string $fixturePath): AgentEvalCases
    {
        $agentEvalCases = AgentEvalCases::newInstance();
        $agentEvalSuitePlanService = $this->getAgentEvalSuitePlanService();
        $agentEvalFixture = $fixturePath !== '' ? $agentEvalSuitePlanService->findFixtureAt($fixturePath) : null;
        $caseEntries = $agentEvalFixture?->getApplicationValue('cases');
        foreach (is_array($caseEntries) ? $caseEntries : [] as $caseFields) {
            if (is_array($caseFields)) {
                $agentEvalCase = AgentEvalCase::fromArray($caseFields);
                $agentEvalCases->add($agentEvalCase);
            }
        }
        return $agentEvalCases;
    }

    /**
     * Override point: the fixture of a run without `--fixture`. The default: the configured path named
     * {@see FIXTURE_PATH_NAME} (`AI.evalSuite.fixturePaths`); null when none is configured.
     */
    protected function getDefaultFixturePath(): ?string
    {
        $agentEvalSuitePlanService = $this->getAgentEvalSuitePlanService();
        return $agentEvalSuitePlanService->findFixturePath(static::FIXTURE_PATH_NAME);
    }

    /**
     * Override point: the scope value the row of a case that names none carries (its not-applicable row). The default:
     * the agent space of the scope options. An application whose rows name another scope value (the id of what the
     * space stands for) reads it from its own option.
     */
    protected function findFallbackScopeValueOfRow(InputInterface $input, ?int $fallbackAgentSpaceId): ?int
    {
        return $fallbackAgentSpaceId;
    }

    /** Override point: the words of the line of a case skipped because neither it nor the scope options name a scope. */
    protected function describeMissingScope(): string
    {
        return 'no agentSpaceId (case or --agentSpaceId fallback)';
    }

    /** The stored runs (the application's class, when it replaces it). */
    protected function getAgentEvalSuiteRunsService(): AgentEvalSuiteRunsService
    {
        /** @var AgentEvalSuiteRunsService $agentEvalSuiteRunsService */
        $agentEvalSuiteRunsService = DDDService::instance()->getService(AgentEvalSuiteRunsService::class);
        return $agentEvalSuiteRunsService;
    }
}
