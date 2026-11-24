<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionFixture;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTrajectory;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurn;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurns;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\AI\ModelTierOptionTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The live eviction evaluation, the real-data counterpart of `app:agent:context-eviction-sim`. Each trajectory of
 * the fixture is a list of varied multi-skill prompts driven through the real agent loop on ONE continuing
 * conversation ({@see AgentEvalRunnerService::runEvictionTrajectory()}): the loaded-capability set grows turn by turn,
 * the eviction sweep runs on the real telemetry, and after every turn the snapshot of the loaded capabilities and what
 * was loaded and evicted are taken ({@see AgentEvalEvictionTurns}). The command prints per trajectory the load curve
 * (skill and tool tokens against the soft target and the hard ceiling, the turn's calls, hops, refused reads, cache
 * share and seconds) and whether eviction fired, and at the end in how many trajectories it fired.
 *
 * The fixture's settings (eager loading, low thresholds; {@see AgentEvalEvictionFixture}) make a short trajectory
 * trigger eviction; `--defaults` runs on the platform's defaults instead, and the context-retention options override
 * either. The conversation is transient. The fixture is `--fixture`, else the configured fixture path `eviction`
 * (`AI.evalSuite.fixturePaths`). A trajectory runs in the agent space it names, else in the one the scope options
 * name ({@see AgentEvalConsoleCommand::resolveFallbackAgentSpaceId()}); an application whose trajectories name their
 * scope by a key of its own overrides {@see trajectoryNamesItsScope()} and {@see getScopeWordOfSkipLine()}.
 *
 * Live: real model and tool calls.
 *
 *   php bin/console app:agent:eval-eviction --agentSpaceId=12 --ignore-budget
 *   php bin/console app:agent:eval-eviction --agentSpaceId=12 --defaults --unload-granularity=tool
 */
#[AsCommand(
    name: 'app:agent:eval-eviction',
    aliases: ['app:ai-agent:eval-eviction'],
    description: 'Drive multi-skill trajectories live and observe capability eviction on real data (load curve + deltas).',
)]
class AgentEvalEvictionCommand extends AgentEvalConsoleCommand
{
    use ModelTierOptionTrait, ContextRetentionSettingsOptionsTrait;

    /** @var string The name of the configured fixture path of this command (`AI.evalSuite.fixturePaths`) */
    public const string FIXTURE_PATH_NAME = 'eviction';

    /** @var string[] The headers of the load-curve table of a trajectory */
    public const array TURN_TABLE_HEADERS = ['turn', 'prompt', 'skillTok', 'toolTok', 'Σ total', 'calls', 'load_tool', 'refused', 'cached %', 'sec', '✨ loaded', '🗑 evicted'];

    /** @var int The longest prompt a row of the load-curve table shows */
    public const int PROMPT_COLUMN_MAX_LENGTH = 36;

    protected function configure(): void
    {
        $this
            ->addOption('fixture', null, InputOption::VALUE_OPTIONAL, 'Path of the eviction trajectory fixture JSON (default: the configured fixture path `' . self::FIXTURE_PATH_NAME . '`)')
            ->addOption('trajectory', null, InputOption::VALUE_OPTIONAL, 'Run only the trajectory with this name (default: all in the fixture)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Also print the full per-turn capability snapshot JSON (every capability + its cost-benefit telemetry)');
        $this->configureBudgetOption();
        $this
            ->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Override the agent AIModel by name; default = the AGENTIC-scope conversation default')
            ->addOption('defaults', null, InputOption::VALUE_NONE, 'Ignore the fixture\'s stress thresholds and run on the platform defaults (eager, every threshold at its default) — the A/B measurement mode; combine with --unload-granularity=skill|tool');
        $this->configureEvalScopeOptions();
        $this->addModelTierOptions();
        $this->addContextRetentionSettingsOptions();
    }

