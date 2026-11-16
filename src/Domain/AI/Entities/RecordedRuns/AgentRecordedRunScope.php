<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseRowScopeColumns;

/**
 * Where a recorded run works: the scope ids it created or works in (by scope name, e.g. the space and the account),
 * the root conversation of the run once the harness created it, and the scope names the run shares with other runs.
 * The ids the run created are read in this scope: per table through the run's conversation tree first, then through
 * the first scope column the table has ({@see buildScopeValuesByColumn()}). A table read through the column of a
 * shared scope may count the rows of another run on the same scope ({@see findSharedScopeTables()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunScope extends ValueObject
{
    /** The columns a conversation tree scopes a table by, in priority order: the conversation, its parent, the root. */
    public const array TREE_SCOPE_COLUMNS = ['aiConversationId', 'parentConversationId', 'rootConversationId'];

    /** The tree column whose values also select the rows by their own id: a root carries no root id. */
    public const string ROOT_CONVERSATION_SCOPE_COLUMN = 'rootConversationId';

    /** The scope name a run always shares: the account. */
    public const string SCOPE_NAME_ACCOUNT = 'account';

    /** @var array<string, int|string|null> Scope name → the id the run created or works in */
    public array $scopeIdsByName = [];

    /**
     * @var string[] The scope names the run did NOT create for itself: a table read through such a scope's column may
     *               count other runs' rows — the account always, a space the run did not create too
     */
    public array $sharedScopeNames = [self::SCOPE_NAME_ACCOUNT];

    /** The root conversation of the run, once the harness created it; null before. */
    public ?int $rootConversationId = null;

    /**
     * @param array<string, int|string|null> $scopeIdsByName
     * @param string[] $sharedScopeNames
     * @param int|null $rootConversationId
     * @return static
     */
    public static function forScopeIds(array $scopeIdsByName, array $sharedScopeNames = [self::SCOPE_NAME_ACCOUNT], ?int $rootConversationId = null): static
    {
        $agentRecordedRunScope = static::newInstance();
        $agentRecordedRunScope->scopeIdsByName = $scopeIdsByName;
        $agentRecordedRunScope->sharedScopeNames = array_values(array_map('strval', $sharedScopeNames));
        $agentRecordedRunScope->rootConversationId = $rootConversationId;
        return $agentRecordedRunScope;
    }

    /**
     * Adds scope ids; an id of a name already set is replaced.
     *
     * @param array<string, int|string|null> $scopeIdsByName
     * @return void
     */
    public function addScopeIds(array $scopeIdsByName): void
    {
        $this->scopeIdsByName = array_merge($this->scopeIdsByName, $scopeIdsByName);
    }

    /**
     * The columns the created-id read of the run is scoped by, in priority order: the run's own conversations (the
     * tree), then the columns of every scope in the order of `$agentRecordedRunScopeColumns`. A column without a value
     * (no tree yet, a scope without an id) is left out.
     *
     * @param AgentRecordedRunScopeColumns $agentRecordedRunScopeColumns
     * @param int[] $treeConversationIds the ids of the run's conversation tree as stored now
     * @return array<string, int[]> column → the run's values
     */
    public function buildScopeValuesByColumn(AgentRecordedRunScopeColumns $agentRecordedRunScopeColumns, array $treeConversationIds): array
    {
        $scopeValuesByColumn = [
            'aiConversationId' => $treeConversationIds,
            'parentConversationId' => $treeConversationIds,
            self::ROOT_CONVERSATION_SCOPE_COLUMN => $this->rootConversationId !== null ? [$this->rootConversationId] : [],
        ];
        foreach ($agentRecordedRunScopeColumns->getScopeNames() as $scopeName) {
            $scopeId = (int)($this->scopeIdsByName[$scopeName] ?? 0);
            foreach ($agentRecordedRunScopeColumns->getColumnsOfScope($scopeName) as $scopeColumn) {
                $scopeValuesByColumn[$scopeColumn] = $scopeId > 0 ? [$scopeId] : [];
            }
        }
        return array_filter($scopeValuesByColumn, static fn (array $scopeValues): bool => $scopeValues !== []);
    }

    /**
     * The scope columns of {@see buildScopeValuesByColumn()} for the framework's created-id read: the root conversation
     * id also admits the root rows themselves (a root carries no root id).
     *
     * @param AgentRecordedRunScopeColumns $agentRecordedRunScopeColumns
     * @param int[] $treeConversationIds
     * @return DatabaseRowScopeColumns
     */
    public function buildRowScopeColumns(AgentRecordedRunScopeColumns $agentRecordedRunScopeColumns, array $treeConversationIds): DatabaseRowScopeColumns
    {
        return DatabaseRowScopeColumns::fromValuesByColumn(
            $this->buildScopeValuesByColumn($agentRecordedRunScopeColumns, $treeConversationIds),
            [self::ROOT_CONVERSATION_SCOPE_COLUMN]
        );
    }

    /**
     * The tables read through a column of a shared scope ({@see $sharedScopeNames}).
     *
     * @param array<string, string> $scopeColumnByTable table → the scope column it was read through
     * @param AgentRecordedRunScopeColumns $agentRecordedRunScopeColumns
     * @return array<string, string> table → its scope column, in the order of `$scopeColumnByTable`
     */
    public function findSharedScopeTables(array $scopeColumnByTable, AgentRecordedRunScopeColumns $agentRecordedRunScopeColumns): array
    {
        $sharedScopeColumns = $agentRecordedRunScopeColumns->getColumnsOfScopes($this->sharedScopeNames);
        return array_filter($scopeColumnByTable, static fn (string $scopeColumn): bool => in_array($scopeColumn, $sharedScopeColumns, true));
    }
}
