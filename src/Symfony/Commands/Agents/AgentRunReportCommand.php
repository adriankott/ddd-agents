<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanSize;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReport;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReports;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\RunReports\AIConversationRunReportService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\RunReport\AgentRunReportTotals;
use DDD\Symfony\Commands\Agents\RunReport\AgentRunReportVerdict;
use DDD\Symfony\Commands\Agents\RunReport\AgentRunReportVerdicts;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The STANDARD per-run report of agent conversations: one row per run with every figure an optimization loop reads —
 * the judge verdict, the wall-clock seconds, the owner turns, the model calls and tool calls (refused / repeated), the
 * time split (agent seconds per turn, model, tools as SUM and as WALL, owner), the plan ticks and skill loads, the
 * agent model's and the decision model's cost, the final variants and the plan size — plus the slowest tool calls and
 * a total row. Read from the stored messages only ({@see AIConversationRunReportService}), so a run is reported the same
 * way whether it came from an evaluation or from a real owner.
 *
 * Time model: {@see AIConversationMetrics} — every gap between two rows is classified by its later row. The wall is
 * the ACTIVE wall (agent + owner seconds): an owner absence of more than ten minutes and a posting long after the turn
 * ended (a scheduled task) are not the run's work. The agent seconds are the gaps the platform was working; the owner
 * seconds are the owner's reading and typing (the simulated owner's thinking time in an evaluation); agent s/turn
 * divides by the execution turns. Tool WALL seconds: tool calls whose execution intervals overlap ran in parallel and
 * count once per group — the difference to the tool SUM is the parallel gain. `delegate` / `check_delegations` calls
 * are listed apart: they do not block the turn. The overhead is what remains of the agent seconds after model, tool
 * wall and delegation wait; a negative value means a stamp is missing.
 *
 * The judge verdict and findings are read from the evaluation logs when `--log-dir` is given: the log naming its
 * conversation id ("conversation started (id N)") carries the PASS/FAIL line and the VIOLATED/PARTIAL findings.
 *
 * Variants: every model call's request snapshot carries the resolved variants vector and the rendered skill section
 * ids. `--variants` prints a per-run TIMELINE — one row per change of a dimension (a changed evidence index `@N` alone
 * is no change), with the sections that call rendered and the tool called next — and the findings header carries the
 * compact initial → final delta.
 *
 * Plan size (report only): the most nodes and the deepest level of the plans in force, the tail tokens the model calls
 * carried (max / Σ, chars/4) — read from the per-message plan snapshots ({@see TaskPlanSize}).
 *
 * The report service is reached through the container, so an application's class of the same name at the mirrored path
 * (its usage-log cost, its prefix of skill section ids) is the one that runs. An application replaces the command by a
 * subclass that repeats `#[AsCommand]` with this name.
 *
 *   php bin/console app:ai-agent:run-report 4711 4712 --log-dir=<eval log folder> --findings --variants --markdown
 */
#[AsCommand(
    name: 'app:ai-agent:run-report',
    description: 'The standard per-run report: judge verdict + findings, wall seconds, owner turns, model/tool calls, time split with the parallel gain, refusals, slowest tools, suite total.'
)]
class AgentRunReportCommand extends AgentConsoleCommand
{
    /** @var int The slowest tools listed per run */
    public const int SLOWEST_TOOLS_SHOWN = AIConversationRunReport::SLOWEST_TOOLS_SHOWN;

    /** @var string The memory limit of a run */
    public const string MEMORY_LIMIT = '1024M';

    /** @var int The empty cells of the row of a run without a report, after its id and `no rows` */
    public const int EMPTY_CELLS_OF_RUN_WITHOUT_REPORT = 22;

    /** @var string[] The headers of the run table */
    public const array RUN_TABLE_HEADERS = ['run', 'case', 'judge', 'wall s', 'owner turns', 'model calls', 'tool calls', 'refused', 'repeats', 'agent s/turn', 'model s', 'overhead s', 'tool s Σ', 'tool s wall', 'driver s', 'ticks', 'loads', 'non-blocking', 'model $', 'decision $ (calls)', 'variants (final)', 'plan nodes', 'plan depth', 'plan tail tok (max / Σ)'];

