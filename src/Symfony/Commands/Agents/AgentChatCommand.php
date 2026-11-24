<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunFields;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunRecording;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunScope;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSessionInterface;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;
use DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Eval\AgentRecordedRunsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Agents\ConversationHtmlTranscript;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRecordedRun;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRenderOptions;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRunOptions;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatScope;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatScopeTrait;
use DDD\Symfony\Commands\Agents\Chat\ConsoleLineEditor;
use DDD\Symfony\Commands\Agents\Chat\CopySlashCommand;
use DDD\Symfony\Commands\Agents\Chat\HelpSlashCommand;
use DDD\Symfony\Commands\Agents\Chat\SlashCommandContext;
use DDD\Symfony\Commands\Agents\Chat\SlashCommandRegistry;
use DDD\Symfony\Commands\AI\ModelTierOptionTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use RuntimeException;
use Throwable;

/**
 * Talks to the agent from the console, one owner turn per call or interactively, and prints every message the turn
 * produces (routing, tool calls, results, text; delegations indented under their child conversation), so routing and
 * answers can be tuned. A stored conversation is resumed by id, so a dialogue also works across separate calls.
 *
 *   php bin/console app:agent:chat --agentSpaceId=7 -m "What changed this week?"
 *   php bin/console app:agent:chat --conversationId=987 -m "And compared with last month?"
 *   php bin/console app:agent:chat --accountId=123 -i                  # interactive
 *   php bin/console app:agent:chat --agentSpaceId=7 --turns-file=turns.txt
 *   php bin/console app:agent:chat --agentSpaceId=7 --turns-file=turns.txt --record-run-for-comparison=run.json
 *   php bin/console app:agent:chat --agentSpaceId=7 --replay-run-and-compare=run.json
 *
 * The run works as the account of `--accountId`, else as the owner of the space the options name. A new conversation
 * is created in that space ({@see createConversationInAgentSpace()}). The console has no message consumer, so the side
 * work production runs in the background (the owner-message embedding, the memory curation, the compaction) runs inline
 * after a turn unless `--no-side-work` is passed.
 *
 * Override points of an application: the scope of the run, shared with `app:agent:chat-tui` through
 * {@see AgentConsoleChatScopeTrait} ({@see resolveChatScope()}, {@see findAgentSpaceForNewConversation()},
 * {@see createConversationInAgentSpace()}, the words {@see describeAgentSpaceOptions()}; the trait also holds the
 * resume of `--conversationId` and the turn inside its envelope); {@see curateMemoryAfterTurn()}; the recorded-run
 * comparison (`--record-run-for-comparison` / `--replay-run-and-compare`, {@see startRecordedRunSession()} and
 * {@see finishRecordedRunSession()}) with the settings a replay must match
 * ({@see buildRecordedRunSettingsPassedByCaller()}), the scope of the run ({@see buildRecordedRunScope()},
 * {@see buildRecordedRunConcludingScopeIds()}) and the wire settings it carries ({@see buildWireRecordingKeys()});
 * {@see buildSlashCommandRegistry()}, and the words {@see getInteractiveChatTitle()}.
 */
#[AsCommand(
    name: 'app:agent:chat',
    aliases: ['app:ai-agent:chat'],
    description: 'Drive the agent chat from the console (single-shot or interactive); debug routing and responses.',
)]
class AgentChatCommand extends AgentConsoleCommand
{
    use AgentConsoleChatScopeTrait, ModelTierOptionTrait, ContextRetentionSettingsOptionsTrait;

    /**
     * The harness name a recording of this command carries (a replay refuses the recording of another harness): the
     * command's former name, kept so older recordings replay.
     */
    public const string RECORDED_RUN_HARNESS = 'app:ai-agent:chat';

    /** The option that records a run of several turns for a later comparison. */
    public const string OPTION_RECORD_RUN_FOR_COMPARISON = 'record-run-for-comparison';

    /** The option that replays a recording and compares the replay with it. */
    public const string OPTION_REPLAY_RUN_AND_COMPARE = 'replay-run-and-compare';

    /** @var string|null The file a recording is written to (`--record-run-for-comparison`); set when the recorded run starts */
    protected ?string $recordingPathToWrite = null;

    /** @var AgentRecordedRunRecording|null The recording a replay answers from (`--replay-run-and-compare`); set when the replay starts */
    protected ?AgentRecordedRunRecording $replayedRecording = null;

    /** @var array<string, mixed> The settings the caller passed that a replay must match, by stored field; set when the recorded run starts */
    protected array $recordedRunSettingsPassedByCaller = [];

    /** @var ConsoleLineEditor|null The raw-mode line editor of the interactive prompt; created on first use, keeps the input history across turns */
    protected ?ConsoleLineEditor $lineEditor = null;

    /** @var string[]|null The capability names loaded after the previous turn, to print what was loaded and evicted since */
    protected ?array $previousCapabilityNames = null;

    protected function configure(): void
    {
        $this->configureScopeOptions();
        $this->configureTurnOptions();
        $this->configureRenderOptions();
        $this->configureConversationSettingsOptions();
        $this->addModelTierOptions();
        $this->addContextRetentionSettingsOptions();
        $this->addCompactionSettingsOptions();
        $this->configureIgnoreBudgetOption();
    }

    /**
     * Declares the account, the space and the conversation of the run.
     *
     * @return void
     */
    protected function configureScopeOptions(): void
    {
        $this->addOption(self::OPTION_ACCOUNT_ID, null, InputOption::VALUE_OPTIONAL, 'The account the run works as. Optional: the owner of the space the options name when omitted');
        $this->configureAgentSpaceOptions();
        $this
            ->addOption(self::OPTION_CONVERSATION_ID, null, InputOption::VALUE_OPTIONAL, 'Resume an existing conversation by id')
            ->addOption('purpose', null, InputOption::VALUE_OPTIONAL, 'Get or start the PURPOSED conversation of the space and print its id. The kickoff turn runs inline in this process; drive the follow-up owner turns with --conversationId (and --async for the worker path). Needs a stored conversation (no --ephemeral).')
            ->addOption('ephemeral', null, InputOption::VALUE_NONE, 'Do not persist (single process, no resume)');
    }