    /**
     * Reads the fixture and the settings, runs every trajectory (or the one `--trajectory` names) and prints the
     * verdict line.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int `FAILURE` when the fixture lists no trajectory or none ran
     * @throws Throwable when the model options or the scope options are refused
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $modelName = $this->resolveEffectiveModelName($input);
        $printsSnapshots = (bool)$input->getOption('json');
        $agentEvalEvictionFixture = AgentEvalEvictionFixture::fromFile($this->findFixturePath($input));
        if (!$agentEvalEvictionFixture->listsTrajectories()) {
            $output->writeln('<error>No trajectories found in fixture</error>');
            return Command::FAILURE;
        }
        $baseSettings = (bool)$input->getOption('defaults')
            ? $this->buildPlatformDefaultSettings()
            : $agentEvalEvictionFixture->buildRetentionSettings();
        $contextRetentionSettingsOverride = $this->buildContextRetentionSettingsOverrideFromInput($input);
        $contextRetentionSettings = $contextRetentionSettingsOverride?->applyTo($baseSettings) ?? $baseSettings;
        $this->printSettings($contextRetentionSettings, $output);

        $fallbackAgentSpaceId = $this->resolveFallbackAgentSpaceId($input);
        $this->rememberFallbackAccountIdOfRun($input);
        $onlyTrajectoryName = $input->getOption('trajectory');
        $onlyTrajectoryName = $onlyTrajectoryName !== null ? (string)$onlyTrajectoryName : null;
        $trajectoriesRun = 0;
        $trajectoriesWithEviction = 0;
        foreach ($agentEvalEvictionFixture->getTrajectories()->getElements() as $agentEvalEvictionTrajectory) {
            if ($onlyTrajectoryName !== null && $agentEvalEvictionTrajectory->name !== $onlyTrajectoryName) {
                continue;
            }
            $agentEvalEvictionTurns = $this->runTrajectory($agentEvalEvictionTrajectory, $contextRetentionSettings, $modelName, $fallbackAgentSpaceId, $output);
            if ($agentEvalEvictionTurns === null) {
                continue;
            }
            $trajectoriesRun++;
            $trajectoriesWithEviction += $this->renderTrajectory($agentEvalEvictionTurns, $contextRetentionSettings, $output, $printsSnapshots) ? 1 : 0;
        }

        if ($trajectoriesRun === 0) {
            $output->writeln('<error>No trajectory ran</error>');
            return Command::FAILURE;
        }
        $output->writeln('');
        $output->writeln(sprintf('Eviction observed in <info>%d/%d</info> trajectories.', $trajectoriesWithEviction, $trajectoriesRun));
        return Command::SUCCESS;
    }

    /**
     * Runs one trajectory and returns its owner turns; null when it was skipped (no scope, no prompts) or failed (the
     * error line is printed).
     *
     * @param AgentEvalEvictionTrajectory $agentEvalEvictionTrajectory
     * @param ContextRetentionSettings $contextRetentionSettings
     * @param string|null $modelName
     * @param int|null $fallbackAgentSpaceId
     * @param OutputInterface $output
     * @return AgentEvalEvictionTurns|null
     */
    protected function runTrajectory(
        AgentEvalEvictionTrajectory $agentEvalEvictionTrajectory,
        ContextRetentionSettings $contextRetentionSettings,
        ?string $modelName,
        ?int $fallbackAgentSpaceId,
        OutputInterface $output
    ): ?AgentEvalEvictionTurns {
        $scopeCase = $agentEvalEvictionTrajectory->buildScopeCase();
        if ((!$this->trajectoryNamesItsScope($scopeCase) && $fallbackAgentSpaceId === null && $this->fallbackAccountIdOfRun === null) || !$agentEvalEvictionTrajectory->hasPrompts()) {
            $output->writeln(sprintf('<comment>SKIP</comment> %s — no %s / no prompts', $agentEvalEvictionTrajectory->name, $this->getScopeWordOfSkipLine()));
            return null;
        }
        $output->writeln('');
        $output->writeln('<info>══ trajectory: ' . $agentEvalEvictionTrajectory->name . ' ══</info>');
        try {
            $agentEvalRunnerService = $this->getAgentEvalRunnerService();
            $agentEvalRunOptions = AgentEvalRunOptions::forArms();
            $agentEvalRunOptions->aiModelName = $modelName;
            $agentEvalRunOutcome = $agentEvalRunnerService->runEvictionTrajectory(
                $agentEvalRunnerService->resolveEvalScopeWithFallbackAccount($scopeCase, $fallbackAgentSpaceId, $this->fallbackAccountIdOfRun),
                $agentEvalEvictionTrajectory->prompts,
                $contextRetentionSettings,
                $agentEvalRunOptions
            );
        } catch (Throwable $trajectoryError) {
            $output->writeln(sprintf('  <error>ERROR</error> %s', $trajectoryError->getMessage()));
            return null;
        }
        return $agentEvalRunOutcome->getEvictionTurnSet();
    }