    /** @var string[] The headers of the usage table (`--usage`) */
    public const array USAGE_TABLE_HEADERS = ['run', 'model calls', 'input tok', 'cached tok', 'cached %', 'cache write tok', 'output tok', 'output tok/call', 'thinking tok', 'thinking tok/call', 'dropped thinking blocks', 'model s/call', 'model $'];

    /** @var string[] The headers of the slowest-tools table */
    public const array SLOWEST_TOOLS_TABLE_HEADERS = ['run', 'tool', 'seconds Σ', 'calls', 'max s'];

    /** @var string[] The headers of a variants timeline table */
    public const array VARIANTS_TIMELINE_TABLE_HEADERS = ['run', 'at', 'dimension', 'from', 'to', 'sections then', 'tool after'];

    /** @var string The pattern of the log line that names the run's conversation */
    public const string LOG_CONVERSATION_ID_PATTERN = '/conversation started \(id (\d+)\)/';

    /** @var string The pattern of the verdict line: verdict, score, case */
    public const string LOG_VERDICT_PATTERN = '/(PASS|FAIL) (\d+)% (\S+)/';

    /** @var string The pattern of a finding line: marker and text */
    public const string LOG_FINDING_PATTERN = '/^\s*(VIOLATED|PARTIAL)\b(.*)$/';

    protected function configure(): void
    {
        $this
            ->addArgument('conversationIds', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'The conversation ids of the runs')
            ->addOption('log-dir', null, InputOption::VALUE_REQUIRED, 'Eval log directory: the log that names each conversation id carries the verdict and the findings')
            ->addOption('markdown', null, InputOption::VALUE_NONE, 'Print the tables as Markdown (for plans and knowledge docs)')
            ->addOption('findings', null, InputOption::VALUE_NONE, 'Print the judge findings (VIOLATED / PARTIAL lines) per run')
            ->addOption('no-tools', null, InputOption::VALUE_NONE, 'Skip the slowest-tools table')
            ->addOption('usage', null, InputOption::VALUE_NONE, 'Print the usage table: per run the input, cached, cache-write, output and thinking tokens, the cached share, the tokens and model seconds per call and the thinking blocks the provider dropped')
            ->addOption('variants', null, InputOption::VALUE_NONE, 'Print the per-run variants timeline (one row per dimension change, with the sections rendered then and the tool called next)');
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * Reads the report of every named run and prints the run table, the slowest tools, the variants timelines and the
     * findings, as the options ask.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws BadRequestException
     * @throws DBALException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', static::MEMORY_LIMIT);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $conversationIds = array_values(array_unique(array_map('intval', (array)$input->getArgument('conversationIds'))));
        $logDirectory = $input->getOption('log-dir') !== null ? (string)$input->getOption('log-dir') : null;
        $verdicts = $logDirectory !== null ? $this->readVerdictsFromLogs($logDirectory, $conversationIds) : AgentRunReportVerdicts::newInstance();
        $markdown = (bool)$input->getOption('markdown');

        $runReports = $this->findReportsOfConversationIds($conversationIds);
        $this->renderRunTable($symfonyStyle, $conversationIds, $runReports, $verdicts, $markdown);
        $unreadableLogFilesNote = $verdicts->getUnreadableLogFilesNote();
        if ($unreadableLogFilesNote !== null) {
            $symfonyStyle->writeln($unreadableLogFilesNote);
        }
        if ((bool)$input->getOption('usage')) {
            $this->renderUsageTable($symfonyStyle, $conversationIds, $runReports, $markdown);
        }
        if (!(bool)$input->getOption('no-tools')) {
            $this->renderSlowestToolsTable($symfonyStyle, $conversationIds, $runReports, $markdown);
        }
        if ((bool)$input->getOption('variants')) {
            $this->renderVariantsTimelines($symfonyStyle, $conversationIds, $runReports, $markdown);
        }
        if ((bool)$input->getOption('findings')) {
            $this->renderFindings($symfonyStyle, $conversationIds, $runReports, $verdicts, $markdown);
        }
        return Command::SUCCESS;
    }

