<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The columns each scope of a recorded run is read through: per scope name (`agentSpace`, `account`, or the names an
 * application gives its own scopes) the column names a table may carry for it, in priority order. A run's created ids
 * are read per table through the first column of this list the table has ({@see AgentRecordedRunScope}); a scope's
 * columns also tell which tables a run shares with other runs ({@see AgentRecordedRunScope::findSharedScopeTables()}).
 *
 * Built scope after scope with {@see withScope()}; the scopes are read in the order they were added. The scope names
 * and columns are opaque strings of the application: the package's default
 * ({@see \DDD\Domain\AI\Services\Eval\AgentRecordedRunsService::getScopeColumnsByName()}) names the space and the
 * account; an application that reads its own tables lists their columns in its service.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunScopeColumns extends ValueObject
{
    /** @var string[] Column → the scope it belongs to, the columns in priority order, scope after scope */
    protected array $scopeNameByColumn = [];

    /**
     * These scope columns with one more scope after the present ones. A column already taken by a scope stays with it.
     *
     * @param string $scopeName
     * @param string ...$columnNames the columns of the scope, in priority order
     * @return static
     */
    public function withScope(string $scopeName, string ...$columnNames): static
    {
        $agentRecordedRunScopeColumns = clone $this;
        foreach ($columnNames as $columnName) {
            $agentRecordedRunScopeColumns->scopeNameByColumn[$columnName] ??= $scopeName;
        }
        return $agentRecordedRunScopeColumns;
    }

    /**
     * @return string[] the scope names, in the order their columns are read
     */
    public function getScopeNames(): array
    {
        return array_values(array_unique(array_values($this->scopeNameByColumn)));
    }

    /**
     * @param string $scopeName
     * @return string[] the columns of the scope in priority order; none for an unknown scope
     */
    public function getColumnsOfScope(string $scopeName): array
    {
        return array_map('strval', array_keys($this->scopeNameByColumn, $scopeName, true));
    }

    /**
     * @param string[] $scopeNames
     * @return string[] the columns of these scopes, scope after scope
     */
    public function getColumnsOfScopes(array $scopeNames): array
    {
        $columns = [];
        foreach ($scopeNames as $scopeName) {
            $columns = array_merge($columns, $this->getColumnsOfScope((string)$scopeName));
        }
        return $columns;
    }
}
