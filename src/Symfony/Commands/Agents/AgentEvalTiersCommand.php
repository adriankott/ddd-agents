<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRunMeasurement;
use DDD\Domain\AI\Entities\Evals\AgentEvalCases;
use DDD\Domain\AI\Entities\Evals\AgentEvalExpectation;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalTiersResult;
use DDD\Domain\AI\Entities\Evals\AgentEvalTiersResults;
use DDD\Domain\AI\Entities\Models\Selection\ModelSelectionContext;
use DDD\Domain\AI\Entities\Models\Selection\ModelTier;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalCaseScopeAndFixtureTrait;
use DDD\Symfony\Commands\AI\ModelTierOptionTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The tool evaluation per model tier: every case of the tool fixtures runs under the model each tier selects through
 * the model catalog (`--tiers`, default every tier; `--vendor` a soft preference), and each tier reports its pass rate
 * and its efficiency (tool calls, failed tool calls, tokens, seconds per case — lower is better), then one summary
 * table. It tells whether a cheaper tier is good enough for the agent loop.
 *
 * A case runs only with the prompt, the scope, the restore block and the tool block of the fixture case (no
 * follow-ups, purpose, channel, step cap or simulated errors). A case that names no scope runs on the space the scope
 * options name, and is left out without one. LIVE: every case under every tier.
 *
 * Override point of an application: {@see getDefaultFixturePaths()}.
 *
 *   php bin/console app:agent:eval-tiers [--tiers=CHEAP,STANDARD,PREMIUM] [--vendor=…] [--agentSpaceId=…] [--full]
 */
#[AsCommand(
    name: 'app:agent:eval-tiers',
    aliases: ['app:ai-agent:eval-tiers'],
    description: 'Run the tool-eval suite per AIModelsService tier (CHEAP/STANDARD/PREMIUM) and report success rate (live).'
)]
class AgentEvalTiersCommand extends AgentEvalConsoleCommand
{
    use ModelTierOptionTrait, AgentEvalCaseScopeAndFixtureTrait;

    /** @var string The name of the default fixtures in the configured fixture paths (`AI.evalSuite.fixturePaths`): the tool fixtures */
    public const string FIXTURE_PATH_NAME = 'tools';

    /** @var string The memory limit of a run (heavy aggregate tools exceed a console default) */
    public const string MEMORY_LIMIT = '2G';

    /** @var string[] The keys of a fixture case a tier run keeps beside the scope keys */
    public const array RUN_CASE_FIELD_NAMES = ['prompt', 'restore', 'blockedTools', 'allowExternalWrites'];

    protected function configure(): void
    {
        $this
            ->addOption('tiers', null, InputOption::VALUE_OPTIONAL, 'Comma-separated tiers to test', implode(',', ModelTier::getAll()))
            ->addOption('vendor', null, InputOption::VALUE_OPTIONAL, 'Soft vendor preference for tier resolution (AIModel::VENDOR_*)')
            ->addOption('fixture', null, InputOption::VALUE_OPTIONAL, 'A single fixture JSON path (default: all tool fixtures)')
            ->addOption('full', null, InputOption::VALUE_NONE, 'List each failing case per tier');
        $this->configureEvalScopeOptions();
    }

    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', static::MEMORY_LIMIT);
        $tiers = array_values(array_filter(array_map('trim', explode(',', (string)$input->getOption('tiers')))));
        $vendor = $input->getOption('vendor');
        $vendor = is_string($vendor) && $vendor !== '' ? $vendor : null;
        $fallbackAgentSpaceId = $this->resolveFallbackAgentSpaceId($input);
        $this->rememberFallbackAccountIdOfRun($input);

