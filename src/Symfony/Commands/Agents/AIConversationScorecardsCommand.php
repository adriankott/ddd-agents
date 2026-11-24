<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Quality\AgentConversationScorecardSelection;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\MessageHandlers\AgentMessengerTransports;
use DDD\Domain\AI\MessageHandlers\AIConversationScorecardMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService;
use DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Throwable;

/**
 * The backfill, reconciliation and inspection of the conversation scorecards. By default it only SELECTS: one ids-only
 * {@see AIConversationScorecardMessage} per conversation goes to the scorecard worker, which writes the scorecard
 * ({@see AgentConversationScorecardsService::writeScorecardOfFinalizedConversation()}) — the same path as the
 * finalize chain. The selection is the root conversations created since `--since` (or the ids of `--conversation`),
 * read by {@see AgentConversationScorecardsService::findConversationIdsForSelection()}; a conversation that is no root
 * or belongs to a purpose hidden from the owner is skipped, an eval run too unless `--eval-runs`.
 *
 * - `--missing` is the regular run a cron starts every few minutes: the OPEN conversations the quality component names
 *   ({@see findOpenConversationIds()}; none in the module, which stores no scorecard), at most as many as fill the
 *   scorecard queue up to `--limit` — it also drains a backfill in small batches.
 * - `--inline` does the work in this process (debugging): {@see analyzeConversation()}, by default the scorecard write
 *   of the worker under the compaction envelope of the finalize chain, as the conversation's account.
 * - `--print` computes the conversation metrics ({@see AIConversationMetrics}) and prints one row per conversation
 *   ({@see buildPrintedRow()}) without writing.
 *
 * Without an application's quality component the module writes no scorecard: a dispatched or inline run writes
 * nothing, `--missing` finds nothing, and `--print` prints the metrics with the purpose, the entry point, the
 * initiator type, the eval marker and the run state of each conversation. Idempotent.
 *
 *   php bin/console app:agent:conversation-scorecards --print --since=2026-01-01
 *   php bin/console app:agent:conversation-scorecards --conversation=123 --inline
 *   php bin/console app:agent:conversation-scorecards --missing --limit=50
 */
#[AsCommand(
    name: 'app:agent:conversation-scorecards',
    aliases: ['app:ai-conversation:scorecards'],
    description: 'Backfill, reconcile (--missing) or print (--print) the conversation scorecards from the persisted messages.'
)]
class AIConversationScorecardsCommand extends AgentConsoleCommand
{
    /** @var int The default of `--limit` */
    public const int DEFAULT_LIMIT = 500;

    /** @var string The `--purpose` value that selects the regular chats (no purpose) */
    public const string PURPOSE_NONE = 'none';

    /** @var string[] The headings of the metrics columns of the `--print` table ({@see buildMetricsCells()}) */
    public const array METRICS_COLUMN_HEADINGS = [
        'wall s', 'active s', 'agent s', 'owner s', 'away s', 'idle s', 'model s (unstamped)', 'overhead s',
        'p50 / p90 turn s', 'owner turns (text)', 'first answer s',
    ];

