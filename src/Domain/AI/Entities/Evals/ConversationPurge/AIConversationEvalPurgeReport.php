<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one run of the evaluation purge did (or, in a dry run, would do): the trees by verdict, the rows per step, the
 * estimated bytes (rows × the table's average row length), the writes and why the run stopped. It also builds the
 * lines and table cells of the console output, so a command only renders them.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEvalPurgeReport extends ValueObject
{
    public const string STOPPED_NO_TREE_LEFT = 'no eval conversation tree left';

    public const string STOPPED_TIME_BUDGET = 'time budget used up';

    public const string STOPPED_NO_EVAL_ACCOUNTS = 'no eval accounts configured';

    /** @var int The most purged roots {@see $sampleRootConversations} keeps. */
    public const int SAMPLE_SIZE = 10;

    /** @var int Bytes of one megabyte in the console output */
    public const int BYTES_PER_MEGABYTE = 1048576;

    /** @var string The label of the total row of the step table */
    public const string TOTAL_ROW_LABEL = 'TOTAL';

    /** @var string The table cell of a value that is not known */
    public const string EMPTY_TABLE_CELL = '-';

    public bool $dryRun = true;

    /** @var string|null UTC moment: activity before it counts as old. */
    public ?string $activityCutoff = null;

    public int $treesChecked = 0;

    /** @var int Trees fully deleted (or, in a dry run, counted). */
    public int $treesPurged = 0;

    /** @var int Conversations in the purged trees (roots, delegation children, supervisor tandems). */
    public int $conversationsInPurgedTrees = 0;

    public int $treesSkippedRunInFlight = 0;

    public int $treesSkippedFreshHeartbeat = 0;

    public int $treesSkippedRecentActivity = 0;

    public int $treesSkippedNotEvalAccount = 0;

    /** @var int Batches stopped before their conversations went (lock wait, time budget) — the next run finishes them. */
    public int $batchesLeftIncomplete = 0;

    public int $chunksSkippedOnLock = 0;

    public int $pausesForServerLoad = 0;

    public int $writes = 0;

    public ?int $averageWriteMs = null;

    public ?int $slowestWriteMs = null;

    public int $totalWriteMs = 0;

    /** @var int The adaptive pause after a write (milliseconds), doubled after a slow write, halved back after a fast one. */
    public int $currentPauseMs = 0;

    /** @var int|null The highest root conversation id checked. */
    public ?int $lastRootConversationId = null;

    public int $runSeconds = 0;

    public string $stoppedBecause = self::STOPPED_NO_TREE_LEFT;

    /** @var int[] Rows written (deleted or nulled) — or counted in a dry run — per step label. */
    public array $rowsByStep = [];

    /** @var int[] Estimated bytes of the deleted rows per step label (deletes only). */
    public array $estimatedBytesByStep = [];

    /** @var int[] Trees purged — or, in a dry run, that would be purged — per account id. */
    public array $treesPurgedByAccountId = [];

    /** @var int[] Conversations of those trees per account id. */
    public array $conversationsPurgedByAccountId = [];

    /** @var AIConversationEvalPurgeSampleRoots The first purged roots with the evaluation marker that selected them */
    public AIConversationEvalPurgeSampleRoots $sampleRootConversations;

    public function __construct()
    {
        parent::__construct();
        $this->sampleRootConversations = AIConversationEvalPurgeSampleRoots::newInstance();
    }

    /** A report of a run that writes (`$dryRun` false) or only counts. */
    public static function forRun(bool $dryRun): static
    {
        $aIConversationEvalPurgeReport = static::newInstance();
        $aIConversationEvalPurgeReport->dryRun = $dryRun;
        return $aIConversationEvalPurgeReport;
    }

    /** Count one purged tree for its account and keep it in the sample while the sample has room. */
    public function recordPurgedTree(AIConversationEvalTree $tree, ?string $evalMarker): void
    {
        $accountId = $tree->rootAccountId ?? 0;
        $this->treesPurgedByAccountId[$accountId] = ($this->treesPurgedByAccountId[$accountId] ?? 0) + 1;
        $this->conversationsPurgedByAccountId[$accountId] = ($this->conversationsPurgedByAccountId[$accountId] ?? 0) + count($tree->conversationIds);
        if ($this->sampleRootConversations->count() < static::SAMPLE_SIZE) {
            $sampleRoot = AIConversationEvalPurgeSampleRoot::forPurgedTree($tree, $evalMarker);
            $this->sampleRootConversations->add($sampleRoot);
        }
    }

    /**
     * Count the trees of one batch whose every step finished: the trees, the conversations of the batch and each tree
     * for its account and the sample.
     *
     * @param array<int, string|null> $evalMarkersByRootConversationId root conversation id → the marker that selected it
     */
    public function recordPurgedTrees(AIConversationEvalTrees $purgedTrees, AIConversationEvalPurgeKeys $purgeKeys, array $evalMarkersByRootConversationId): void
    {
        $this->treesPurged += $purgedTrees->count();
        $this->conversationsInPurgedTrees += $purgeKeys->countIdsOfKind(AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        foreach ($purgedTrees->getElements() as $purgedTree) {
            $this->recordPurgedTree($purgedTree, $evalMarkersByRootConversationId[$purgedTree->rootConversationId] ?? null);
        }
    }

    public function recordVerdict(string $verdict): void
    {
        $this->treesChecked++;
        match ($verdict) {
            AIConversationEvalTree::VERDICT_SKIP_RUN_IN_FLIGHT => $this->treesSkippedRunInFlight++,
            AIConversationEvalTree::VERDICT_SKIP_FRESH_HEARTBEAT => $this->treesSkippedFreshHeartbeat++,
            AIConversationEvalTree::VERDICT_SKIP_RECENT_ACTIVITY => $this->treesSkippedRecentActivity++,
            AIConversationEvalTree::VERDICT_SKIP_NOT_EVAL_ACCOUNT => $this->treesSkippedNotEvalAccount++,
            default => null,
        };
    }

    /** Add the rows of one statement (or one dry-run count) of `$step`. */
    public function addStepRows(AIConversationEvalPurgeStep $step, int $rows, int $averageRowBytes): void
    {
        $label = $step->getReportLabel();
        $this->rowsByStep[$label] = ($this->rowsByStep[$label] ?? 0) + $rows;
        if ($step->isDelete()) {
            $this->estimatedBytesByStep[$label] = ($this->estimatedBytesByStep[$label] ?? 0) + $rows * max(0, $averageRowBytes);
        }
    }

    public function recordWrite(int $writeMs): void
    {
        $this->writes++;
        $this->totalWriteMs += $writeMs;
        $this->averageWriteMs = intdiv($this->totalWriteMs, $this->writes);
        $this->slowestWriteMs = max($this->slowestWriteMs ?? 0, $writeMs);
    }

    public function getEstimatedBytesTotal(): int
    {
        return (int)array_sum($this->estimatedBytesByStep);
    }

    public function getRowsTotal(): int
    {
        return (int)array_sum($this->rowsByStep);
    }

    /** One progress line of the running purge, written after every batch. */
    public function buildProgressLine(): string
    {
        return ($this->dryRun ? '[dry run] ' : '')
            . "roots up to {$this->lastRootConversationId}: {$this->treesChecked} trees checked, {$this->treesPurged} purged"
            . " ({$this->conversationsInPurgedTrees} conversations), {$this->getRowsTotal()} rows, "
            . $this->formatMegabytes($this->getEstimatedBytesTotal()) . ' MB, average write ' . ($this->averageWriteMs ?? '-') . ' ms';
    }

    /** The bytes as megabytes with one decimal, as the console output writes them (`0`, `1.5`). */
    public function formatMegabytes(int $bytes): string
    {
        return (string)round($bytes / static::BYTES_PER_MEGABYTE, 1);
    }

    /** @return string[] the headers of the step table */
    public function buildStepTableHeaders(): array
    {
        return ['table / column', $this->dryRun ? 'rows (would)' : 'rows', 'estimated MB'];
    }

    /**
     * @return string[] the row of the step table for one label of {@see $rowsByStep}: label, rows, estimated MB (`-`
     *     for a step that deletes nothing)
     */
    public function buildStepTableCells(string $stepLabel): array
    {
        $stepBytes = $this->estimatedBytesByStep[$stepLabel] ?? null;
        return [$stepLabel, (string)($this->rowsByStep[$stepLabel] ?? 0), $stepBytes === null ? static::EMPTY_TABLE_CELL : $this->formatMegabytes($stepBytes)];
    }

    /** @return string[] the last row of the step table: the totals */
    public function buildStepTableTotalCells(): array
    {
        return [static::TOTAL_ROW_LABEL, (string)$this->getRowsTotal(), $this->formatMegabytes($this->getEstimatedBytesTotal())];
    }

    /** @return string[] the headers of the summary table */
    public function buildSummaryTableHeaders(): array
    {
        return ['', 'value'];
    }

    /**
     * @return string[] the summary of the run as the console prints it: name → value, in the order of the table, `-`
     *     for a value that is not known
     */
    public function buildSummaryValuesByName(): array
    {
        $summaryValuesByName = [];
        foreach ([
            'activityCutoff (UTC)' => $this->activityCutoff,
            'treesChecked' => $this->treesChecked,
            'treesPurged' => $this->treesPurged,
            'conversationsInPurgedTrees' => $this->conversationsInPurgedTrees,
            'treesSkippedRunInFlight' => $this->treesSkippedRunInFlight,
            'treesSkippedFreshHeartbeat' => $this->treesSkippedFreshHeartbeat,
            'treesSkippedRecentActivity' => $this->treesSkippedRecentActivity,
            'treesSkippedNotEvalAccount' => $this->treesSkippedNotEvalAccount,
            'batchesLeftIncomplete' => $this->batchesLeftIncomplete,
            'chunksSkippedOnLock' => $this->chunksSkippedOnLock,
            'pausesForServerLoad' => $this->pausesForServerLoad,
            'writes' => $this->writes,
            'averageWriteMs' => $this->averageWriteMs,
            'slowestWriteMs' => $this->slowestWriteMs,
            'lastRootConversationId' => $this->lastRootConversationId,
            'runSeconds' => $this->runSeconds,
            'stoppedBecause' => $this->stoppedBecause,
        ] as $summaryName => $summaryValue) {
            $summaryValuesByName[$summaryName] = $summaryValue === null ? static::EMPTY_TABLE_CELL : (string)$summaryValue;
        }
        return $summaryValuesByName;
    }

    /** @return string[] the headers of the account table */
    public function buildAccountTableHeaders(): array
    {
        return ['account', $this->dryRun ? 'trees (would)' : 'trees', 'conversations'];
    }

    /** @return string[] the row of the account table for one key of {@see $treesPurgedByAccountId} */
    public function buildAccountTableCells(int $accountId): array
    {
        return [(string)$accountId, (string)($this->treesPurgedByAccountId[$accountId] ?? 0), (string)($this->conversationsPurgedByAccountId[$accountId] ?? 0)];
    }

    /** The closing line of the run (the console prints it as a success line). */
    public function buildResultLine(): string
    {
        return ($this->dryRun ? 'dry run: ' : '') . "{$this->treesPurged} trees, {$this->getRowsTotal()} rows, stopped because: {$this->stoppedBecause}";
    }
}