    /**
     * Declares what one call drives: the message, the interactive loop, the turns file, the worker path, the model
     * pin of the provider and the side work.
     *
     * @return void
     */
    protected function configureTurnOptions(): void
    {
        $this
            ->addOption('message', 'm', InputOption::VALUE_OPTIONAL, 'The owner message of this turn')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Interactive loop (for a human session)')
            ->addOption('turns-file', null, InputOption::VALUE_REQUIRED, 'Drive one turn per non-empty line of this file, in this process (inline), one after the other')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch the turn to the worker path (the resume message of the turn transport) instead of driving it inline. With a synchronous transport the handler runs in this process; with a queue a background worker drives the turn and this command returns at once. Needs a stored conversation (no --ephemeral).')
            ->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Use this AIModel by catalog name for the agent calls; default = the conversation\'s default model. Wins over --tier.')
            ->addOption('provider', null, InputOption::VALUE_OPTIONAL, 'Debug probe: pin the gateway routing of the agent calls to this provider order (lowercase provider slugs, comma-separated) without fallback, so a per-provider latency or error probe is attributable. Console-only.')
            ->addOption('no-compact', null, InputOption::VALUE_NONE, 'No inline compaction after a turn. By default the console compacts synchronously once the conversation crosses its threshold (also with --ephemeral). Pass this to inspect the raw, uncompacted context.')
            ->addOption('no-side-work', null, InputOption::VALUE_NONE, 'Latency benchmark: skip the side work production runs in the background, off the response path: the owner-message embedding, the memory curation after a turn and the inline compaction (implies --no-compact). Combine with --timings to measure the core turn alone.')
            ->addOption(self::OPTION_RECORD_RUN_FOR_COMPARISON, null, InputOption::VALUE_REQUIRED, 'Recorded-run comparison: drive --turns-file on a NEW conversation and record every Argus call, tool result and dispatched message plus the conversation tree (root, delegation children, batches, compaction nodes) into this JSON file; with --async every turn takes the worker path, its messages run in this process')
            ->addOption(self::OPTION_REPLAY_RUN_AND_COMPARE, null, InputOption::VALUE_REQUIRED, 'Recorded-run comparison: drive the turns of this recording on a NEW conversation (sync or worker path, as recorded), answer every Argus call and tool result from it and compare the tree rows, the dispatched messages and the sent requests; exit 1 on a difference');
    }

    /**
     * Declares how the turns are printed.
     *
     * @return void
     */
    protected function configureRenderOptions(): void
    {
        $this
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Print message payloads (tool calls and their arguments) untruncated')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Do not truncate tool results')
            ->addOption('collapse-threads', null, InputOption::VALUE_NONE, 'Collapse delegations: show the delegate call and verdict, hide the steps of each child conversation')
            ->addOption('html', null, InputOption::VALUE_OPTIONAL, 'Also write a self-contained HTML transcript to this path (collapsible tool results, rendered Markdown and tables). Rewritten after every turn.')
            ->addOption('timings', null, InputOption::VALUE_NONE, 'Print the wall-clock time of each operation (system-prompt build, each model call, each tool call, the turn)')
            ->addOption('memory', null, InputOption::VALUE_NONE, 'After each turn, print what the memory curation decided. The curation itself runs inline after every stored turn; this option only prints it. Needs a stored conversation (no --ephemeral).')
            ->addOption('context-composition', null, InputOption::VALUE_NONE, 'After each turn, print the labelled, token-sized segment breakdown of the model call that produced the reply (system parts, tool schemas, wire messages), rebuilt from the message\'s request snapshot')
            ->addOption('capabilities', null, InputOption::VALUE_NONE, 'After each turn, print the eviction snapshot of the loaded capabilities (JSON) and what was loaded and evicted since the turn before. Combine with --eager and low --evict-* thresholds to watch eviction.');
    }

    /**
     * Declares the settings of the conversation a call may change; each is stored on a stored conversation, so the
     * worker path reads it too.
     *
     * @return void
     */
    protected function configureConversationSettingsOptions(): void
    {
        $this
            ->addOption('max-steps', null, InputOption::VALUE_OPTIONAL, 'runSettings.maxStepsPerTurn: the model-call steps one owner turn may take across all its worker invocations. Stored.')
            ->addOption('max-turn-cost', null, InputOption::VALUE_OPTIONAL, 'runSettings.maxTurnCostUsd: the USD cost ceiling of one owner turn (own steps and delegation children). A breach ends the turn with a stop message. Stored.')
            ->addOption('store-snapshot', null, InputOption::VALUE_NONE, 'Debug: contextRetentionSettings.storeRequestSnapshot: store the full sent payload and the raw model response on every message. Stored.')
            ->addOption('hint-recent-tool-calls', null, InputOption::VALUE_NONE, 'Test: contextRetentionSettings.hintRecentToolCallsToDiscourageRepeats: append an owner message at the wire tail listing the recent read tool calls with a reuse instruction. Stored.')
            ->addOption('agent-wire-api', null, InputOption::VALUE_REQUIRED, 'The wire API of the agent calls: `chatCompletions` | `responses` | `messages` (the Anthropic Messages API, Anthropic models over the direct OpenRouter egress) (`inherit` clears it). Stored as a run-settings row.')
            ->addOption('previous-reasoning-carry-over', null, InputOption::VALUE_REQUIRED, 'Send the reasoning items of the previous calls back on the later calls: `all` | `currentTurn` | `off` | `capped` (`inherit` = the default). Stored as a run-settings row.')
            ->addOption('previous-reasoning-cap-tokens', null, InputOption::VALUE_REQUIRED, 'The cap of --previous-reasoning-carry-over=capped in reasoning tokens (`0` = the configured default). Stored as a run-settings row.');
    }

    /**
     * Resolves whom the run talks as, prepares the conversation and drives what the options ask for: the purposed
     * conversation, the interactive loop, several turns, or one turn.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $chatScope = $this->resolveChatScope($input);
        if ($chatScope->isRefused()) {
            $output->writeln('<error>' . $chatScope->refusalText . '</error>');
            return $chatScope->refusalExitCode;
        }
        $this->actAsAccount($chatScope->account);
        $this->pinProviderOrderWhenRequested($input);
        $modelName = $this->resolveEffectiveModelName($input);
        $this->writeModelSelection($input, $output, $modelName);
        $runOptions = $this->buildRunOptionsFromInput($input, $modelName);
        $renderOptions = $this->buildRenderOptionsFromInput($input);
        if ($renderOptions->timings && isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            // from the start of the process (autoload, container boot, this command's set-up) to the first turn
            $output->writeln(sprintf('<fg=gray>⏱  console boot  %.3fs</>', microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT']));
        }

        $purposeName = $input->getOption('purpose');
        if (is_string($purposeName) && $purposeName !== '') {
            return $this->startPurposedConversation($purposeName, $chatScope, $runOptions, $input, $output);
        }

        // every settings value is parsed before anything is written: a refused value leaves no conversation behind
        $compactionSettingsOverride = $this->buildCompactionSettingsOverrideFromInput($input);
        $contextRetentionSettingsOverride = $this->buildContextRetentionSettingsOverrideFromInput($input);
        $wireRunSettingsOverride = $this->buildWireRunSettingsOverrideFromInput($input);

        $this->modelNameOfNewConversation = $modelName;
        $conversation = $this->findOrCreateConversation(
            $this->readIdOption($input, self::OPTION_CONVERSATION_ID),
            $chatScope,
            $runOptions->ephemeral,
            $contextRetentionSettingsOverride?->skillLoadingStrategy
        );
        $this->applyConversationSettingsFromInput($conversation, $input, $runOptions, $compactionSettingsOverride, $contextRetentionSettingsOverride, $wireRunSettingsOverride);

        return $this->driveConversation($conversation, $input, $runOptions, $renderOptions, $output);
    }

    /**
     * Drives the prepared conversation: the interactive loop, several turns (a turns file or a recorded run), or the
     * one turn of `--message`.
     *
     * @param AIConversation $conversation
     * @param InputInterface $input
     * @param AgentConsoleChatRunOptions $runOptions
     * @param AgentConsoleChatRenderOptions $renderOptions
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function driveConversation(
        AIConversation $conversation,
        InputInterface $input,
        AgentConsoleChatRunOptions $runOptions,
        AgentConsoleChatRenderOptions $renderOptions,
        OutputInterface $output
    ): int {
        if ((bool)$input->getOption('interactive')) {
            return $this->runInteractive($conversation, $runOptions, $renderOptions, $output);
        }

        $recordedRun = $this->startRecordedRunSession($input, $conversation, $output);
        $turnsFile = $input->getOption('turns-file');
        if ($recordedRun !== null || is_string($turnsFile)) {
            return $this->runSeveralTurns($conversation, is_string($turnsFile) ? $turnsFile : null, $recordedRun, $runOptions, $renderOptions, $output);
        }

        $message = $input->getOption('message');
        if (!is_string($message) || $message === '') {
            $output->writeln('<error>--message (-m) is required (or use --interactive)</error>');
            return Command::INVALID;
        }
        if ($runOptions->async && $runOptions->ephemeral) {
            $output->writeln('<error>--async needs a persisted conversation (the worker re-loads it by id) — drop --ephemeral</error>');
            return Command::INVALID;
        }
        // single-shot: the terminal did not echo the message, so the printer does
        $this->runTurn($conversation, $message, $runOptions, $renderOptions->withEchoOfUserMessage(true), $output);
        $this->printFooter($conversation, $output);
        return Command::SUCCESS;
    }

    /**
     * Gets or starts the purposed conversation of the space {@see findAgentSpaceForNewConversation()} names and prints
     * its id. The kickoff turn runs inline
     * (a process grant of `--ignore-budget` covers it); with `--ignore-budget` the conversation is marked as lifted, so
     * the workers that drive its later turns inherit the lift.
     *
     * @param string $purposeName
     * @param AgentConsoleChatScope $chatScope
     * @param AgentConsoleChatRunOptions $runOptions
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function startPurposedConversation(string $purposeName, AgentConsoleChatScope $chatScope, AgentConsoleChatRunOptions $runOptions, InputInterface $input, OutputInterface $output): int
    {
        $agentSpace = $runOptions->ephemeral ? null : $this->findAgentSpaceForNewConversation($chatScope);
        if ($agentSpace === null) {
            $output->writeln('<error>--purpose needs ' . $this->describeAgentSpaceOptions() . ' and a persisted conversation (no --ephemeral)</error>');
            return Command::INVALID;
        }
        $aIConversationAgentService = $this->getAIConversationAgentService();
        $purposedConversation = $aIConversationAgentService->getOrStartPurposedConversation(
            AIConversationDriveScope::CUSTOMER,
            $agentSpace,
            $purposeName,
            async: false,
            aiModelName: $runOptions->aiModelName
        );
        if ($this->isBudgetBypassRequested($input) && isset($purposedConversation->id) && !$purposedConversation->budgetBypass) {
            $purposedConversation->budgetBypass = true;
            $purposedConversation->updatePartialIgnoringRights('budgetBypass');
        }
        $output->writeln('conversation: ' . ($purposedConversation->id ?? '?') . '  (resume: --conversationId=' . ($purposedConversation->id ?? '?') . ')');
        return Command::SUCCESS;
    }

    /**
     * @param InputInterface $input
     * @param string|null $modelName the model {@see resolveEffectiveModelName()} resolved
     * @return AgentConsoleChatRunOptions
     */
    protected function buildRunOptionsFromInput(InputInterface $input, ?string $modelName): AgentConsoleChatRunOptions
    {
        $sideWorkEnabled = !(bool)$input->getOption('no-side-work');
        return new AgentConsoleChatRunOptions(
            ephemeral: (bool)$input->getOption('ephemeral'),
            aiModelName: $modelName,
            async: (bool)$input->getOption('async'),
            // inline compaction is side work: --no-side-work implies --no-compact
            compactionEnabled: $sideWorkEnabled && !(bool)$input->getOption('no-compact'),
            sideWorkEnabled: $sideWorkEnabled
        );
    }

    /**
     * @param InputInterface $input
     * @return AgentConsoleChatRenderOptions
     */
    protected function buildRenderOptionsFromInput(InputInterface $input): AgentConsoleChatRenderOptions
    {
        $htmlPath = $input->getOption('html');
        return new AgentConsoleChatRenderOptions(
            debug: (bool)$input->getOption('debug'),
            full: (bool)$input->getOption('full'),
            collapseThreads: (bool)$input->getOption('collapse-threads'),
            timings: (bool)$input->getOption('timings'),
            showCapabilities: (bool)$input->getOption('capabilities'),
            showContextComposition: (bool)$input->getOption('context-composition'),
            showMemoryCuration: (bool)$input->getOption('memory'),
            htmlPath: is_string($htmlPath) && $htmlPath !== '' ? $htmlPath : null
        );
    }

    /**
     * Pins the provider order of the agent calls for this process when `--provider` is passed.
     *
     * @param InputInterface $input
     * @return void
     */
    protected function pinProviderOrderWhenRequested(InputInterface $input): void
    {
        $providerOrder = $input->getOption('provider');
        if (is_string($providerOrder) && $providerOrder !== '') {
            ArgusAIAgentConversation::forceProviderOrder(array_map('trim', explode(',', $providerOrder)));
        }
    }

    /**
     * The run-settings rows of the wire options, parsed; null when none was passed. Each row is checked against the
     * run's model when the run starts; a rejected combination fails the turn with the reason.
     *
     * @param InputInterface $input
     * @return RunSettingsOverride|null
     * @throws InvalidOptionException when the cap is no whole number
     */
    protected function buildWireRunSettingsOverrideFromInput(InputInterface $input): ?RunSettingsOverride
    {
        $agentWireApi = $this->readOptionValue($input, 'agent-wire-api');
        $previousReasoningCarryOver = $this->readOptionValue($input, 'previous-reasoning-carry-over');
        $previousReasoningCapTokens = $this->readOptionValue($input, 'previous-reasoning-cap-tokens');
        if ($previousReasoningCapTokens !== null && $previousReasoningCapTokens !== '' && !ctype_digit(trim($previousReasoningCapTokens))) {
            throw new InvalidOptionException('--previous-reasoning-cap-tokens must be 0 or a positive number of reasoning tokens');
        }
        return RunSettingsOverride::fromValues(
            agentWireApi: $agentWireApi !== null && $agentWireApi !== '' ? $agentWireApi : null,
            previousReasoningCarryOver: $previousReasoningCarryOver !== null && $previousReasoningCarryOver !== '' ? $previousReasoningCarryOver : null,
            previousReasoningCapTokens: $previousReasoningCapTokens !== null && $previousReasoningCapTokens !== '' ? (int)trim($previousReasoningCapTokens) : null
        );
    }

    /**
     * Writes what the options set onto the conversation: the budget lift, the model pin, the compaction knobs, the
     * turn limits, the wire rows, the debug switches and the context-retention knobs. A stored conversation stores each
     * changed column on its own (the worker path re-loads the conversation by id and reads them there); an ephemeral one
     * keeps them in memory.
     *
     * @param AIConversation $conversation
     * @param InputInterface $input
     * @param AgentConsoleChatRunOptions $runOptions
     * @param CompactionSettingsOverride|null $compactionSettingsOverride
     * @param ContextRetentionSettingsOverride|null $contextRetentionSettingsOverride
     * @param RunSettingsOverride|null $wireRunSettingsOverride
     * @return void
     * @throws Throwable
     */
    protected function applyConversationSettingsFromInput(
        AIConversation $conversation,
        InputInterface $input,
        AgentConsoleChatRunOptions $runOptions,
        ?CompactionSettingsOverride $compactionSettingsOverride,
        ?ContextRetentionSettingsOverride $contextRetentionSettingsOverride,
        ?RunSettingsOverride $wireRunSettingsOverride
    ): void {
        $ephemeral = $runOptions->ephemeral;
        // the process grant of --ignore-budget does not reach a worker: the stored conversation carries the lift
        if ($this->isBudgetBypassRequested($input) && !$ephemeral && isset($conversation->id) && !$conversation->budgetBypass) {
            $conversation->budgetBypass = true;
            $conversation->update(0);
        }
        // a worker resolves the model from the stored pin, never from this command's resolution; an inline turn gets
        // the model as its argument, so an ephemeral conversation is not pinned
        $pinnedModelName = $this->readOptionValue($input, 'model');
        if ($pinnedModelName !== null && $pinnedModelName !== '' && !$ephemeral && isset($conversation->id)) {
            $conversation->aiModelNameOverride = $pinnedModelName;
            $conversation->updatePartialIgnoringRights('aiModelNameOverride');
        }
        if ($compactionSettingsOverride !== null) {
            $conversation->compactionSettings = $compactionSettingsOverride->applyTo($conversation->compactionSettings ?? null);
            $this->storeConversationColumnUnlessEphemeral($conversation, 'compactionSettings', $ephemeral);
        }
        $this->applyTurnLimitsFromInput($conversation, $input, $ephemeral);
        if ($wireRunSettingsOverride !== null) {
            $wireRunSettings = $conversation->runSettings ?? RunSettings::newInstance();
            $wireRunSettings->applyOverride($wireRunSettingsOverride, null, new DateTime());
            $conversation->runSettings = $wireRunSettings;
            $this->storeConversationColumnUnlessEphemeral($conversation, 'runSettings', $ephemeral);
        }
        $this->applyDebugRetentionSwitchesFromInput($conversation, $input, $ephemeral);
        if ($contextRetentionSettingsOverride !== null) {
            $this->applyContextRetentionSettingsOverride($conversation, $contextRetentionSettingsOverride, $ephemeral);
        }
    }

    /**
     * `--max-steps` and `--max-turn-cost` onto the conversation's run settings.
     *
     * @param AIConversation $conversation
     * @param InputInterface $input
     * @param bool $ephemeral
     * @return void
     * @throws Throwable
     */
    protected function applyTurnLimitsFromInput(AIConversation $conversation, InputInterface $input, bool $ephemeral): void
    {
        $maxStepsPerTurn = $this->readOptionValue($input, 'max-steps');
        $maxTurnCostUsd = $this->readOptionValue($input, 'max-turn-cost');
        $maxStepsPassed = $maxStepsPerTurn !== null && $maxStepsPerTurn !== '';
        $maxTurnCostPassed = $maxTurnCostUsd !== null && $maxTurnCostUsd !== '';
        if (!$maxStepsPassed && !$maxTurnCostPassed) {
            return;
        }
        $runSettings = $conversation->runSettings ?? RunSettings::newInstance();
        if ($maxStepsPassed) {
            $runSettings->maxStepsPerTurn = (int)$maxStepsPerTurn;
        }
        if ($maxTurnCostPassed) {
            $runSettings->maxTurnCostUsd = (float)$maxTurnCostUsd;
        }
        $conversation->runSettings = $runSettings;
        $this->storeConversationColumnUnlessEphemeral($conversation, 'runSettings', $ephemeral);
    }

    /**
     * `--store-snapshot` and `--hint-recent-tool-calls` onto the conversation's context-retention settings.
     *
     * @param AIConversation $conversation
     * @param InputInterface $input
     * @param bool $ephemeral
     * @return void
     * @throws Throwable
     */
    protected function applyDebugRetentionSwitchesFromInput(AIConversation $conversation, InputInterface $input, bool $ephemeral): void
    {
        $storeRequestSnapshot = (bool)$input->getOption('store-snapshot');
        $hintRecentToolCalls = (bool)$input->getOption('hint-recent-tool-calls');
        if (!$storeRequestSnapshot && !$hintRecentToolCalls) {
            return;
        }
        $contextRetentionSettings = $conversation->contextRetentionSettings ?? ContextRetentionSettings::newInstance();
        if ($storeRequestSnapshot) {
            $contextRetentionSettings->storeRequestSnapshot = true;
        }
        if ($hintRecentToolCalls) {
            $contextRetentionSettings->hintRecentToolCallsToDiscourageRepeats = true;
        }
        $conversation->contextRetentionSettings = $contextRetentionSettings;
        $this->storeConversationColumnUnlessEphemeral($conversation, 'contextRetentionSettings', $ephemeral);
    }

    /**
     * The context-retention knobs onto the conversation's settings; the stamped skill-loading strategy stays (a change
     * after the conversation's first model call is refused).
     *
     * @param AIConversation $conversation
     * @param ContextRetentionSettingsOverride $contextRetentionSettingsOverride
     * @param bool $ephemeral
     * @return void
     * @throws Throwable
     */
    protected function applyContextRetentionSettingsOverride(AIConversation $conversation, ContextRetentionSettingsOverride $contextRetentionSettingsOverride, bool $ephemeral): void
    {
        $contextRetentionSettings = $contextRetentionSettingsOverride->applyTo($conversation->contextRetentionSettings ?? null);
        $aIConversationsService = $this->getAIConversationsService();
        $conversation->contextRetentionSettings = $aIConversationsService->keepStampedSkillLoadingStrategy($conversation, $contextRetentionSettings);
        $this->storeConversationColumnUnlessEphemeral($conversation, 'contextRetentionSettings', $ephemeral);
    }

    /**
     * Stores one column of a stored conversation (a targeted write that leaves the run state alone); an ephemeral or
     * not yet stored conversation keeps the value in memory.
     *
     * @param AIConversation $conversation
     * @param string $columnName
     * @param bool $ephemeral
     * @return void
     * @throws Throwable
     */
    protected function storeConversationColumnUnlessEphemeral(AIConversation $conversation, string $columnName, bool $ephemeral): void
    {
        if (!$ephemeral && isset($conversation->id)) {
            $conversation->updatePartialIgnoringRights($columnName);
        }
    }

    /**
     * The interactive loop: one turn per entry, a slash command ({@see buildSlashCommandRegistry()}) instead of a
     * message, an empty entry or `exit` ends it.
     *
     * @param AIConversation $conversation
     * @param AgentConsoleChatRunOptions $runOptions
     * @param AgentConsoleChatRenderOptions $renderOptions
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function runInteractive(AIConversation $conversation, AgentConsoleChatRunOptions $runOptions, AgentConsoleChatRenderOptions $renderOptions, OutputInterface $output): int
    {
        $slashCommandRegistry = $this->buildSlashCommandRegistry();
        $slashCommandContext = new SlashCommandContext(
            $conversation,
            $output,
            $this->getAgentMessagePrinter(),
            $slashCommandRegistry,
            $renderOptions->full,
            $renderOptions->debug,
            $renderOptions->collapseThreads
        );
        $output->writeln('<comment>' . $this->getInteractiveChatTitle() . ' — empty line or /exit to quit, /help for commands.</comment>');
        $output->writeln('<fg=gray>←/→ move, Option/Ctrl+←/→ jump words, Home/End, ↑/↓ history. Option/Shift+Enter (or \\) = new line, Enter sends. Ctrl+C exits.</>');
        $this->printFooter($conversation, $output);
        // the terminal echoed the typed entry at the prompt already
        $interactiveRenderOptions = $renderOptions->withEchoOfUserMessage(false);
        $interactiveRunOptions = $runOptions->withAsync(false);
        while (true) {
            $enteredText = $this->readUserInput($output);
            if ($enteredText === null || trim($enteredText) === '' || trim($enteredText) === 'exit') {
                break;
            }
            if ($slashCommandRegistry->isSlashCommand($enteredText)) {
                // a quit command answers false
                if (!$slashCommandRegistry->dispatch($enteredText, $slashCommandContext)) {
                    break;
                }
                continue;
            }
            $this->runTurn($conversation, $enteredText, $interactiveRunOptions, $interactiveRenderOptions, $output);
        }
        $this->printFooter($conversation, $output);
        return Command::SUCCESS;
    }

    /**
     * The headline of the interactive loop.
     *
     * @return string
     */
    protected function getInteractiveChatTitle(): string
    {
        return 'Interactive agent chat';
    }

    /**
     * The slash commands of the interactive loop; `/help` lists every registered one, `/exit` and `/quit` are handled
     * by the registry. An application registers its own commands here.
     *
     * @return SlashCommandRegistry
     */
    protected function buildSlashCommandRegistry(): SlashCommandRegistry
    {
        $slashCommandRegistry = new SlashCommandRegistry();
        $slashCommandRegistry->register(new HelpSlashCommand());
        $slashCommandRegistry->register(new CopySlashCommand());
        return $slashCommandRegistry;
    }

    /**
     * Reads one entry, possibly over several lines. On a terminal the raw-mode {@see ConsoleLineEditor} edits it
     * (Option/Shift+Enter inserts a line, Enter submits, Ctrl+C or Ctrl+D on an empty entry end); on piped input a line
     * ending in a backslash continues on the next line. Null at the end of the input.
     *
     * @param OutputInterface $output
     * @return string|null
     */
    protected function readUserInput(OutputInterface $output): ?string
    {
        $output->writeln('');
        $consoleLineEditor = $this->lineEditor ??= new ConsoleLineEditor();
        $primaryPrompt = $output->isDecorated() ? "\033[32myou>\033[0m " : 'you> ';
        if ($consoleLineEditor->isInteractive()) {
            return $consoleLineEditor->readLine($primaryPrompt);
        }
        $continuationPrompt = $output->isDecorated() ? "\033[90m…\033[0m " : '… ';
        $collectedLines = [];
        $prompt = $primaryPrompt;
        while (true) {
            $output->write($prompt);
            $readLine = fgets(STDIN);
            if ($readLine === false) {
                return $collectedLines === [] ? null : implode("\n", $collectedLines);
            }
            $lineText = rtrim($readLine, "\r\n");
            if (str_ends_with($lineText, '\\') && !str_ends_with($lineText, '\\\\')) {
                $collectedLines[] = substr($lineText, 0, -1);
                $prompt = $continuationPrompt;
                continue;
            }
            if (str_ends_with($lineText, '\\\\')) {
                $lineText = substr($lineText, 0, -1);
            }
            $collectedLines[] = $lineText;
            break;
        }
        return implode("\n", $collectedLines);
    }

    /**
     * Several turns in this process, one after the other: the lines of a turns file, or the turns of a recorded run
     * ({@see startRecordedRunSession()}). Needs a stored conversation.
     *
     * @param AIConversation $conversation
     * @param string|null $turnsFile
     * @param AgentConsoleChatRecordedRun|null $recordedRun
     * @param AgentConsoleChatRunOptions $runOptions
     * @param AgentConsoleChatRenderOptions $renderOptions
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function runSeveralTurns(
        AIConversation $conversation,
        ?string $turnsFile,
        ?AgentConsoleChatRecordedRun $recordedRun,
        AgentConsoleChatRunOptions $runOptions,
        AgentConsoleChatRenderOptions $renderOptions,
        OutputInterface $output
    ): int {
        if ($runOptions->ephemeral || !isset($conversation->id)) {
            $output->writeln('<error>several turns and the recorded-run comparison need a persisted conversation (drop --ephemeral)</error>');
            return Command::INVALID;
        }
        if ($recordedRun !== null) {
            if ($recordedRun->isRefused()) {
                $output->writeln('<error>' . $recordedRun->refusalText . '</error>');
                return Command::INVALID;
            }
            return $this->runRecordedTurns($conversation, $recordedRun, $runOptions->withAsync($recordedRun->async), $renderOptions->forSeveralTurns(), $output);
        }
        $turns = $this->readTurnsOfFile($turnsFile);
        if ($turns === null) {
            $output->writeln('<error>--turns-file is missing or empty</error>');
            return Command::INVALID;
        }
        $turnRunOptions = $runOptions->withAsync(false);
        $turnRenderOptions = $renderOptions->forSeveralTurns();
        foreach ($turns as $turn) {
            $this->runTurn($conversation, $turn, $turnRunOptions, $turnRenderOptions, $output);
        }
        $this->printFooter($conversation, $output);
        return Command::SUCCESS;
    }

    /**
     * The non-empty lines of `$turnsFile`, trimmed; null when the file is missing, unreadable or has none.
     *
     * @param string|null $turnsFile
     * @return string[]|null
     */
    protected function readTurnsOfFile(?string $turnsFile): ?array
    {
        $turnLines = $turnsFile !== null && is_file($turnsFile) ? file($turnsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($turnLines === false) {
            return null;
        }
        $turns = array_values(array_filter(array_map('trim', $turnLines), 'strlen'));
        return $turns === [] ? null : $turns;
    }

    /**
     * Drives the turns of a recorded run under its session: every call of a turn, and of every message the turn
     * dispatches, is recorded or answered under the turn's ordinal; the dispatched messages run in this process in
     * dispatch order, after which the next turn reads the conversation as stored. The session is stopped in any case
     * (on an exception nothing queued runs any more); then {@see finishRecordedRunSession()} concludes the run.
     *
     * @param AIConversation $conversation
     * @param AgentConsoleChatRecordedRun $recordedRun
     * @param AgentConsoleChatRunOptions $turnRunOptions
     * @param AgentConsoleChatRenderOptions $turnRenderOptions
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function runRecordedTurns(
        AIConversation $conversation,
        AgentConsoleChatRecordedRun $recordedRun,
        AgentConsoleChatRunOptions $turnRunOptions,
        AgentConsoleChatRenderOptions $turnRenderOptions,
        OutputInterface $output
    ): int {
        $agentRecordedRunSession = $recordedRun->agentRecordedRunSession;
        $agentRecordedRunSession->start();
        try {
            foreach ($recordedRun->turns as $turnIndex => $turn) {
                $agentRecordedRunSession->beginTurn($turnIndex + 1);
                $this->runTurn($conversation, $turn, $turnRunOptions, $turnRenderOptions, $output);
                // a consumed message starts on fresh worker state: the next turn reads the conversation as stored
                if ($agentRecordedRunSession->runQueuedMessages() > 0) {
                    $conversation = $this->findConversationAsStored((int)$conversation->id) ?? $conversation;
                }
            }
            $agentRecordedRunSession->runQueuedMessages();
        } finally {
            $agentRecordedRunSession->stop(runRemainingMessages: false);
        }
        $conversation = $this->findConversationAsStored((int)$conversation->id) ?? $conversation;
        return $this->finishRecordedRunSession($recordedRun, $conversation, $output);
    }

    /**
     * The recorded-run comparison the options ask for, with its session created and its scope set but not started;
     * null when no recording is asked for. RECORD (`--record-run-for-comparison`) drives the lines of `--turns-file`;
     * REPLAY (`--replay-run-and-compare`) drives the recording's turns, as recorded on the worker path or inline, with
     * every Argus call and tool result answered from it. A replay of another harness, or with a setting the caller
     * passed that differs from the recorded one ({@see buildRecordedRunSettingsPassedByCaller()},
     * {@see buildWireRecordingKeys()}), is returned refused, as is an unreadable recording or a missing turns file.
     *
     * @param InputInterface $input
     * @param AIConversation $conversation the conversation the turns are driven on
     * @param OutputInterface $output
     * @return AgentConsoleChatRecordedRun|null
     * @throws Throwable
     */
    protected function startRecordedRunSession(InputInterface $input, AIConversation $conversation, OutputInterface $output): ?AgentConsoleChatRecordedRun
    {
        $recordingPathToWrite = $input->hasOption(self::OPTION_RECORD_RUN_FOR_COMPARISON) ? $input->getOption(self::OPTION_RECORD_RUN_FOR_COMPARISON) : null;
        $recordingPathToReplay = $input->hasOption(self::OPTION_REPLAY_RUN_AND_COMPARE) ? $input->getOption(self::OPTION_REPLAY_RUN_AND_COMPARE) : null;
        $this->recordingPathToWrite = is_string($recordingPathToWrite) ? $recordingPathToWrite : null;
        $this->replayedRecording = null;
        if (!is_string($recordingPathToReplay) && $this->recordingPathToWrite === null) {
            return null;
        }
        $this->recordedRunSettingsPassedByCaller = $this->buildRecordedRunSettingsPassedByCaller($input, $conversation);
        $agentRecordedRunsService = $this->getAgentRecordedRunsService();
        if (is_string($recordingPathToReplay)) {
            try {
                $agentRecordedRunRecording = $agentRecordedRunsService->findRecording($recordingPathToReplay);
            } catch (RuntimeException $recordingError) {
                return AgentConsoleChatRecordedRun::refused($recordingError->getMessage());
            }
            $replayRefusal = $agentRecordedRunRecording->findReplayRefusal(
                static::RECORDED_RUN_HARNESS,
                $this->recordedRunSettingsPassedByCaller + $this->buildWireRecordingKeys($conversation)
            );
            if ($replayRefusal !== null) {
                return AgentConsoleChatRecordedRun::refused("{$recordingPathToReplay}: {$replayRefusal}");
            }
            $this->replayedRecording = $agentRecordedRunRecording;
            $turns = $agentRecordedRunRecording->getTurns();
            $async = $agentRecordedRunRecording->isAsync();
            $agentRecordedRunSession = $agentRecordedRunsService->startSession($agentRecordedRunRecording);
        } else {
            $turnsFile = $input->getOption('turns-file');
            $turns = $this->readTurnsOfFile(is_string($turnsFile) ? $turnsFile : null);
            if ($turns === null) {
                return AgentConsoleChatRecordedRun::refused('--turns-file is missing or empty');
            }
            $async = (bool)$input->getOption('async');
            $agentRecordedRunSession = $agentRecordedRunsService->startSession(null);
        }
        $agentRecordedRunSession->setScope($this->buildRecordedRunScope($conversation));
        return AgentConsoleChatRecordedRun::forSession($agentRecordedRunSession, $turns, $async);
    }

    /**
     * Writes the recording (record mode) or compares the replay with its recording and prints every difference
     * (replay mode; exit 1 on a difference). The root conversation reads as a scope id
     * ({@see buildRecordedRunConcludingScopeIds()}): the chat opens it before the session reads the high-water marks.
     *
     * @param AgentConsoleChatRecordedRun $recordedRun
     * @param AIConversation $conversation the conversation as stored after the run
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     */
    protected function finishRecordedRunSession(AgentConsoleChatRecordedRun $recordedRun, AIConversation $conversation, OutputInterface $output): int
    {
        $agentRecordedRunSession = $recordedRun->agentRecordedRunSession;
        if ($agentRecordedRunSession === null) {
            return Command::INVALID;
        }
        $scopeIdsByName = $this->buildRecordedRunConcludingScopeIds($conversation);
        if (!$agentRecordedRunSession->isReplay()) {
            $agentRecordedRunRecording = $agentRecordedRunSession->buildRecording($conversation, $scopeIdsByName, $this->buildRecordedRunFields($recordedRun, $conversation));
            $agentRecordedRunsService = $this->getAgentRecordedRunsService();
            $agentRecordedRunsService->writeRecording((string)$this->recordingPathToWrite, $agentRecordedRunRecording);
            $this->printSharedScopeTables($agentRecordedRunSession, $output);
            $output->writeln(sprintf(
                '<info>run recording for comparison: %d turns, %s → %s</info>',
                count($recordedRun->turns),
                $agentRecordedRunSession->describeRun($agentRecordedRunRecording->getSnapshot()),
                $this->recordingPathToWrite
            ));
            return Command::SUCCESS;
        }
        $agentRecordedRunComparison = $agentRecordedRunSession->compareWithRecording($conversation, $scopeIdsByName);
        $this->printSharedScopeTables($agentRecordedRunSession, $output);
        return $this->printComparison($agentRecordedRunSession, $agentRecordedRunComparison, $recordedRun, $conversation, $output);
    }

    /**
     * The settings the caller passed that a replay must match, by the field the recording stores them under (null = not
     * passed: the recording decides); stored as run fields of a recording. The package's: the space of `--agentSpaceId`.
     * An application that names the scope of its chat by an option of its own returns that option.
     *
     * @param InputInterface $input
     * @param AIConversation $conversation
     * @return array<string, mixed> stored field → the value the caller passed
     */
    protected function buildRecordedRunSettingsPassedByCaller(InputInterface $input, AIConversation $conversation): array
    {
        return [self::OPTION_AGENT_SPACE_ID => $this->readIdOption($input, self::OPTION_AGENT_SPACE_ID)];
    }

    /**
     * The scope of a recorded run: the space and the account of the conversation, both shared with other runs (the
     * chat runs in an existing space), and the conversation as the root.
     *
     * @param AIConversation $conversation
     * @return AgentRecordedRunScope
     */
    protected function buildRecordedRunScope(AIConversation $conversation): AgentRecordedRunScope
    {
        return AgentRecordedRunScope::forScopeIds(
            [
                AgentRecordedRunsService::SCOPE_NAME_AGENT_SPACE => $conversation->agentSpaceId ?? null,
                AgentRecordedRunScope::SCOPE_NAME_ACCOUNT => $conversation->accountId ?? null,
            ],
            [AgentRecordedRunScope::SCOPE_NAME_ACCOUNT, AgentRecordedRunsService::SCOPE_NAME_AGENT_SPACE],
            isset($conversation->id) ? (int)$conversation->id : null
        );
    }

    /**
     * The scope ids a recorded run names as its own when it concludes (stored as the recording's `scopeIds`): the
     * space of the conversation and the root conversation.
     *
     * @param AIConversation $conversation
     * @return array<string, int|string|null>
     */
    protected function buildRecordedRunConcludingScopeIds(AIConversation $conversation): array
    {
        return [
            AgentRecordedRunsService::SCOPE_NAME_AGENT_SPACE => $conversation->agentSpaceId ?? null,
            AgentRecordedRunSession::SCOPE_NAME_ROOT_CONVERSATION => (int)$conversation->id,
        ];
    }

    /**
     * The run fields of a chat recording, in stored order: the harness, the settings the caller passed, the worker path,
     * the turns, then the wire settings.
     *
     * @param AgentConsoleChatRecordedRun $recordedRun
     * @param AIConversation $conversation
     * @return AgentRecordedRunFields
     */
    protected function buildRecordedRunFields(AgentConsoleChatRecordedRun $recordedRun, AIConversation $conversation): AgentRecordedRunFields
    {
        return AgentRecordedRunFields::fromValuesByField(
            [AgentRecordedRunFields::FIELD_HARNESS => static::RECORDED_RUN_HARNESS]
            + $this->recordedRunSettingsPassedByCaller
            + ['async' => $recordedRun->async, 'turns' => $recordedRun->turns]
            + $this->buildWireRecordingKeys($conversation)
        );
    }

    /**
     * @param AgentRecordedRunSessionInterface $agentRecordedRunSession
     * @param OutputInterface $output
     * @return void
     */
    protected function printSharedScopeTables(AgentRecordedRunSessionInterface $agentRecordedRunSession, OutputInterface $output): void
    {
        $sharedScopeTablesLine = $agentRecordedRunSession->describeSharedScopeTables();
        if ($sharedScopeTablesLine !== null) {
            $output->writeln('<comment>' . $sharedScopeTablesLine . '</comment>');
        }
    }

    /**
     * @param AgentRecordedRunSessionInterface $agentRecordedRunSession
     * @param AgentRecordedRunComparison $agentRecordedRunComparison
     * @param AgentConsoleChatRecordedRun $recordedRun
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @return int `Command::SUCCESS` when identical, else `Command::FAILURE`
     */
    protected function printComparison(
        AgentRecordedRunSessionInterface $agentRecordedRunSession,
        AgentRecordedRunComparison $agentRecordedRunComparison,
        AgentConsoleChatRecordedRun $recordedRun,
        AIConversation $conversation,
        OutputInterface $output
    ): int {
        foreach ($agentRecordedRunComparison->playedAnswerIdTranslations as $playedAnswerIdTranslation) {
            $output->writeln('<fg=gray>  translated in a played answer: ' . $playedAnswerIdTranslation . '</>');
        }
        if ($agentRecordedRunComparison->isIdentical()) {
            $output->writeln(sprintf(
                '<info>recorded-run comparison IDENTICAL: %d turns, %s (conversation %d)</info>',
                count($recordedRun->turns),
                $agentRecordedRunSession->describeRun(($this->replayedRecording ?? AgentRecordedRunRecording::newInstance())->getSnapshot()),
                (int)$conversation->id
            ));
            return Command::SUCCESS;
        }
        $output->writeln(sprintf('<error>recorded-run comparison DIFFERS (conversation %d): %d difference(s)</error>', (int)$conversation->id, count($agentRecordedRunComparison->differences)));
        foreach ($agentRecordedRunComparison->differences as $difference) {
            $output->writeln('  ' . $difference);
        }
        return Command::FAILURE;
    }

    /**
     * The wire settings a recorded run carries and a replay must match: the conversation's wire-API row, its stamped
     * skill-loading strategy (null for the default), its carry-over row, the cap row and the state-change delivery
     * (null for the default) — a replay under another arm is refused. A default reads as absent, so an older recording
     * replays unchanged.
     *
     * @param AIConversation $conversation
     * @return array<string, string|null>
     */
    protected function buildWireRecordingKeys(AIConversation $conversation): array
    {
        $runSettings = $conversation->runSettings ?? null;
        return [
            'agentWireApi' => $runSettings?->agentWireApi,
            'skillLoadingStrategy' => $conversation->usesConversationAppend() ? ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND : null,
            'previousReasoningCarryOver' => $runSettings?->previousReasoningCarryOver,
            'previousReasoningCapTokens' => $runSettings?->previousReasoningCapTokens !== null ? (string)$runSettings->previousReasoningCapTokens : null,
            'stateChangeDelivery' => $conversation->deliversStateChangesOnce() ? ContextRetentionSettings::STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED : null,
        ];
    }

    /**
     * The conversation as its row stands now, read past the entity registry (after messages ran on fresh worker state);
     * {@see findStoredConversation()} reads through the registry.
     *
     * @param int $conversationId
     * @return AIConversation|null
     * @throws Throwable
     */
    protected function findConversationAsStored(int $conversationId): ?AIConversation
    {
        $aIConversationsService = $this->getAIConversationsService();
        $storedConversation = $aIConversationsService->find($conversationId, useEntityRegistrCache: false);
        return $storedConversation instanceof AIConversation ? $storedConversation : null;
    }

    /**
     * One owner turn: the owner's message is appended (and stored), the turn is driven inline or dispatched to the
     * worker path, then the blocks the render options ask for are printed and, on a stored conversation, the memory is
     * curated.
     *
     * @param AIConversation $conversation
     * @param string $message
     * @param AgentConsoleChatRunOptions $runOptions
     * @param AgentConsoleChatRenderOptions $renderOptions
     * @param OutputInterface $output
     * @return void
     * @throws Throwable
     */
    protected function runTurn(AIConversation $conversation, string $message, AgentConsoleChatRunOptions $runOptions, AgentConsoleChatRenderOptions $renderOptions, OutputInterface $output): void
    {
        $userMessage = $this->appendOwnerMessage($conversation, $message, $runOptions);
        if ($renderOptions->echoUserMessage) {
            $agentMessagePrinter = $this->getAgentMessagePrinter();
            $agentMessagePrinter->renderMessage($userMessage, $output, $renderOptions->full, $renderOptions->debug, $renderOptions->collapseThreads, $conversation);
        }
        $tokensBefore = $conversation->messages?->getTotalTokens() ?? 0;
        $costBefore = $conversation->messages?->getTotalCostUsd() ?? 0.0;
        // inline compaction leaves no other trace on an ephemeral run than the grown tree
        $compactionTreeSizeBefore = count(isset($conversation->compactionNodes) ? $conversation->compactionNodes->getElements() : []);

        if ($runOptions->async) {
            $this->dispatchTurnToWorkerPath($conversation, $message, $output);
            $this->printTurnMetrics($conversation, $output, $tokensBefore, $costBefore);
            if ($renderOptions->htmlPath !== null) {
                $this->writeHtmlTranscript($conversation, $renderOptions->htmlPath, $output);
            }
            return;
        }

        $this->driveTurnInline($conversation, $runOptions, $renderOptions, $output);
        $this->printTurnMetrics($conversation, $output, $tokensBefore, $costBefore);
        $this->printCompactionNotice($conversation, $output, $compactionTreeSizeBefore);
        if ($renderOptions->showCapabilities) {
            $this->printCapabilitiesSnapshot($conversation, $output);
        }
        if ($renderOptions->showContextComposition) {
            $this->printContextComposition($conversation, $output);
        }
        // the console has no message consumer: the curation runs here after every stored turn, --memory only prints it
        if (!$runOptions->ephemeral && $runOptions->sideWorkEnabled) {
            $this->curateMemoryAfterTurn($conversation, $output, $renderOptions);
        }
        if ($renderOptions->htmlPath !== null) {
            $this->writeHtmlTranscript($conversation, $renderOptions->htmlPath, $output);
        }
    }

    /**
     * Appends the owner's message through the conversation (it carries the loaded skills forward onto the new row) and,
     * on a stored conversation, stores it through the messages service and embeds it inline unless the side work is
     * off. Control bytes and invalid UTF-8 of terminal input are removed first: one invalid byte fails the encoding of
     * the wire request.
     *
     * @param AIConversation $conversation
     * @param string $message
     * @param AgentConsoleChatRunOptions $runOptions
     * @return AIConversationMessage the appended message
     * @throws Throwable
     */
    protected function appendOwnerMessage(AIConversation $conversation, string $message, AgentConsoleChatRunOptions $runOptions): AIConversationMessage
    {
        $cleanMessage = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_convert_encoding($message, 'UTF-8', 'UTF-8'));
        $userMessage = $conversation->appendMessage(
            AIConversationMessage::newInstance(),
            AIConversationMessage::MESSAGE_TEXT,
            $cleanMessage,
            role: AIConversationMessage::ROLE_USER,
            authorType: AIConversationMessage::AUTHOR_USER
        );
        if ($runOptions->ephemeral || !isset($conversation->id)) {
            return $userMessage;
        }
        $userMessage->aiConversationId = (int)$conversation->id;
        $userMessage->aiConversation = $conversation;
        $aIConversationMessagesService = $this->getAIConversationMessagesService();
        $aIConversationMessagesService->persistInboundRowIndexSafe($conversation, $userMessage);
        if ($runOptions->sideWorkEnabled) {
            try {
                $aIConversationMessagesService->embedMessage($userMessage, async: false);
            } catch (Throwable) {
                // best effort: the message is stored, the embedding backfill catches a row without a vector
            }
        }
        return $userMessage;
    }

    /**
     * Dispatches the turn to the worker path. A finished delegation child gets its next run opened first (the claim
     * refuses a terminal child). The worker prints nothing here; inspect the conversation afterwards.
     *
     * @param AIConversation $conversation
     * @param string $message
     * @param OutputInterface $output
     * @return void
     * @throws Throwable
     */
    protected function dispatchTurnToWorkerPath(AIConversation $conversation, string $message, OutputInterface $output): void
    {
        $output->writeln('<fg=gray>↪ dispatching AIConversationResumeMessage (ai_conversation_turn)…</>');
        $aIConversationDelegationSpawnService = $this->getAIConversationDelegationSpawnService();
        $aIConversationDelegationSpawnService->openSubAgentContinuationRun($conversation, $message, DelegationSubAgentRun::CONTINUED_BY_CLI);
        $aIConversationAgentService = $this->getAIConversationAgentService();
        $aIConversationAgentService->resumeConversationIfReady($conversation, async: true);
        $output->writeln('<fg=gray>↩ dispatch returned (sync:// = handler ran inline; rabbitmq = backgrounded)</>');
    }

    /**
     * Drives the turn inline inside the turn envelope of the conversation's account
     * ({@see generateAgentReplyInsideTurnEnvelope()}); the printer streams every message as it is produced. A failed
     * turn is printed, not thrown.
     *
     * @param AIConversation $conversation
     * @param AgentConsoleChatRunOptions $runOptions
     * @param AgentConsoleChatRenderOptions $renderOptions
     * @param OutputInterface $output
     * @return void
     */
    protected function driveTurnInline(AIConversation $conversation, AgentConsoleChatRunOptions $runOptions, AgentConsoleChatRenderOptions $renderOptions, OutputInterface $output): void
    {
        $turnStart = hrtime(true);
        try {
            $this->generateAgentReplyInsideTurnEnvelope($conversation, $output, $runOptions, $renderOptions);
        } catch (Throwable $turnError) {
            $output->writeln("<error>turn failed: {$turnError->getMessage()}</error>");
        }
        if ($renderOptions->timings) {
            $output->writeln(sprintf('<fg=gray>⏱  turn total  %.3fs</>', (hrtime(true) - $turnStart) / 1e9));
        }
    }

    /**
     * Curates the memory from the turn the stored conversation just answered, inline inside the memory-curation
     * envelope (the console has no worker for it); the curation call is {@see AgentMemoriesService::curateConversationAfterTurn()}.
     * A failure never fails the turn; with `--memory` it is printed. An application whose curation reports what it
     * decided overrides this method to print that.
     *
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @param AgentConsoleChatRenderOptions $renderOptions
     * @return void
     */
    protected function curateMemoryAfterTurn(AIConversation $conversation, OutputInterface $output, AgentConsoleChatRenderOptions $renderOptions): void
    {
        try {
            $usageEnvelopeContext = $this->openUsageEnvelope($conversation->accountId ?? null, AgentAIUsageScope::ENVELOPE_AI_MEMORY_CURATE);
            try {
                $agentMemoriesService = $this->getAgentMemoriesService();
                $agentMemoriesService->curateConversationAfterTurn($conversation, false);
            } finally {
                $this->closeUsageEnvelope($usageEnvelopeContext);
            }
        } catch (Throwable $curationError) {
            if ($renderOptions->showMemoryCuration) {
                $output->writeln("<fg=red>🧠 memory curate failed: {$curationError->getMessage()}</>");
            }
        }
    }

    /**
     * Reports that the compaction tree grew during the turn: its node count, the covered message range and the top
     * level.
     *
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @param int $compactionTreeSizeBefore
     * @return void
     */
    protected function printCompactionNotice(AIConversation $conversation, OutputInterface $output, int $compactionTreeSizeBefore): void
    {
        $compactionNodes = isset($conversation->compactionNodes) ? $conversation->compactionNodes->getElements() : [];
        if (count($compactionNodes) <= $compactionTreeSizeBefore) {
            return;
        }
        $topLevel = 0;
        foreach ($compactionNodes as $compactionNode) {
            $topLevel = max($topLevel, $compactionNode->level ?? 0);
        }
        $coveredUpToIndex = $conversation->getCompactionCoveredUpToIndex();
        $output->writeln(sprintf(
            '<fg=cyan>🗜 compaction tree grew — %d nodes (was %d), covers messages 0–%s, top level %d</>',
            count($compactionNodes),
            $compactionTreeSizeBefore,
            $coveredUpToIndex !== null ? (string)$coveredUpToIndex : '?',
            $topLevel
        ));
    }

    /**
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @return void
     */
    protected function printFooter(AIConversation $conversation, OutputInterface $output): void
    {
        $conversationId = $conversation->id ?? null;
        $output->writeln($conversationId !== null
            ? "\n<fg=gray>conversation: $conversationId  (resume: --conversationId=$conversationId)</>"
            : "\n<fg=gray>conversation: ephemeral (not persisted)</>");
    }

    /**
     * One line after each turn: the turn's tokens and cost, the running totals and the loaded skills.
     *
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @param int $tokensBefore
     * @param float $costBefore
     * @return void
     */
    protected function printTurnMetrics(AIConversation $conversation, OutputInterface $output, int $tokensBefore, float $costBefore): void
    {
        $totalTokens = $conversation->messages?->getTotalTokens() ?? 0;
        $totalCostUsd = $conversation->messages?->getTotalCostUsd() ?? 0.0;
        $loadedSkillNames = implode(', ', $conversation->messages?->getLoadedSkillNames() ?? []);
        $output->writeln(sprintf(
            '<fg=gray>· turn: %d tok · $%.4f  ·  total: %d tok · $%.4f  ·  skills [%s]</>',
            $totalTokens - $tokensBefore,
            $totalCostUsd - $costBefore,
            $totalTokens,
            $totalCostUsd,
            $loadedSkillNames
        ));
    }

    /**
     * Prints the eviction snapshot of the capabilities loaded on the newest message: the resident tokens of skills and
     * tools against the soft target and the hard ceiling, what was loaded and evicted since the turn before, and the
     * telemetry of every capability as JSON. Reads memory only, so it works on an ephemeral run.
     *
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @return void
     */
    protected function printCapabilitiesSnapshot(AIConversation $conversation, OutputInterface $output): void
    {
        $loadedCapabilities = $conversation->messages?->last()?->getLoadedCapabilities();
        if ($loadedCapabilities === null) {
            $output->writeln('<fg=gray>· capabilities: none loaded yet</>');
            $this->previousCapabilityNames = [];
            return;
        }
        $contextRetentionSettings = $conversation->contextRetentionSettings ?? ContextRetentionSettings::newInstance();
        $evictionSnapshot = $loadedCapabilities->buildEvictionSnapshot(
            $conversation->getLatestContextInputTokens() ?? 0,
            $conversation->buildOwnerTurns()->countOwnerTurns(),
            $contextRetentionSettings
        );
        $currentCapabilityNames = $evictionSnapshot->getCapabilityNames();
        $previousCapabilityNames = $this->previousCapabilityNames ?? [];
        $evictedCapabilityNames = array_values(array_diff($previousCapabilityNames, $currentCapabilityNames));
        $newlyLoadedCapabilityNames = array_values(array_diff($currentCapabilityNames, $previousCapabilityNames));
        $this->previousCapabilityNames = $currentCapabilityNames;

        $softTargetTokens = $contextRetentionSettings->pressureBudgetTokens ?? LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS;
        $hardCapTokens = $contextRetentionSettings->hardCapTokens ?? LoadedCapability::DEFAULT_HARD_CAP_TOKENS;
        $output->writeln(sprintf(
            '<fg=cyan>· capabilities turn %d</>: skillTok %d · toolTok %d · <options=bold>Σ %d</> (soft %d / hard %d%s)',
            $evictionSnapshot->turn,
            $evictionSnapshot->skillTokens,
            $evictionSnapshot->toolTokens,
            $evictionSnapshot->totalResidentTokens,
            $softTargetTokens,
            $hardCapTokens,
            $evictionSnapshot->totalResidentTokens > $hardCapTokens ? ' <fg=yellow>OVER</>' : ''
        ));
        if ($newlyLoadedCapabilityNames !== []) {
            $output->writeln('  <fg=green>✨ loaded:</> ' . implode(', ', $newlyLoadedCapabilityNames));
        }
        if ($evictedCapabilityNames !== []) {
            $output->writeln('  <fg=red>🗑 evicted:</> ' . implode(', ', $evictedCapabilityNames));
        }
        $output->writeln('<fg=gray>' . (json_encode($evictionSnapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . '</>');
    }

    /**
     * Prints the context composition of the newest model call (the newest message with a request snapshot): the
     * labelled, token-sized segments of the system parts, the tool schemas and the wire messages, rebuilt with the base
     * prompt and the tool schemas the call had. Reads memory only, so it works on an ephemeral run.
     *
     * @param AIConversation $conversation
     * @param OutputInterface $output
     * @return void
     * @throws Throwable
     */
    protected function printContextComposition(AIConversation $conversation, OutputInterface $output): void
    {
        $producingMessage = null;
        foreach ($conversation->messages?->getElements() ?? [] as $candidateMessage) {
            if (!isset($candidateMessage->requestSnapshot)) {
                continue;
            }
            if ($producingMessage === null || ($candidateMessage->indexInConversation ?? -1) > ($producingMessage->indexInConversation ?? -1)) {
                $producingMessage = $candidateMessage;
            }
        }
        if ($producingMessage === null) {
            $output->writeln('<fg=gray>── context composition: no model-call message with a request snapshot yet ──</>');
            return;
        }
        $aIConversationModelCallsService = $this->getAIConversationModelCallsService();
        $modelCallComposition = $producingMessage->buildContextComposition(
            $conversation,
            $aIConversationModelCallsService->resolveBaseSystemPrompt($conversation),
            $aIConversationModelCallsService->buildToolSchemasForMessage($conversation, $producingMessage)
        );
        if ($modelCallComposition->isUnavailable) {
            $output->writeln('<fg=gray>── context composition: unavailable (no request snapshot) ──</>');
            return;
        }
        $output->writeln(sprintf(
            "\n<fg=cyan>── Context composition · message #%s · the model call that produced the last reply ──</>",
            (string)($producingMessage->indexInConversation ?? '?')
        ));
        $this->printContextSegmentGroup($output, 'SYSTEM', $modelCallComposition->systemSegments);
        $this->printContextSegmentGroup($output, 'TOOLS', $modelCallComposition->toolSchemaSegments);
        $this->printContextSegmentGroup($output, 'MESSAGES', $modelCallComposition->messageSegments);
        $reconstructionNote = $modelCallComposition->isReconstructed
            ? ' · <fg=yellow>reconstructed (may differ from sent — prompt/data/memory drift since)</>'
            : '';
        $output->writeln(sprintf('<fg=cyan>  Σ ≈ %d tok%s</>', $modelCallComposition->getTotalTokens(), $reconstructionNote));
    }

    /**
     * @param OutputInterface $output
     * @param string $groupTitle
     * @param AgentContextSegment[] $contextSegments
     * @return void
     */
    protected function printContextSegmentGroup(OutputInterface $output, string $groupTitle, array $contextSegments): void
    {
        if ($contextSegments === []) {
            return;
        }
        $output->writeln("<fg=gray>{$groupTitle}:</>");
        foreach ($contextSegments as $contextSegment) {
            $output->writeln(sprintf(
                '  %-20s %-5s %s%5d tok   %s',
                $contextSegment->type->value,
                $contextSegment->sourceMessageIndex !== null ? '#' . $contextSegment->sourceMessageIndex : '',
                $contextSegment->tokenCountIsEstimate ? '≈' : ' ',
                $contextSegment->tokenCount ?? 0,
                $contextSegment->getLabel()
            ));
        }
    }

    /**
     * Writes the self-contained HTML transcript of the conversation to `$htmlPath`, again after every turn.
     *
     * @param AIConversation $conversation
     * @param string $htmlPath
     * @param OutputInterface $output
     * @return void
     */
    protected function writeHtmlTranscript(AIConversation $conversation, string $htmlPath, OutputInterface $output): void
    {
        $htmlTranscript = (new ConversationHtmlTranscript())->render($conversation);
        if (file_put_contents($htmlPath, $htmlTranscript) === false) {
            $output->writeln("<error>could not write HTML transcript to $htmlPath</error>");
            return;
        }
        $output->writeln("<fg=gray>· html transcript: $htmlPath</>");
    }

    /** @return AgentMessagePrinterInterface the printer every message of a turn is streamed through */
    protected function getAgentMessagePrinter(): AgentMessagePrinterInterface
    {
        /** @var AgentMessagePrinterInterface $agentMessagePrinter */
        $agentMessagePrinter = DDDService::instance()->getService(AgentMessagePrinterInterface::class);
        return $agentMessagePrinter;
    }

    /** @return AIConversationDelegationSpawnService */
    protected function getAIConversationDelegationSpawnService(): AIConversationDelegationSpawnService
    {
        /** @var AIConversationDelegationSpawnService $aIConversationDelegationSpawnService */
        $aIConversationDelegationSpawnService = DDDService::instance()->getService(AIConversationDelegationSpawnService::class);
        return $aIConversationDelegationSpawnService;
    }

    /** @return AgentRecordedRunsService */
    protected function getAgentRecordedRunsService(): AgentRecordedRunsService
    {
        /** @var AgentRecordedRunsService $agentRecordedRunsService */
        $agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);
        return $agentRecordedRunsService;
    }

    /** @return AIConversationModelCallsService */
    protected function getAIConversationModelCallsService(): AIConversationModelCallsService
    {
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        return $aIConversationModelCallsService;
    }

    /** @return AgentMemoriesService */
    protected function getAgentMemoriesService(): AgentMemoriesService
    {
        /** @var AgentMemoriesService $agentMemoriesService */
        $agentMemoriesService = DDDService::instance()->getService(AgentMemoriesService::class);
        return $agentMemoriesService;
    }
}