    /**
     * The reports of the runs that have one, keyed by conversation; a conversation that does not exist (or that the
     * run may not read) or has no message rows has none.
     *
     * @param int[] $conversationIds
     * @return AIConversationRunReports
     * @throws BadRequestException
     * @throws DBALException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    protected function findReportsOfConversationIds(array $conversationIds): AIConversationRunReports
    {
        $aIConversationsService = $this->getAIConversationsService();
        $aIConversationRunReportService = $this->getAIConversationRunReportService();
        $runReports = AIConversationRunReports::newInstance();
        foreach ($conversationIds as $conversationId) {
            $conversation = $aIConversationsService->find($conversationId);
            if (!$conversation instanceof AIConversation) {
                continue;
            }
            $runReport = $aIConversationRunReportService->findReportOfConversation($conversation);
            if ($runReport !== null) {
                $runReports->add($runReport);
            }
        }
        return $runReports;
    }

    /**
     * The run table: one row per named run (`no rows` for a run without a report) and the totals row.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param int[] $conversationIds
     * @param AIConversationRunReports $runReports
     * @param AgentRunReportVerdicts $verdicts
     * @param bool $markdown
     * @return void
     */
    protected function renderRunTable(SymfonyStyle $symfonyStyle, array $conversationIds, AIConversationRunReports $runReports, AgentRunReportVerdicts $verdicts, bool $markdown): void
    {
        $printedRows = [];
        $runReportTotals = $this->buildRunReportTotals();
        foreach ($conversationIds as $conversationId) {
            $runReport = $runReports->getByConversationId($conversationId);
            if ($runReport === null) {
                $printedRows[] = array_merge([(string)$conversationId, 'no rows'], array_fill(0, static::EMPTY_CELLS_OF_RUN_WITHOUT_REPORT, ''));
                continue;
            }
            $runVerdict = $verdicts->getByConversationId($conversationId) ?? AgentRunReportVerdict::none();
            $printedRows[] = $this->buildPrintedRunRow($conversationId, $runReport, $runVerdict);
            $runReportTotals->addRun($runReport, $runVerdict);
        }
        $printedRows[] = $runReportTotals->getPrintedRow();
        $this->renderTable($symfonyStyle, static::RUN_TABLE_HEADERS, $printedRows, $markdown);
        $this->renderPlanSizeNotMeasuredNotes($symfonyStyle, $conversationIds, $runReports);
        $this->renderDecisionCostNotReadNotes($symfonyStyle, $conversationIds, $runReports);
    }

    /**
     * The usage table: one row per reported run and a row over all of them — the tokens of the model calls, the share
     * read from the prompt cache, the output and thinking tokens per call, the model seconds per call and the thinking
     * blocks the provider dropped from the requests.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param int[] $conversationIds
     * @param AIConversationRunReports $runReports
     * @param bool $markdown
     * @return void
     */
    protected function renderUsageTable(SymfonyStyle $symfonyStyle, array $conversationIds, AIConversationRunReports $runReports, bool $markdown): void
    {
        $printedRows = [];
        $sums = ['modelCalls' => 0, 'inputTokens' => 0, 'cachedInputTokens' => 0, 'cacheWriteTokens' => 0, 'outputTokens' => 0, 'reasoningTokens' => 0, 'droppedThinkingBlocks' => 0, 'modelSeconds' => 0.0, 'modelCostInUSD' => 0.0];
        foreach ($conversationIds as $conversationId) {
            $runReport = $runReports->getByConversationId($conversationId);
            if ($runReport === null) {
                continue;
            }
            $printedRows[] = $this->buildPrintedUsageRow((string)$conversationId, $runReport->modelCalls, $runReport->inputTokens, $runReport->cachedInputTokens, $runReport->cacheWriteTokens, $runReport->outputTokens, $runReport->reasoningTokens, $runReport->droppedThinkingBlocks, $runReport->modelSeconds, $runReport->modelCostInUSD);
            foreach (array_keys($sums) as $sumKey) {
                $sums[$sumKey] += $runReport->{$sumKey};
            }
        }
        if ($printedRows === []) {
            return;
        }
        $printedRows[] = $this->buildPrintedUsageRow('Σ', $sums['modelCalls'], $sums['inputTokens'], $sums['cachedInputTokens'], $sums['cacheWriteTokens'], $sums['outputTokens'], $sums['reasoningTokens'], $sums['droppedThinkingBlocks'], $sums['modelSeconds'], $sums['modelCostInUSD']);
        $this->renderTable($symfonyStyle, static::USAGE_TABLE_HEADERS, $printedRows, $markdown);
    }