    /**
     * Whether a trajectory names the scope it runs in itself. The default: it names an agent space (`agentSpaceId`).
     * An application whose trajectories name their scope by a key of its own adds that key.
     *
     * @param AgentEvalCase $scopeCase the trajectory as a case ({@see AgentEvalEvictionTrajectory::buildScopeCase()})
     * @return bool
     */
    protected function trajectoryNamesItsScope(AgentEvalCase $scopeCase): bool
    {
        return $scopeCase->getAgentSpaceId() !== null;
    }

    /**
     * The word of the scope in the line of a skipped trajectory (`SKIP <name> — no <word> / no prompts`). The
     * default: `agentSpaceId`.
     *
     * @return string
     */
    protected function getScopeWordOfSkipLine(): string
    {
        return self::OPTION_AGENT_SPACE_ID;
    }

    /**
     * Prints the load curve of one trajectory, its sums and its peak line, and with `$printsSnapshots` the snapshot
     * JSON of every turn. Returns whether a capability was evicted in any turn.
     *
     * @param AgentEvalEvictionTurns $agentEvalEvictionTurns
     * @param ContextRetentionSettings $contextRetentionSettings
     * @param OutputInterface $output
     * @param bool $printsSnapshots
     * @return bool
     */
    protected function renderTrajectory(AgentEvalEvictionTurns $agentEvalEvictionTurns, ContextRetentionSettings $contextRetentionSettings, OutputInterface $output, bool $printsSnapshots): bool
    {
        $hardCapTokens = $contextRetentionSettings->hardCapTokens ?? LoadedCapability::DEFAULT_HARD_CAP_TOKENS;
        $softTargetTokens = $contextRetentionSettings->pressureBudgetTokens ?? LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS;
        $anyEviction = $agentEvalEvictionTurns->hasEviction();

        $table = new Table($output);
        $table->setHeaders(static::TURN_TABLE_HEADERS);
        $tableRows = [];
        foreach ($agentEvalEvictionTurns->getElements() as $agentEvalEvictionTurn) {
            $tableRows[] = $this->buildTurnTableRow($agentEvalEvictionTurn, $hardCapTokens);
        }
        $table->setRows($tableRows);
        $table->render();

        $totalFigures = $agentEvalEvictionTurns->buildTotalFigures();
        $output->writeln(sprintf(
            '  Σ calls %d · load_tool hops %d · refused reads %d · cache hit %d %% · %.1f s',
            $totalFigures->modelCalls,
            $totalFigures->loadToolHops,
            $totalFigures->refusedReads,
            $totalFigures->inputTokens > 0 ? (int)round(100 * $totalFigures->cachedInputTokens / $totalFigures->inputTokens) : 0,
            $totalFigures->seconds
        ));
        $output->writeln(sprintf(
            '  peak carried = %d tok · soft = %d · hard = %d · %s',
            $agentEvalEvictionTurns->getPeakTotalResidentTokens(),
            $softTargetTokens,
            $hardCapTokens,
            $anyEviction ? '<fg=green>eviction fired ✓</>' : '<fg=yellow>no eviction (raise --eager / lower --evict-* to trigger)</>'
        ));

        if ($printsSnapshots) {
            foreach ($agentEvalEvictionTurns->getElements() as $agentEvalEvictionTurn) {
                $output->writeln('<fg=gray>' . (json_encode($agentEvalEvictionTurn->snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . '</>');
            }
        }
        return $anyEviction;
    }

    /**
     * The row of one turn in the load-curve table.
     *
     * @param AgentEvalEvictionTurn $agentEvalEvictionTurn
     * @param int $hardCapTokens
     * @return string[]
     */
    protected function buildTurnTableRow(AgentEvalEvictionTurn $agentEvalEvictionTurn, int $hardCapTokens): array
    {
        $snapshot = $agentEvalEvictionTurn->snapshot;
        $figures = $agentEvalEvictionTurn->figures;
        $totalResidentTokens = $snapshot->totalResidentTokens;
        return [
            (string)$snapshot->turn,
            $this->truncate($agentEvalEvictionTurn->prompt, static::PROMPT_COLUMN_MAX_LENGTH),
            (string)$snapshot->skillTokens,
            (string)$snapshot->toolTokens,
            $totalResidentTokens > $hardCapTokens ? '<fg=yellow>' . $totalResidentTokens . ' OVER</>' : (string)$totalResidentTokens,
            (string)$figures->modelCalls,
            $figures->loadToolHops > 0 ? '<fg=yellow>' . $figures->loadToolHops . '</>' : '0',
            $figures->refusedReads > 0 ? '<fg=yellow>' . $figures->refusedReads . '</>' : '0',
            $figures->inputTokens > 0 ? (string)(int)round(100 * $figures->cachedInputTokens / $figures->inputTokens) : '-',
            (string)$figures->seconds,
            implode(', ', $agentEvalEvictionTurn->newlyLoadedCapabilityNames),
            $agentEvalEvictionTurn->hasEvicted() ? '<fg=red>' . implode(', ', $agentEvalEvictionTurn->evictedCapabilityNames) . '</>' : '',
        ];
    }

    /**
     * Prints the header line of the settings every trajectory runs on.
     *
     * @param ContextRetentionSettings $contextRetentionSettings
     * @param OutputInterface $output
     * @return void
     */
    protected function printSettings(ContextRetentionSettings $contextRetentionSettings, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '<info>Eviction eval</info> — unloading=%s · loading=%s · soft=%d · hard=%d · ref=%d · tool=%d/%dt · skill=%d/%dt · minRes=%d',
            $contextRetentionSettings->getUnloadGranularity(),
            ($contextRetentionSettings->loadToolsOnSkillLoad ?? false) ? 'EAGER' : 'LAZY',
            $contextRetentionSettings->pressureBudgetTokens ?? LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS,
            $contextRetentionSettings->hardCapTokens ?? LoadedCapability::DEFAULT_HARD_CAP_TOKENS,
            $contextRetentionSettings->referenceResidentTokens ?? LoadedCapability::DEFAULT_REFERENCE_RESIDENT_TOKENS,
            $contextRetentionSettings->unloadToolAfterUnusedTokens ?? LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS,
            $contextRetentionSettings->unloadToolAfterUnusedTurns ?? LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TURNS,
            $contextRetentionSettings->unloadSkillAfterUnusedTokens ?? LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS,
            $contextRetentionSettings->unloadSkillAfterUnusedTurns ?? LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TURNS,
            $contextRetentionSettings->minResidencyTurns ?? LoadedCapability::DEFAULT_MIN_RESIDENCY_TURNS,
        ));
    }

