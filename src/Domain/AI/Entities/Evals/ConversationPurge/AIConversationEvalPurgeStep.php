<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;

/**
 * One statement kind of the evaluation purge: delete the rows of `$tableName` whose `$columnName` points at an id of
 * the purged trees, or set that column to NULL (a record that outlives the conversation keeps its content and loses
 * only the pointer). Which ids the column holds is the `$keyKind` ({@see AIConversationEvalPurgeKeys}); heavy rows
 * (large JSON or text payloads) are written in smaller statements than light ones.
 *
 * A step is named by its key ({@see getStepKey()}): the order of the purge's step list is a list of these keys.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationEvalPurgeStep extends ValueObject
{
    /** @var string The rows go. */
    public const string ACTION_DELETE = 'delete';

    /** @var string The rows stay; the pointer column becomes NULL. */
    public const string ACTION_NULLIFY = 'nullify';

    /** @var string The column that identifies a row of a step table on PostgreSQL, where a write picks its rows by it */
    public const string ROW_ID_COLUMN_NAME = 'id';

    /** @var string The alias of the derived table the PostgreSQL write picks its row ids from */
    public const string PICKED_ROWS_ALIAS = 'pickedRows';

    public string $tableName = '';

    public string $columnName = '';

    /** @var string A kind of {@see AIConversationEvalPurgeKeys}: one of its `KIND_*` constants or a kind of the application. */
    public string $keyKind = AIConversationEvalPurgeKeys::KIND_CONVERSATION;

    public string $action = self::ACTION_DELETE;

    /** @var bool The rows are large (MB-scale JSON, snapshots, embeddings): written in the small statement size. */
    public bool $hasHeavyRows = false;

    public static function deleteRows(string $tableName, string $columnName, string $keyKind, bool $hasHeavyRows = false): static
    {
        return static::create($tableName, $columnName, $keyKind, static::ACTION_DELETE, $hasHeavyRows);
    }

    public static function nullifyColumn(string $tableName, string $columnName, string $keyKind): static
    {
        return static::create($tableName, $columnName, $keyKind, static::ACTION_NULLIFY, false);
    }

    protected static function create(string $tableName, string $columnName, string $keyKind, string $action, bool $hasHeavyRows): static
    {
        $aIConversationEvalPurgeStep = static::newInstance();
        $aIConversationEvalPurgeStep->tableName = $tableName;
        $aIConversationEvalPurgeStep->columnName = $columnName;
        $aIConversationEvalPurgeStep->keyKind = $keyKind;
        $aIConversationEvalPurgeStep->action = $action;
        $aIConversationEvalPurgeStep->hasHeavyRows = $hasHeavyRows;
        return $aIConversationEvalPurgeStep;
    }

    /**
     * The key of a step with this action on this table and column: `delete:EntityFoo.bar`, `nullify:EntityFoo.bar`.
     * Unique within a step list, also where two steps share a table and its report label.
     */
    public static function buildStepKey(string $action, string $tableName, string $columnName): string
    {
        return "{$action}:{$tableName}.{$columnName}";
    }

    /** The key of this step ({@see buildStepKey()}). */
    public function getStepKey(): string
    {
        return static::buildStepKey($this->action, $this->tableName, $this->columnName);
    }

    public function isDelete(): bool
    {
        return $this->action === static::ACTION_DELETE;
    }

    /** The report line of this step: `EntityFoo` for a delete, `EntityFoo.column = NULL` for a nullify. */
    public function getReportLabel(): string
    {
        return $this->isDelete() ? $this->tableName : "{$this->tableName}.{$this->columnName} = NULL";
    }

    /** The row limit of one write of this step: the heavy or the light statement size of the run, at least 1. */
    public function getRowLimit(AIConversationEvalPurgeSettings $settings): int
    {
        return max(1, $this->hasHeavyRows ? $settings->heavyRowsPerStatement : $settings->lightRowsPerStatement);
    }

    /**
     * The one write statement of a chunk; `:ids` is the id list, the row limit keeps every statement short. MariaDB
     * limits the write itself (`DELETE … LIMIT n`, `UPDATE … LIMIT n`). PostgreSQL has no limit on a write: the
     * statement writes the rows whose id a limited read of the same table picks (a derived table, so the read is
     * complete before the write starts).
     */
    public function buildWriteSql(int $rowLimit, SqlDialect $sqlDialect): string
    {
        $quotedTableName = $sqlDialect->quote($this->tableName);
        $quotedColumnName = $sqlDialect->quote($this->columnName);
        $limitedRowLimit = max(1, $rowLimit);
        $writeStatement = $this->isDelete()
            ? "DELETE FROM {$quotedTableName}"
            : "UPDATE {$quotedTableName} SET {$quotedColumnName} = NULL";
        if ($sqlDialect->name() !== SqlDialect::POSTGRES) {
            return "{$writeStatement} WHERE {$quotedColumnName} IN (:ids) LIMIT {$limitedRowLimit}";
        }
        $quotedRowIdColumnName = $sqlDialect->quote(static::ROW_ID_COLUMN_NAME);
        return "{$writeStatement} WHERE {$quotedRowIdColumnName} IN (SELECT {$quotedRowIdColumnName} FROM ("
            . "SELECT {$quotedRowIdColumnName} FROM {$quotedTableName} WHERE {$quotedColumnName} IN (:ids) LIMIT {$limitedRowLimit}"
            . ') AS ' . $sqlDialect->quote(static::PICKED_ROWS_ALIAS) . ')';
    }

    /** The dry-run count of the rows this step would write for `:ids`. */
    public function buildCountSql(SqlDialect $sqlDialect): string
    {
        return 'SELECT COUNT(*) FROM ' . $sqlDialect->quote($this->tableName) . ' WHERE ' . $sqlDialect->quote($this->columnName) . ' IN (:ids)';
    }
}