    /**
     * @return string[] one cell per column of {@see self::USAGE_TABLE_HEADERS}
     */
    protected function buildPrintedUsageRow(string $label, int $modelCalls, int $inputTokens, int $cachedInputTokens, int $cacheWriteTokens, int $outputTokens, int $reasoningTokens, int $droppedThinkingBlocks, float $modelSeconds, float $modelCostInUSD): array
    {
        $calls = max(1, $modelCalls);
        return [
            $label,
            (string)$modelCalls,
            (string)$inputTokens,
            (string)$cachedInputTokens,
            $inputTokens > 0 ? (string)round(100 * $cachedInputTokens / $inputTokens) : '',
            (string)$cacheWriteTokens,
            (string)$outputTokens,
            (string)round($outputTokens / $calls),
            (string)$reasoningTokens,
            (string)round($reasoningTokens / $calls),
            (string)$droppedThinkingBlocks,
            number_format($modelSeconds / $calls, 1),
            number_format($modelCostInUSD, 3),
        ];
    }

    /**
     * Below the run table, one line per reported run whose plan size was not measured because the read of its plan
     * snapshots failed (`<run>: plan size not measured: <exception class>`); nothing when every read worked.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param int[] $conversationIds
     * @param AIConversationRunReports $runReports
     * @return void
     */
    protected function renderPlanSizeNotMeasuredNotes(SymfonyStyle $symfonyStyle, array $conversationIds, AIConversationRunReports $runReports): void
    {
        foreach ($conversationIds as $conversationId) {
            $planSizeNotMeasuredNote = $runReports->getByConversationId($conversationId)?->getPlanSizeNotMeasuredNote();
            if ($planSizeNotMeasuredNote !== null) {
                $symfonyStyle->writeln($conversationId . ': ' . $planSizeNotMeasuredNote);
            }
        }
    }

    /**
     * Below the run table (after the plan-size notes), one line per reported run whose decision model's cost was not
     * read because the read of the usage log failed (`<run>: decision cost not read: <exception class>`); nothing when
     * every read worked.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param int[] $conversationIds
     * @param AIConversationRunReports $runReports
     * @return void
     */
    protected function renderDecisionCostNotReadNotes(SymfonyStyle $symfonyStyle, array $conversationIds, AIConversationRunReports $runReports): void
    {
        foreach ($conversationIds as $conversationId) {
            $decisionCostNotReadNote = $runReports->getByConversationId($conversationId)?->getDecisionCostNotReadNote();
            if ($decisionCostNotReadNote !== null) {
                $symfonyStyle->writeln($conversationId . ': ' . $decisionCostNotReadNote);
            }
        }
    }

    /**
     * The row of one reported run.
     *
     * @param int $conversationId
     * @param AIConversationRunReport $runReport
     * @param AgentRunReportVerdict $runVerdict
     * @return string[] one cell per column of {@see self::RUN_TABLE_HEADERS}
     */
    protected function buildPrintedRunRow(int $conversationId, AIConversationRunReport $runReport, AgentRunReportVerdict $runVerdict): array
    {
        $unstampedModelCalls = $runReport->metrics->modelCallsWithoutEgressStamp;
        $taskPlanSize = $runReport->taskPlanSize;
        return [
            (string)$conversationId,
            $runVerdict->caseId,
            $runVerdict->getJudgeCellText(),
            (string)round($runReport->wallSeconds),
            (string)$runReport->ownerTurns,
            (string)$runReport->modelCalls,
            (string)$runReport->toolCalls,
            (string)$runReport->refusedToolCalls,
            (string)$runReport->identicalRepeats,
            number_format($runReport->agentSecondsPerTurn, 1),
            (string)round($runReport->modelSeconds) . ($unstampedModelCalls > 0 ? ' (' . $unstampedModelCalls . ' unstamped)' : ''),
            (string)round($runReport->platformOverheadSeconds),
            (string)round($runReport->toolSeconds),
            (string)round($runReport->toolWallSeconds),
            (string)round($runReport->ownerSeconds),
            (string)$runReport->planTicks,
            (string)$runReport->skillLoads,
            $runReport->nonBlockingToolCalls > 0 ? $runReport->nonBlockingToolCalls . ' (' . round($runReport->nonBlockingToolSeconds) . ' s)' : '',
            number_format($runReport->modelCostInUSD, 3),
            number_format($runReport->decisionModelCostInUSD, 4) . ' (' . $runReport->decisionModelCalls . ')',
            $runReport->variantsFinal !== '' ? $runReport->variantsFinal . ' (' . $runReport->variantTransitions . ' changes)' : '',
            $taskPlanSize !== null ? (string)$taskPlanSize->nodes : '',
            $taskPlanSize !== null ? (string)$taskPlanSize->maxDepth : '',
            $taskPlanSize !== null ? $taskPlanSize->tailTokensMax . ' / ' . $taskPlanSize->tailTokensSum : '',
        ];
    }

