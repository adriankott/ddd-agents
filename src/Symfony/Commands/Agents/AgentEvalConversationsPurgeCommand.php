<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSampleRoot;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Deletes the conversations of the evaluation accounts whose last activity is older than `--older-than-hours` (48),
 * with every dependent row — politely, resumable, under a time budget; see {@see AIConversationEvalPurgeService}. Dry
 * run by default (rows per table and estimated bytes), `--apply` writes.
 *
 * Runs as a cron. A ticker that kills its process after 360 s still lets a run finish: the budget is capped at
 * {@see AIConversationEvalPurgeSettings::MAXIMUM_SECONDS}; what is left is deleted by the next run:
 *
 *   php bin/console app:agent:eval-conversations-purge --apply
 *
 * An account that also holds the owner's own conversations gives up only its evaluation-marked trees — dry run first:
 *
 *   php bin/console app:agent:eval-conversations-purge --accounts=<id>,<id> --evalOnly --older-than-hours=1
 *
 * The command only parses its options, calls the service and renders the report's lines and table cells.
 */
#[AsCommand(
    name: 'app:agent:eval-conversations-purge',
    aliases: ['app:ai-agent:eval-conversations-purge'],
    description: 'Politely delete the eval-account conversations (and every dependent row) whose last activity is older than N hours'
)]
class AgentEvalConversationsPurgeCommand extends AgentConsoleCommand
{
    /** @var string The memory limit of a run */
    public const string MEMORY_LIMIT = '512M';

    /** @var int The PHP time limit of a run (seconds); the purge's own budget is far below it */
    public const int TIME_LIMIT_SECONDS = 3600;

    protected function configure(): void
    {
        $settingsDefaults = AIConversationEvalPurgeSettings::newInstance();
        $this
            ->addOption('older-than-hours', null, InputOption::VALUE_REQUIRED, 'Delete trees whose newest activity is older than this many hours', (string)$settingsDefaults->olderThanHours)
            ->addOption('fresh-heartbeat-minutes', null, InputOption::VALUE_REQUIRED, 'A run heartbeat younger than this keeps the tree', (string)$settingsDefaults->freshHeartbeatMinutes)
            ->addOption('trees-per-batch', null, InputOption::VALUE_REQUIRED, 'Root conversations checked and deleted together', (string)$settingsDefaults->treesPerBatch)
            ->addOption('heavy-rows-per-statement', null, InputOption::VALUE_REQUIRED, 'Row limit of one write on the large-row tables (messages, embeddings, compaction nodes)', (string)$settingsDefaults->heavyRowsPerStatement)
            ->addOption('light-rows-per-statement', null, InputOption::VALUE_REQUIRED, 'Row limit of one write on every other table', (string)$settingsDefaults->lightRowsPerStatement)
            ->addOption('pause-ms', null, InputOption::VALUE_REQUIRED, 'Base pause after every write (doubles while writes are slow)', (string)$settingsDefaults->pauseMs)
            ->addOption('slow-write-ms', null, InputOption::VALUE_REQUIRED, 'A write slower than this doubles the pause', (string)$settingsDefaults->slowWriteMs)
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Time budget of this run, capped at ' . AIConversationEvalPurgeSettings::MAXIMUM_SECONDS . ' s (the cron executor kills at 360 s)', (string)$settingsDefaults->maxSeconds)
            ->addOption('max-threads-running', null, InputOption::VALUE_REQUIRED, 'MariaDB only: wait while more statements than this execute on the server', (string)$settingsDefaults->maxThreadsRunning)
            ->addOption('max-history-list-length', null, InputOption::VALUE_REQUIRED, 'MariaDB only: wait while the InnoDB purge backlog is longer than this', (string)$settingsDefaults->maxHistoryListLength)
            ->addOption('after-root-conversation-id', null, InputOption::VALUE_REQUIRED, 'Start after this root conversation id')
            ->addOption('only-root-conversation-id', null, InputOption::VALUE_REQUIRED, 'Only this root conversation (still checked like every other)')
            ->addOption('accounts', null, InputOption::VALUE_REQUIRED, 'Comma list of account ids instead of the configured eval accounts; an account that is no configured eval account needs --evalOnly')
            ->addOption('evalOnly', null, InputOption::VALUE_NONE, $this->getEvalOnlyOptionDescription())
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write — without it the run only counts');
    }