    /**
     * @param TransportInterface|null $scorecardTransport the scorecard transport, read for the count of queued
     *                                                    messages; null when the application has not configured it
     */
    public function __construct(
        #[Autowire(service: 'messenger.transport.' . AgentMessengerTransports::SCORECARD)]
        protected readonly ?TransportInterface $scorecardTransport = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, $this->describeOption('since'), date('Y-m-d', strtotime('-30 days')))
            ->addOption('conversation', null, InputOption::VALUE_REQUIRED, $this->describeOption('conversation'))
            ->addOption('purpose', null, InputOption::VALUE_REQUIRED, $this->describeOption('purpose'))
            ->addOption('missing', null, InputOption::VALUE_NONE, $this->describeOption('missing'))
            ->addOption('print', null, InputOption::VALUE_NONE, $this->describeOption('print'));
        $this->configureAnalysisOptions();
        $this
            ->addOption('eval-runs', null, InputOption::VALUE_NONE, $this->describeOption('eval-runs'))
            ->addOption('internal-accounts', null, InputOption::VALUE_NONE, $this->describeOption('internal-accounts'))
            ->addOption('inline', null, InputOption::VALUE_NONE, $this->describeOption('inline'))
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, $this->describeOption('limit'), (string)self::DEFAULT_LIMIT)
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, $this->describeOption('shard'));
    }

    /**
     * Override point: declares the options of the analysis, between `--print` and `--eval-runs`. The default declares
     * `--force`, which the scorecard write receives.
     *
     * @return void
     */
    protected function configureAnalysisOptions(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, $this->describeOption('force'));
    }

    /**
     * Override point: the help text of an option (an application names what its quality component does).
     *
     * @param string $optionName
     * @return string
     */
    protected function describeOption(string $optionName): string
    {
        return match ($optionName) {
            'since' => 'Conversations created on or after this date (Y-m-d)',
            'conversation' => 'One conversation id, or a comma-separated list, instead of the date window',
            'purpose' => "Comma-separated purpose names; '" . self::PURPOSE_NONE . "' = regular chats; omitted = all",
            'missing' => 'The regular run (cron): the OPEN conversations the quality component names, topping the scorecard queue up to --limit (none without a quality component)',
            'print' => 'Compute and print, write nothing',
            'force' => 'Analyze again even when the analysis covers the newest message',
            'eval-runs' => 'Include the eval harness runs',
            'internal-accounts' => 'Include the conversations of internal accounts (test and staff accounts), which the selection leaves out when the application names them',
            'inline' => 'Write in THIS process (debugging) instead of dispatching one scorecard message per conversation to the workers',
            'limit' => 'At most this many conversations',
            'shard' => 'N/M: only the conversations of shard N of M (by a hash of the id) — parallel backfill processes never overlap',
            default => '',
        };
    }

    /** The run works as the configured default account for console work, as the scorecard backfill always did. */
    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * Selects the conversations and writes, dispatches or prints their scorecards.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(3600);
        $symfonyStyle = new SymfonyStyle($input, $output);
        if ((bool)$input->getOption('missing')) {
            // the regular run: top the scorecard queue up to --limit — a run that finds the workers still busy sends
            // nothing, so the same conversation is never queued twice and every run stays small
            $queuedCount = (bool)$input->getOption('print') ? 0 : $this->findQueuedScorecardMessageCount();
            $openSlots = (int)$input->getOption('limit') - $queuedCount;
            if ($openSlots <= 0) {
                $symfonyStyle->note("{$queuedCount} scorecards still queued — nothing dispatched.");
                return Command::SUCCESS;
            }
            $conversationIds = $this->findOpenConversationIds($input, $openSlots, $queuedCount);
        } else {
            $conversationIds = $this->findConversationIds($input);
        }
        $conversationIds = $this->filterShard($conversationIds, $input->getOption('shard'));
        if ($conversationIds === []) {
            $symfonyStyle->note('No conversation in the selection.');
            return Command::SUCCESS;
        }
        $runTally = new AIConversationScorecardsRunTally();
        $symfonyStyle->progressStart(count($conversationIds));
        foreach ($conversationIds as $conversationId) {
            $symfonyStyle->progressAdvance();
            try {
                $this->scoreConversation($conversationId, $input, $runTally);
            } catch (Throwable $conversationError) {
                $symfonyStyle->warning("Conversation {$conversationId}: " . $conversationError->getMessage());
            }
        }
        $symfonyStyle->progressFinish();
        $this->printRunResult($input, $symfonyStyle, $runTally);
        return Command::SUCCESS;
    }

    /**
     * Prints, dispatches or writes the scorecard of one conversation and counts it in `$runTally`.
     *
     * @param int $conversationId
     * @param InputInterface $input
     * @param AIConversationScorecardsRunTally $runTally
     * @return void
     * @throws Throwable the run prints it as a warning and goes on with the next conversation
     */
    protected function scoreConversation(int $conversationId, InputInterface $input, AIConversationScorecardsRunTally $runTally): void
    {
        $aIConversationsService = $this->getAIConversationsService();
        $conversation = $aIConversationsService->find($conversationId, useEntityRegistrCache: false);
        if (!$conversation instanceof AIConversation || !$this->isConversationScored($conversation)) {
            $runTally->countSkipped();
            return;
        }
        $isEvalRun = $this->isEvalRunConversation($conversation);
        if ($isEvalRun && !(bool)$input->getOption('eval-runs')) {
            $runTally->countSkipped();
            return;
        }
        if ((bool)$input->getOption('print')) {
            $aIConversationMetricsService = $this->getMetricsService();
            $metrics = $aIConversationMetricsService->buildMetricsForConversation($conversation);
            $runTally->addPrintedRow($this->buildPrintedRow($conversation, $metrics, $isEvalRun));
            return;
        }
        if (!(bool)$input->getOption('inline')) {
            // the work runs in the scorecard worker, like the finalize chain's own dispatch
            $this->dispatchScorecardMessage($conversation, (bool)$input->getOption('force'));
            $runTally->countWritten();
            return;
        }
        $runTally->countAnalysisOutcome($this->analyzeConversation($conversation, $input));
        $runTally->countWritten();
    }

    /**
     * Prints the `--print` table, or the line that counts what was written, dispatched and skipped.
     *
     * @param InputInterface $input
     * @param SymfonyStyle $symfonyStyle
     * @param AIConversationScorecardsRunTally $runTally
     * @return void
     */
    protected function printRunResult(InputInterface $input, SymfonyStyle $symfonyStyle, AIConversationScorecardsRunTally $runTally): void
    {
        if ((bool)$input->getOption('print')) {
            $symfonyStyle->table($this->getPrintedTableHeadings(), $runTally->printedRows);
            return;
        }
        $symfonyStyle->success(
            "{$runTally->writtenCount} scorecards " . ((bool)$input->getOption('inline') ? 'written' : 'dispatched to the workers')
            . ", {$runTally->skippedCount} skipped (hidden, delegation or eval runs — --eval-runs includes the eval runs)."
            . $runTally->describeAnalysisOutcomes()
        );
    }

    /**
     * Override point: the ids of the OPEN conversations of the regular run (`--missing`) — the conversations whose
     * scorecard is missing or older than their messages — at most `$openSlots`, skipping the first `$queuedCount`
     * (what a previous run queued and the workers have not taken yet). The module stores no scorecard, so the
     * default finds none; an application with a quality component reads its own rows.
     *
     * @param InputInterface $input the run's options (`--since`, `--internal-accounts`)
     * @param int $openSlots
     * @param int $queuedCount
     * @return int[]
     * @throws Throwable
     */
    protected function findOpenConversationIds(InputInterface $input, int $openSlots, int $queuedCount): array
    {
        return [];
    }

    /**
     * Override point: the scorecard work of one conversation in this process (`--inline`). The default does what the
     * scorecard worker does: the scorecard write of {@see AgentConversationScorecardsService} with `--force`, inside
     * the compaction envelope of the finalize chain, as the conversation's account.
     *
     * @param AIConversation $conversation
     * @param InputInterface $input
     * @return string|null the analysis outcome to count; null when no analysis ran
     * @throws Throwable
     */
    protected function analyzeConversation(AIConversation $conversation, InputInterface $input): ?string
    {
        $agentConversationScorecardsService = $this->getScorecardsService();
        $accountOfRun = AuthService::instance()->getAccount();
        try {
            $this->actAsAccount($this->findAccountOfConversation($conversation));
            $usageEnvelopeContext = $this->openUsageEnvelope(
                $conversation->accountId ?? null,
                AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_COMPACTION,
                AIUsageInitiator::ACCOUNT
            );
            try {
                return $agentConversationScorecardsService->writeScorecardOfFinalizedConversation($conversation, (bool)$input->getOption('force'));
            } finally {
                $this->closeUsageEnvelope($usageEnvelopeContext);
            }
        } finally {
            $this->restoreAccountOfBeforeRun($accountOfRun);
        }
    }

    /**
     * Override point: the cells of one `--print` row, in the order of {@see getPrintedTableHeadings()}. The default:
     * id, purpose, entry point, initiator type, eval marker, run state, then the metrics ({@see buildMetricsCells()}).
     *
     * @param AIConversation $conversation
     * @param AIConversationMetrics|null $metrics null when the conversation has no rows
     * @param bool $isEvalRun
     * @return string[]
     */
    protected function buildPrintedRow(AIConversation $conversation, ?AIConversationMetrics $metrics, bool $isEvalRun): array
    {
        return array_merge([
            (string)$conversation->id,
            (string)($conversation->purposeName ?? ''),
            (string)($metrics->entryPoint ?? ''),
            (string)($conversation->initiatorType ?? ''),
            $isEvalRun ? 'yes' : '',
            $conversation->runState,
        ], $this->buildMetricsCells($metrics));
    }

    /**
     * Override point: the headings of the `--print` table, one per cell of {@see buildPrintedRow()}.
     *
     * @return string[]
     */
    protected function getPrintedTableHeadings(): array
    {
        return array_merge(['conversation', 'purpose', 'entry', 'initiator', 'eval', 'run state'], self::METRICS_COLUMN_HEADINGS);
    }

    /**
     * The metrics cells of a `--print` row, in the order of {@see self::METRICS_COLUMN_HEADINGS}: seconds rounded to
     * whole seconds, the model seconds with the count of model calls without an egress stamp in brackets when there
     * are any, the owner turns with the text turns in brackets. Every cell is empty without metrics.
     *
     * @param AIConversationMetrics|null $metrics
     * @return string[]
     */
    protected function buildMetricsCells(?AIConversationMetrics $metrics): array
    {
        $modelCallsWithoutEgressStamp = $metrics->modelCallsWithoutEgressStamp ?? 0;
        return [
            $this->formatSeconds($metrics?->totalWallSeconds),
            $this->formatSeconds($metrics?->activeWallSeconds),
            $this->formatSeconds($metrics?->agentSeconds),
            $this->formatSeconds($metrics?->ownerSeconds),
            $this->formatSeconds($metrics?->awaySeconds),
            $this->formatSeconds($metrics?->idleSeconds),
            $this->formatSeconds($metrics?->modelSeconds) . ($modelCallsWithoutEgressStamp > 0 ? " ({$modelCallsWithoutEgressStamp})" : ''),
            $this->formatSeconds($metrics?->platformOverheadSeconds),
            $this->formatSeconds($metrics?->p50TurnAgentSeconds) . ' / ' . $this->formatSeconds($metrics?->p90TurnAgentSeconds),
            ($metrics->ownerTurns ?? '') . ' (' . ($metrics->ownerTextTurns ?? '') . ')',
            $this->formatSeconds($metrics?->greetingLatencySeconds),
        ];
    }

    /**
     * @param float|null $seconds
     * @return string whole seconds; empty for null
     */
    protected function formatSeconds(?float $seconds): string
    {
        return $seconds === null ? '' : (string)round($seconds);
    }

    /**
     * Override point: whether the conversation gets a scorecard — a stored ROOT conversation that is no delegation and
     * no run of a purpose hidden from the owner. An application whose quality component decides this itself answers
     * with its rule.
     *
     * @param AIConversation $conversation
     * @return bool
     */
    protected function isConversationScored(AIConversation $conversation): bool
    {
        return isset($conversation->id)
            && !$conversation->isDelegation()
            && !AIConversationPurpose::isCustomerHiddenPurposeName($conversation->purposeName ?? null);
    }

    /**
     * @param AIConversation $conversation
     * @return bool whether the conversation is an eval run (`runSettings.evalRun` = true)
     */
    protected function isEvalRunConversation(AIConversation $conversation): bool
    {
        return (isset($conversation->runSettings) ? ($conversation->runSettings->evalRun ?? null) : null) === true;
    }

    /**
     * The ids of `--conversation`, or the root conversations of the selection the options name, oldest first.
     *
     * @param InputInterface $input
     * @return int[]
     * @throws Throwable
     */
    protected function findConversationIds(InputInterface $input): array
    {
        if ($input->getOption('conversation') !== null) {
            return array_values(array_filter(array_map('intval', explode(',', (string)$input->getOption('conversation')))));
        }
        $agentConversationScorecardsService = $this->getScorecardsService();
        return $agentConversationScorecardsService->findConversationIdsForSelection($this->buildSelectionFromInput($input));
    }

    /**
     * The selection `--since`, `--purpose`, `--eval-runs`, `--internal-accounts` and `--limit` name.
     *
     * @param InputInterface $input
     * @return AgentConversationScorecardSelection
     */
    protected function buildSelectionFromInput(InputInterface $input): AgentConversationScorecardSelection
    {
        $selection = AgentConversationScorecardSelection::createdSince((string)$input->getOption('since'), (int)$input->getOption('limit'));
        $purposeOption = $input->getOption('purpose');
        if ($purposeOption !== null) {
            $purposeNames = array_values(array_filter(array_map('trim', explode(',', (string)$purposeOption))));
            $selection->restrictToPurposes(
                array_values(array_diff($purposeNames, [self::PURPOSE_NONE])),
                in_array(self::PURPOSE_NONE, $purposeNames, true)
            );
        }
        $selection->includesEvalRuns = (bool)$input->getOption('eval-runs');
        $selection->includesInternalAccounts = (bool)$input->getOption('internal-accounts');
        return $selection;
    }

    /**
     * The ids of one shard `N/M` (crc32 of the id % M = N); all ids without the option or with an unreadable one.
     *
     * @param int[] $conversationIds
     * @param mixed $shardOption
     * @return int[]
     */
    protected function filterShard(array $conversationIds, mixed $shardOption): array
    {
        if (!is_string($shardOption) || !preg_match('#^(\d+)/(\d+)$#', $shardOption, $shardMatch) || (int)$shardMatch[2] < 1) {
            return $conversationIds;
        }
        $shardIndex = (int)$shardMatch[1];
        $shardCount = (int)$shardMatch[2];
        // by a hash, not by `id % M`: a database may hand out ids in steps (every id ≡ 2 mod 4), a plain modulo puts
        // everything into one shard
        return array_values(array_filter($conversationIds, static fn (int $conversationId): bool => crc32((string)$conversationId) % $shardCount === $shardIndex));
    }

    /**
     * One ids-only scorecard message for the worker, dispatched as the conversation's account (the worker restores
     * that account); the account of the run is authenticated again afterwards.
     *
     * @param AIConversation $conversation
     * @param bool $forceAnalysis
     * @return void
     * @throws Throwable
     */
    protected function dispatchScorecardMessage(AIConversation $conversation, bool $forceAnalysis): void
    {
        $accountOfRun = AuthService::instance()->getAccount();
        try {
            $this->actAsAccount($this->findAccountOfConversation($conversation));
            (new AIConversationScorecardMessage((int)$conversation->id, $forceAnalysis))->dispatch();
        } finally {
            $this->restoreAccountOfBeforeRun($accountOfRun);
        }
    }

    /**
     * The account of the conversation (loaded when the conversation names one); null without one.
     *
     * @param AIConversation $conversation
     * @return Account|null
     */
    protected function findAccountOfConversation(AIConversation $conversation): ?Account
    {
        return $conversation->account;
    }

    /**
     * The messages waiting on the scorecard transport; 0 when the transport cannot count or is not configured.
     *
     * @return int
     */
    protected function findQueuedScorecardMessageCount(): int
    {
        return $this->scorecardTransport instanceof MessageCountAwareInterface ? $this->scorecardTransport->getMessageCount() : 0;
    }

    /** @return AIConversationsService */
    protected function getAIConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        return $aIConversationsService;
    }

    /** @return AIConversationMetricsService */
    protected function getMetricsService(): AIConversationMetricsService
    {
        /** @var AIConversationMetricsService $aIConversationMetricsService */
        $aIConversationMetricsService = DDDService::instance()->getService(AIConversationMetricsService::class);
        return $aIConversationMetricsService;
    }

    /** @return AgentConversationScorecardsService */
    protected function getScorecardsService(): AgentConversationScorecardsService
    {
        /** @var AgentConversationScorecardsService $agentConversationScorecardsService */
        $agentConversationScorecardsService = DDDService::instance()->getService(AgentConversationScorecardsService::class);
        return $agentConversationScorecardsService;
    }
}
