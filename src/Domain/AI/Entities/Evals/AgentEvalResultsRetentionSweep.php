<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one retention sweep over the results folder of the evaluation runs deleted
 * ({@see \DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService::sweepRunEntriesOlderThanRetention()}): how many dated
 * run entries (a run folder or a loose run file), the bytes they held, and the retention they were older than.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalResultsRetentionSweep extends ValueObject
{
    /** @var int Days a run entry is kept */
    protected int $retentionDays = 0;

    /** @var int The run entries deleted */
    protected int $deletedEntryCount = 0;

    /** @var int The bytes of the deleted files */
    protected int $freedBytes = 0;

    /**
     * A sweep that has deleted nothing yet.
     *
     * @param int $retentionDays days a run entry is kept
     * @return static
     */
    public static function withRetentionDays(int $retentionDays): static
    {
        $agentEvalResultsRetentionSweep = static::newInstance();
        $agentEvalResultsRetentionSweep->retentionDays = $retentionDays;
        return $agentEvalResultsRetentionSweep;
    }

    /**
     * Counts one deleted run entry.
     *
     * @param int $freedBytes the bytes of the files the entry held
     */
    public function addDeletedEntry(int $freedBytes): void
    {
        $this->deletedEntryCount++;
        $this->freedBytes += $freedBytes;
    }

    /** Days a run entry is kept. */
    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    /** The run entries deleted. */
    public function getDeletedEntryCount(): int
    {
        return $this->deletedEntryCount;
    }

    /** The bytes of the deleted files. */
    public function getFreedBytes(): int
    {
        return $this->freedBytes;
    }

    /** The line for the operator; null when nothing was deleted (a sweep that deleted nothing prints nothing). */
    public function buildReportLine(): ?string
    {
        if ($this->deletedEntryCount === 0) {
            return null;
        }
        return sprintf(
            'Retention sweep: deleted %d run artifact(s) older than %d days (%.1f MB freed)',
            $this->deletedEntryCount,
            $this->retentionDays,
            $this->freedBytes / 1048576
        );
    }
}