    /** The help text of `--evalOnly`; an application names its own markers here. */
    protected function getEvalOnlyOptionDescription(): string
    {
        return 'Only conversation trees whose root carries an eval marker (runSettings.evalRun / evalSeededHistory, or a marker of the application) — the owner\'s own conversations of the account stay';
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * Prints the accounts line, runs the purge with the progress lines of every batch, then the step, summary, account
     * and sample tables and the result line.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', static::MEMORY_LIMIT);
        set_time_limit(static::TIME_LIMIT_SECONDS);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $settings = $this->buildSettingsFromInput($input);
        try {
            $aIConversationEvalPurgeService = $this->getEvalPurgeService();
            $symfonyStyle->writeln($settings->buildAccountsLine($aIConversationEvalPurgeService->resolveAccountIdsToPurge($settings)));
            $report = $aIConversationEvalPurgeService->purgeEvalConversations($settings, $this->getProgressReporter());
            $this->renderReportTables($report, $output);
            $symfonyStyle->success($report->buildResultLine());
            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            $symfonyStyle->error($throwable->getMessage());
            return Command::FAILURE;
        }
    }

    /** The step table, the summary table, the account table and — when the run kept samples — the sample table. */
    protected function renderReportTables(AIConversationEvalPurgeReport $report, OutputInterface $output): void
    {
        $stepTable = new Table($output);
        $stepTable->setHeaders($report->buildStepTableHeaders());
        foreach (array_keys($report->rowsByStep) as $stepLabel) {
            $stepTable->addRow($report->buildStepTableCells((string)$stepLabel));
        }
        $stepTable->addRow($report->buildStepTableTotalCells());
        $stepTable->render();

        $summaryTable = new Table($output);
        $summaryTable->setHeaders($report->buildSummaryTableHeaders());
        foreach ($report->buildSummaryValuesByName() as $summaryName => $summaryValue) {
            $summaryTable->addRow([(string)$summaryName, $summaryValue]);
        }
        $summaryTable->render();

        $accountTable = new Table($output);
        $accountTable->setHeaders($report->buildAccountTableHeaders());
        foreach (array_keys($report->treesPurgedByAccountId) as $purgedAccountId) {
            $accountTable->addRow($report->buildAccountTableCells((int)$purgedAccountId));
        }
        $accountTable->render();

        if ($report->sampleRootConversations->count() === 0) {
            return;
        }
        $sampleTable = new Table($output);
        $sampleTable->setHeaders(AIConversationEvalPurgeSampleRoot::TABLE_HEADERS);
        foreach ($report->sampleRootConversations->getElements() as $sampleRoot) {
            $sampleTable->addRow($sampleRoot->buildTableCells());
        }
        $sampleTable->render();
    }

    /** The settings of the run from the options; every number is held to its lower bound. */
    protected function buildSettingsFromInput(InputInterface $input): AIConversationEvalPurgeSettings
    {
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->olderThanHours = max(1, (int)$input->getOption('older-than-hours'));
        $settings->freshHeartbeatMinutes = max(1, (int)$input->getOption('fresh-heartbeat-minutes'));
        $settings->treesPerBatch = max(1, (int)$input->getOption('trees-per-batch'));
        $settings->heavyRowsPerStatement = max(1, (int)$input->getOption('heavy-rows-per-statement'));
        $settings->lightRowsPerStatement = max(1, (int)$input->getOption('light-rows-per-statement'));
        $settings->pauseMs = max(0, (int)$input->getOption('pause-ms'));
        $settings->slowWriteMs = max(1, (int)$input->getOption('slow-write-ms'));
        $settings->maxSeconds = max(1, (int)$input->getOption('max-seconds'));
        $settings->maxThreadsRunning = max(1, (int)$input->getOption('max-threads-running'));
        $settings->maxHistoryListLength = max(1, (int)$input->getOption('max-history-list-length'));
        $afterRootConversationIdOption = $input->getOption('after-root-conversation-id');
        $settings->afterRootConversationId = $afterRootConversationIdOption !== null ? (int)$afterRootConversationIdOption : null;
        $onlyRootConversationIdOption = $input->getOption('only-root-conversation-id');
        $settings->onlyRootConversationId = $onlyRootConversationIdOption !== null ? (int)$onlyRootConversationIdOption : null;
        $accountsOption = trim((string)($input->getOption('accounts') ?? ''));
        $settings->accountIds = $accountsOption !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $accountsOption))))
            : null;
        $settings->evalOnly = (bool)$input->getOption('evalOnly');
        $settings->dryRun = !(bool)$input->getOption('apply');
        return $settings;
    }

    /** The purge service (the application's class, when it replaces it). */
    protected function getEvalPurgeService(): AIConversationEvalPurgeService
    {
        /** @var AIConversationEvalPurgeService $aIConversationEvalPurgeService */
        $aIConversationEvalPurgeService = DDDService::instance()->getService(AIConversationEvalPurgeService::class);
        return $aIConversationEvalPurgeService;
    }
}