    /**
     * The settings of `--defaults`: eager loading, every threshold at the platform's default — the base of an A/B
     * measurement.
     *
     * @return ContextRetentionSettings
     */
    protected function buildPlatformDefaultSettings(): ContextRetentionSettings
    {
        $contextRetentionSettings = ContextRetentionSettings::newInstance();
        $contextRetentionSettings->loadToolsOnSkillLoad = true;
        return $contextRetentionSettings;
    }

    /**
     * The fixture file: `--fixture`, else the configured fixture path {@see FIXTURE_PATH_NAME}; empty when neither
     * names one (the fixture then lists no trajectory).
     *
     * @param InputInterface $input
     * @return string
     */
    protected function findFixturePath(InputInterface $input): string
    {
        $fixtureOption = $input->getOption('fixture');
        if (is_string($fixtureOption) && $fixtureOption !== '') {
            return $fixtureOption;
        }
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        return $agentEvalSuitePlanService->findFixturePath(self::FIXTURE_PATH_NAME) ?? '';
    }

    /**
     * `$text` cut to `$maxLength` characters, the last one an ellipsis.
     *
     * @param string $text
     * @param int $maxLength
     * @return string
     */
    protected function truncate(string $text, int $maxLength): string
    {
        return mb_strlen($text) <= $maxLength ? $text : mb_substr($text, 0, $maxLength - 1) . '…';
    }

    /**
     * The runner service (the application's class, when it replaces it).
     *
     * @return AgentEvalRunnerService
     */
    protected function getAgentEvalRunnerService(): AgentEvalRunnerService
    {
        /** @var AgentEvalRunnerService $agentEvalRunnerService */
        $agentEvalRunnerService = DDDService::instance()->getService(AgentEvalRunnerService::class);
        return $agentEvalRunnerService;
    }
}