        $agentEvalCases = $this->findCasesOfFixtures($this->resolveFixturePaths($input->getOption('fixture')), $fallbackAgentSpaceId);
        if ($agentEvalCases->count() === 0) {
            $output->writeln('<error>No tool-eval cases found</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('<info>Per-tier tool-eval — %d case(s) × %d tier(s) — LIVE</info>', $agentEvalCases->count(), count($tiers)));

        $agentEvalTiersResults = AgentEvalTiersResults::newInstance();
        foreach ($tiers as $tier) {
            $agentEvalTiersResult = $this->runTier($tier, $vendor, $agentEvalCases, $fallbackAgentSpaceId, $output);
            if ($agentEvalTiersResult === null) {
                continue;
            }
            $output->writeln($agentEvalTiersResult->buildResultLine());
            if ((bool)$input->getOption('full')) {
                foreach ($agentEvalTiersResult->getDistinctFailureNotes() as $failureNote) {
                    $output->writeln('    <error>FAIL</error> ' . $failureNote);
                }
            }
            $agentEvalTiersResults->add($agentEvalTiersResult);
        }
        $this->printSummary($output, $agentEvalTiersResults);
        return Command::SUCCESS;
    }

    /**
     * Selects the tier's model and runs every case under it; null (with its line) for an unknown tier and for a tier
     * without an eligible model.
     */
    protected function runTier(string $tier, ?string $vendor, AgentEvalCases $agentEvalCases, ?int $fallbackAgentSpaceId, OutputInterface $output): ?AgentEvalTiersResult
    {
        if (!ModelTier::isValid($tier)) {
            $output->writeln("<error>Unknown tier '$tier' — skipped</error>");
            return null;
        }
        try {
            $aIModelsService = $this->getAIModelsService();
            $modelName = $aIModelsService->selectModel(new ModelSelectionContext($this->createTierSelectionPolicy($tier, $vendor)))?->name;
        } catch (BadRequestException $badRequestException) {
            $output->writeln(sprintf('<error>%-10s unresolved: %s</error>', $tier, $badRequestException->getMessage()));
            return null;
        }
        $agentEvalTiersResult = AgentEvalTiersResult::forTier($tier, $modelName);
        $output->writeln(sprintf("\n<info>── %s → %s ──</info>", $tier, $agentEvalTiersResult->getModelLabel()));
        foreach ($agentEvalCases->getElements() as $agentEvalCase) {
            $this->runCaseUnderTier($agentEvalCase, $agentEvalTiersResult, $fallbackAgentSpaceId);
        }
        return $agentEvalTiersResult;
    }

    /**
     * Runs one case on the tier's model and counts it: a pass by the case's expectation; a run that threw counts as a
     * failure with its error and is not measured. The runner applies the case's tool block (the external-profile
     * writes included) and lifts it after the run.
     */
    protected function runCaseUnderTier(AgentEvalCase $agentEvalCase, AgentEvalTiersResult $agentEvalTiersResult, ?int $fallbackAgentSpaceId): void
    {
        $caseId = (string)($agentEvalCase->getApplicationValue('id') ?? '');
        try {
            $startedAtMicrotime = microtime(true);
            $runCase = $this->buildRunCaseOfFixtureCase($agentEvalCase);
            $agentEvalRunOptions = AgentEvalRunOptions::forArms();
            $agentEvalRunOptions->aiModelName = $agentEvalTiersResult->modelName;
            $agentEvalRunOptions->progressReporter = $this->getProgressReporter();
            $agentEvalRunnerService = $this->getAgentEvalRunnerService();
            $conversation = $agentEvalRunnerService->runCase($agentEvalRunnerService->resolveEvalScopeWithFallbackAccount($runCase, $fallbackAgentSpaceId, $this->fallbackAccountIdOfRun), $runCase, $agentEvalRunOptions)->getConversation();
            $durationMs = AgentEvalCaseRunMeasurement::measureDurationMsSince($startedAtMicrotime);
            // the conversation with its delegated conversations
            $messagesOfTree = $conversation->getAllMessagesIncludingDelegations();
            $toolCallHistory = $messagesOfTree->buildToolCallHistory();
            $agentEvalTiersResult->addMeasuredRun(
                $toolCallHistory->countToolCalls(),
                count(array_filter(
                    $toolCallHistory->getToolCallRows(),
                    static fn (AIConversationMessage $toolCallMessage): bool => $toolCallMessage->status === AIConversationMessage::STATUS_FAILED
                )),
                $messagesOfTree->getTotalTokens(),
                $durationMs
            );
            $passed = AgentEvalExpectation::fromExpectBlock($agentEvalCase->getExpect())->buildEvalResult($conversation)->passed;
        } catch (Throwable $throwable) {
            $agentEvalTiersResult->countFailedCase($caseId . ' (error: ' . $throwable->getMessage() . ')');
            return;
        }
        if ($passed) {
            $agentEvalTiersResult->countPassedCase();
            return;
        }
        $agentEvalTiersResult->countFailedCase($caseId);
    }

    /** The case a tier runs: the scope keys, the prompt, the restore block and the tool block of the fixture case. */
    protected function buildRunCaseOfFixtureCase(AgentEvalCase $agentEvalCase): AgentEvalCase
    {
        $runCaseFieldNames = array_merge($this->getCaseScopeFieldNames(), static::RUN_CASE_FIELD_NAMES);
        return AgentEvalCase::fromArray(array_intersect_key($agentEvalCase->toArray(), array_flip($runCaseFieldNames)));
    }

    /** Prints the summary table: its header, then one row per tier that ran. */
    protected function printSummary(OutputInterface $output, AgentEvalTiersResults $agentEvalTiersResults): void
    {
        $output->writeln("\n<info>════ Summary (efficiency = lower is better) ════</info>");
        $output->writeln($agentEvalTiersResults->buildSummaryHeaderLine());
        foreach ($agentEvalTiersResults->getElements() as $agentEvalTiersResult) {
            $output->writeln($agentEvalTiersResult->buildSummaryLine());
        }
    }

    /**
     * The fixture files of the run: `--fixture` (none when the file is missing), else {@see getDefaultFixturePaths()}.
     *
     * @return string[]
     */
    protected function resolveFixturePaths(mixed $fixtureOption): array
    {
        if (is_string($fixtureOption) && $fixtureOption !== '') {
            return is_file($fixtureOption) ? [$fixtureOption] : [];
        }
        return $this->getDefaultFixturePaths();
    }

    /**
     * The cases of the fixture files: every JSON object of a fixture's `cases` with a `prompt` and a scope (its own,
     * else the space of the scope options), in the order of the files.
     *
     * @param string[] $fixturePaths
     */
    protected function findCasesOfFixtures(array $fixturePaths, ?int $fallbackAgentSpaceId): AgentEvalCases
    {
        $agentEvalCases = AgentEvalCases::newInstance();
        $agentEvalSuitePlanService = $this->getAgentEvalSuitePlanService();
        foreach ($fixturePaths as $fixturePath) {
            $caseEntries = $agentEvalSuitePlanService->findFixtureAt($fixturePath)?->getApplicationValue('cases');
            foreach (is_array($caseEntries) ? $caseEntries : [] as $caseFields) {
                if (!is_array($caseFields) || !isset($caseFields['prompt'])) {
                    continue;
                }
                $agentEvalCase = AgentEvalCase::fromArray($caseFields);
                if ($this->findScopeValueNamedByCase($agentEvalCase) === null && $fallbackAgentSpaceId === null && $this->fallbackAccountIdOfRun === null) {
                    continue;
                }
                $agentEvalCases->add($agentEvalCase);
            }
        }
        return $agentEvalCases;
    }

    /**
     * Override point: the fixture files of a run without `--fixture`. The default: the files of the configured path
     * named {@see FIXTURE_PATH_NAME} (`AI.evalSuite.fixturePaths`); none when none is configured.
     *
     * @return string[]
     */
    protected function getDefaultFixturePaths(): array
    {
        $agentEvalSuitePlanService = $this->getAgentEvalSuitePlanService();
        $fixturePath = $agentEvalSuitePlanService->findFixturePath(static::FIXTURE_PATH_NAME);
        return $fixturePath !== null ? $agentEvalSuitePlanService->findFixtureFilesAt($fixturePath) : [];
    }
}