    /**
     * The slowest tools of every reported run, the slowest first, at most {@see self::SLOWEST_TOOLS_SHOWN} per named
     * run; nothing when no run called a tool.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param int[] $conversationIds
     * @param AIConversationRunReports $runReports
     * @param bool $markdown
     * @return void
     */
    protected function renderSlowestToolsTable(SymfonyStyle $symfonyStyle, array $conversationIds, AIConversationRunReports $runReports, bool $markdown): void
    {
        $printedRows = [];
        foreach ($conversationIds as $conversationId) {
            $runReport = $runReports->getByConversationId($conversationId);
            foreach ($runReport?->slowestTools->getElements() ?? [] as $toolTime) {
                $printedRows[] = [(string)$conversationId, $toolTime->toolName, number_format($toolTime->seconds, 1), (string)$toolTime->calls, number_format($toolTime->maxSeconds, 1)];
            }
        }
        if ($printedRows === []) {
            return;
        }
        // ordered by the printed seconds, as the table shows them
        usort($printedRows, static fn (array $firstRow, array $secondRow): int => (float)$secondRow[2] <=> (float)$firstRow[2]);
        $this->renderTable($symfonyStyle, static::SLOWEST_TOOLS_TABLE_HEADERS, array_slice($printedRows, 0, static::SLOWEST_TOOLS_SHOWN * max(1, count($conversationIds))), $markdown);
    }

    /**
     * One variants timeline table per run whose timeline has an entry, each under a line naming the run.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param int[] $conversationIds
     * @param AIConversationRunReports $runReports
     * @param bool $markdown
     * @return void
     */
    protected function renderVariantsTimelines(SymfonyStyle $symfonyStyle, array $conversationIds, AIConversationRunReports $runReports, bool $markdown): void
    {
        foreach ($conversationIds as $conversationId) {
            $variantsChanges = $runReports->getByConversationId($conversationId)?->variantsTimeline->changes;
            if ($variantsChanges === null || $variantsChanges->isEmpty()) {
                continue;
            }
            $symfonyStyle->writeln(($markdown ? '#### ' : '') . $conversationId . ' · variants timeline');
            $printedRows = [];
            foreach ($variantsChanges->getElements() as $variantsChange) {
                $printedRows[] = [(string)$conversationId, (string)$variantsChange->messageIndex, $variantsChange->dimension, $variantsChange->fromToken, $variantsChange->toToken, $variantsChange->skillSections, $variantsChange->toolCalledAfter];
            }
            $this->renderTable($symfonyStyle, static::VARIANTS_TIMELINE_TABLE_HEADERS, $printedRows, $markdown);
        }
    }

    /**
     * The findings of every run a log names: a header line (case, verdict, score, the variants delta) and one line per
     * finding.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param int[] $conversationIds
     * @param AIConversationRunReports $runReports
     * @param AgentRunReportVerdicts $verdicts
     * @param bool $markdown
     * @return void
     */
    protected function renderFindings(SymfonyStyle $symfonyStyle, array $conversationIds, AIConversationRunReports $runReports, AgentRunReportVerdicts $verdicts, bool $markdown): void
    {
        foreach ($conversationIds as $conversationId) {
            $runVerdict = $verdicts->getByConversationId($conversationId);
            if ($runVerdict === null) {
                continue;
            }
            $variantsDelta = (string)($runReports->getByConversationId($conversationId)?->variantsTimeline->delta ?? '');
            $symfonyStyle->writeln(
                ($markdown ? '### ' : '') . $conversationId . ' · ' . $runVerdict->caseId . ' · ' . $runVerdict->verdict . ' ' . ($runVerdict->scorePercent ?? '') . ' %'
                . ($variantsDelta !== '' ? ' · ' . $variantsDelta : '')
            );
            foreach ($runVerdict->findings as $finding) {
                $symfonyStyle->writeln('- ' . $finding);
            }
            $symfonyStyle->writeln('');
        }
    }

