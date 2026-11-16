<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunCreatedIdReadReport;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseIdsAboveHighWaterMarks;

/**
 * What one read of a recorded run's created ids found, as the recording stores it: the ids per table that grew since
 * the run started, the scope column each table was read through, the tables read without a scope, the tables whose
 * read hit the limit, and the tables read through a scope the run shares with other runs. The stored document keeps
 * the keys `idsByTable`, `scopeColumnByTable`, `unscopedTables`, `cappedTables`, `sharedScopeTables` in this order.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunCreatedIdRead extends ValueObject
{
    /** The stored key of the tables read through a shared scope. */
    public const string KEY_SHARED_SCOPE_TABLES = 'sharedScopeTables';

    /** @var array<string, mixed> The stored document of the read */
    protected array $storedRead = [];

    /**
     * @param DatabaseIdsAboveHighWaterMarks $databaseIdsAboveHighWaterMarks what the framework's read found
     * @param array<string, string> $sharedScopeTables table → its scope column, for the tables read through a shared scope
     * @return static
     */
    public static function fromIdsAboveHighWaterMarks(DatabaseIdsAboveHighWaterMarks $databaseIdsAboveHighWaterMarks, array $sharedScopeTables): static
    {
        $storedRead = $databaseIdsAboveHighWaterMarks->toArray();
        $storedRead[self::KEY_SHARED_SCOPE_TABLES] = $sharedScopeTables;
        return static::fromStoredRead($storedRead);
    }

    /**
     * @param array<string, mixed> $storedRead the read as a recording stores it; an empty array for a recording without one
     * @return static
     */
    public static function fromStoredRead(array $storedRead): static
    {
        $agentRecordedRunCreatedIdRead = static::newInstance();
        $agentRecordedRunCreatedIdRead->storedRead = $storedRead;
        return $agentRecordedRunCreatedIdRead;
    }

    /**
     * @return array<string, int[]> table → the ids the run created in it, ascending
     */
    public function getIdsByTable(): array
    {
        return (array)($this->storedRead['idsByTable'] ?? []);
    }

    /**
     * @return array<string, string> table → the scope column it was read through
     */
    public function getScopeColumnByTable(): array
    {
        return (array)($this->storedRead['scopeColumnByTable'] ?? []);
    }

    /**
     * @return array<string, string> table → its scope column, for the tables read through a shared scope
     */
    public function getSharedScopeTables(): array
    {
        return (array)($this->storedRead[self::KEY_SHARED_SCOPE_TABLES] ?? []);
    }

    /**
     * The part of the read a comparison reports: the capped and the unscoped tables.
     *
     * @return AgentRecordedRunCreatedIdReadReport
     */
    public function buildReadReport(): AgentRecordedRunCreatedIdReadReport
    {
        return AgentRecordedRunCreatedIdReadReport::fromStoredReport($this->storedRead);
    }

    /**
     * The stored document of the read.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->storedRead;
    }
}
