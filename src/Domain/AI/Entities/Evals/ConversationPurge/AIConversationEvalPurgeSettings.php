<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The knobs of one run of the evaluation purge (`app:agent:eval-conversations-purge`). The defaults fit a cron run
 * under a ticker that kills its process after 360 seconds: a budget capped at {@see MAXIMUM_SECONDS}, small
 * statements, a pause after every write that doubles while writes are slow, a wait while the server is busy. A dry run
 * is the default; `$dryRun = false` writes.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEvalPurgeSettings extends ValueObject
{
    /**
     * @var int The time budget is capped here, for a cron ticker that kills its process after 360 s. The budget is
     * checked before every tree check and every write, never inside a statement: after its last check a run still
     * finishes the statement it started — every read and every write of the purge is limited to 20 s
     * ({@see \DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService::STATEMENT_TIMEOUT_SECONDS}) — the pause
     * after a write (at most 10 s) and the remaining reads of its batch, each limited to 20 s as well (a dry run counts
     * the rows of a batch without a budget check between the counts). 240 s leaves that tail and the console boot room
     * below the ticker's timeout.
     */
    public const int MAXIMUM_SECONDS = 240;

    /** @var string The format of a cutoff moment: the format the conversation columns store their UTC moments in */
    public const string CUTOFF_MOMENT_FORMAT = 'Y-m-d H:i:s';

    /** @var int A tree whose newest activity is older than this many hours is deleted. */
    public int $olderThanHours = 48;

    /**
     * @var int|null A tree whose newest activity is older than this many MINUTES is deleted — replaces
     * {@see $olderThanHours} when set; 0 = every tree created before now (the clean start of a seed account).
     */
    public ?int $olderThanMinutes = null;

    /**
     * @var int[]|null The accounts this run purges; null = the configured evaluation accounts. Every account named here
     * must be a configured evaluation account — unless {@see $evalOnly} restricts the run to marked evaluation
     * conversations and the application allows the account for that.
     */
    public ?array $accountIds = null;

    /**
     * @var bool Only trees whose root carries an evaluation marker: the evaluation conversations of an account that
     * also holds the owner's own conversations. The owner's conversations carry no marker and are never selected.
     */
    public bool $evalOnly = false;

    /** @var int[] Conversations on these agent spaces are never purged, whatever their account. */
    public array $keptAgentSpaceIds = [];

    /** @var int A run heartbeat younger than this many minutes keeps the tree, whatever its age. */
    public int $freshHeartbeatMinutes = 30;

    /** @var int Root conversations checked and deleted together. */
    public int $treesPerBatch = 25;

    /** @var int Row limit of one write statement on a table with large rows (messages, snapshots, embeddings). */
    public int $heavyRowsPerStatement = 50;

    /** @var int Row limit of one write statement on any other table. */
    public int $lightRowsPerStatement = 500;

    /** @var int Ids bound into one `IN (…)` list. */
    public int $idsPerStatement = 500;

    /** @var int Base pause after every write, in milliseconds. */
    public int $pauseMs = 100;

    /** @var int A write slower than this (milliseconds) doubles the pause. */
    public int $slowWriteMs = 2000;

    /**
     * @var int Time budget of the run in seconds (capped at {@see MAXIMUM_SECONDS}); the next run continues with what is
     * left.
     */
    public int $maxSeconds = self::MAXIMUM_SECONDS;

    /** @var int MariaDB only: wait while more statements than this execute on the server. */
    public int $maxThreadsRunning = 40;

    /** @var int MariaDB only: wait while the purge backlog of the storage engine (history list length) is longer than this. */
    public int $maxHistoryListLength = 200000;

    public bool $dryRun = true;

    /** @var int|null Start after this root conversation id instead of 0. */
    public ?int $afterRootConversationId = null;

    /** @var int|null Only this root conversation (still checked like every other). */
    public ?int $onlyRootConversationId = null;

    /** The time budget that applies: at least one second, never above {@see MAXIMUM_SECONDS}. */
    public function getEffectiveMaxSeconds(): int
    {
        return min(static::MAXIMUM_SECONDS, max(1, $this->maxSeconds));
    }

    /**
     * The UTC moment before which a tree's activity counts as old: {@see $olderThanMinutes} (at least 0) when set,
     * otherwise {@see $olderThanHours} (at least 1) before `$nowUnixTimestamp`. Computed here, never by the database:
     * the columns hold UTC, and the server clock of one engine is local time.
     */
    public function buildActivityCutoff(int $nowUnixTimestamp): string
    {
        $ageSeconds = $this->olderThanMinutes !== null
            ? max(0, $this->olderThanMinutes) * 60
            : max(1, $this->olderThanHours) * 3600;
        return gmdate(static::CUTOFF_MOMENT_FORMAT, $nowUnixTimestamp - $ageSeconds);
    }

    /** The UTC moment after which a run heartbeat keeps its tree: {@see $freshHeartbeatMinutes} (at least 1) before now. */
    public function buildFreshHeartbeatCutoff(int $nowUnixTimestamp): string
    {
        return gmdate(static::CUTOFF_MOMENT_FORMAT, $nowUnixTimestamp - max(1, $this->freshHeartbeatMinutes) * 60);
    }

    /**
     * The first console line of a run: `[dry run] accounts: 1, 2 — eval-marked conversation trees only`.
     *
     * @param int[] $accountIds the accounts the run purges
     */
    public function buildAccountsLine(array $accountIds): string
    {
        return ($this->dryRun ? '[dry run] ' : '') . 'accounts: ' . implode(', ', $accountIds)
            . ($this->evalOnly ? ' — eval-marked conversation trees only' : '');
    }
}