    /**
     * The judge verdicts and findings of the evaluation logs (`*.log` in the folder) that name one of the given
     * conversations. A log without the verdict line is skipped; a log that cannot be read is skipped and counted
     * ({@see AgentRunReportVerdicts::$unreadableLogFileCount}).
     *
     * @param string $logDirectory
     * @param int[] $conversationIds
     * @return AgentRunReportVerdicts
     */
    public function readVerdictsFromLogs(string $logDirectory, array $conversationIds): AgentRunReportVerdicts
    {
        $verdicts = AgentRunReportVerdicts::newInstance();
        foreach (glob(rtrim($logDirectory, '/') . '/*.log') ?: [] as $logPath) {
            try {
                $logContents = @file_get_contents($logPath);
            } catch (Throwable) {
                $logContents = false;
            }
            if ($logContents === false) {
                $verdicts->unreadableLogFileCount++;
                continue;
            }
            $logText = $logContents;
            $runVerdict = $this->parseVerdictOfLog($logText, $conversationIds);
            if ($runVerdict !== null) {
                $verdicts->addOrReplace($runVerdict);
            }
        }
        return $verdicts;
    }

    /**
     * The verdict a log states for its conversation; null when the log names no conversation, one not asked for, or
     * carries no verdict line.
     *
     * @param string $logText
     * @param int[] $conversationIds
     * @return AgentRunReportVerdict|null
     */
    protected function parseVerdictOfLog(string $logText, array $conversationIds): ?AgentRunReportVerdict
    {
        if (!preg_match(static::LOG_CONVERSATION_ID_PATTERN, $logText, $conversationIdMatch)) {
            return null;
        }
        $conversationId = (int)$conversationIdMatch[1];
        if (!in_array($conversationId, $conversationIds, true)) {
            return null;
        }
        if (!preg_match(static::LOG_VERDICT_PATTERN, $logText, $verdictMatch)) {
            return null;
        }
        $findings = [];
        foreach (preg_split('/\R/', $logText) ?: [] as $logLine) {
            if (preg_match(static::LOG_FINDING_PATTERN, $logLine, $findingMatch)) {
                $findings[] = $findingMatch[1] . mb_substr($findingMatch[2], 0, AgentRunReportVerdict::FINDING_TEXT_MAX_LENGTH);
            }
        }
        return AgentRunReportVerdict::forRun($conversationId, $verdictMatch[3], $verdictMatch[1], (int)$verdictMatch[2], $findings);
    }

    /**
     * Prints a table: the console table, or a Markdown table followed by an empty line.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param string[] $headers
     * @param array<int, array<int, string>> $printedRows
     * @param bool $markdown
     * @return void
     */
    protected function renderTable(SymfonyStyle $symfonyStyle, array $headers, array $printedRows, bool $markdown): void
    {
        if (!$markdown) {
            $symfonyStyle->table($headers, $printedRows);
            return;
        }
        $symfonyStyle->writeln('| ' . implode(' | ', $headers) . ' |');
        $symfonyStyle->writeln('|' . str_repeat('---|', count($headers)));
        foreach ($printedRows as $printedRow) {
            $symfonyStyle->writeln('| ' . implode(' | ', array_map(static fn ($cell): string => str_replace('|', '\\|', (string)$cell), $printedRow)) . ' |');
        }
        $symfonyStyle->writeln('');
    }

    /**
     * The conversations service (the application's class, when it replaces it).
     *
     * @return AIConversationsService
     */
    protected function getAIConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    /**
     * The run-report service (the application's class, when it replaces it).
     *
     * @return AIConversationRunReportService
     */
    protected function getAIConversationRunReportService(): AIConversationRunReportService
    {
        /** @var AIConversationRunReportService $aIConversationRunReportService */
        $aIConversationRunReportService = DDDService::instance()->getService(AIConversationRunReportService::class);
        return $aIConversationRunReportService;
    }

    /** The empty totals of one printout; an application's command may return its own totals object. */
    protected function buildRunReportTotals(): AgentRunReportTotals
    {
        return new AgentRunReportTotals();
    }
}
