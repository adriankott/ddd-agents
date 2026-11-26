<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Goals;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoals;
use DDD\Domain\AI\Repo\DB\Conversations\Goals\DBAgentGoal;
use DDD\Domain\AI\Repo\DB\Conversations\Goals\DBAgentGoalModel;
use DDD\Domain\AI\Repo\DB\Conversations\Goals\DBAgentGoals;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

/**
 * THE goal port of the agent: every read and write of the goal register goes through this store — the goal rules
 * ({@see AgentGoalsService}, whose {@see AgentGoalsService::persistGoal()} is the one write path), the goal tools
 * ({@see \DDD\Presentation\Mcp\Client\Agents\AgentGoalsMCPPackage}) and the evaluation purge. Nothing outside the store,
 * its repositories and its entities names the package's goal table or one of its columns.
 *
 * The default answers from the package's table (`AgentGoals`): reads by scope (an account plus an optional space, as
 * the memory store), the row write, and the bitemporal closing of a row. While that table does not exist yet
 * ({@see isGoalStoreAvailable()}) the tools answer "not available" and nothing is read or written.
 *
 * A host that keeps goals in rows of its own puts a same-path subclass of this service under its root namespace and
 * answers the port's methods from its rows; {@see AgentGoal} is the carrier it fills (its `id` is the host row's id,
 * `accountId` / `agentSpaceId` the scope), and the scope arguments stay `(int $accountId, ?int $agentSpaceId)`, an empty
 * space meaning account level. The port: {@see isGoalStoreAvailable()}, {@see findCurrentGoalsForScope()}, `find()`,
 * {@see writeGoal()}, {@see closeValidityWindow()}, {@see recordSuccessorOfClosedGoal()},
 * {@see invalidateCurrentGoalsOfScope()} and {@see getColumnsNotPurgedByEvalPurge()}. The rules (dedup, initial status,
 * parents, transitions) stay in {@see AgentGoalsService} and apply to a host store as well. The host switches goals on
 * (`AI.agentGoals.enabled`) without creating the package's table.
 *
 * @method AgentGoal|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentGoals findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentGoal getEntityRepoClassInstance()
 * @method DBAgentGoals getEntitySetRepoClassInstance()
 */
class AgentGoalStoreService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentGoal::class;

    /** @var int How long a "table missing" answer is kept in a worker before the catalog is asked again */
    public const int MISSING_TABLE_RECHECK_SECONDS = 60;

    /** @var bool|null Whether the goal table exists, per process; null = not asked yet */
    protected static ?bool $goalTableAvailable = null;

    /** @var int When {@see $goalTableAvailable} was last asked (unix seconds) */
    protected static int $goalTableCheckedAt = 0;

    /**
     * Whether the store can read and write goals: by default, whether the package's table `AgentGoals` exists in the
     * connected database (MariaDB or PostgreSQL). A yes is kept for the life of the process; a no (also a failed
     * question) is asked again after {@see MISSING_TABLE_RECHECK_SECONDS}, so a worker picks the table up once the DDL
     * ran. A host store backed by its own rows answers true (or its own check).
     *
     * @return bool
     */
    public function isGoalStoreAvailable(): bool
    {
        if (static::$goalTableAvailable === true
            || (static::$goalTableAvailable === false && time() - static::$goalTableCheckedAt < static::MISSING_TABLE_RECHECK_SECONDS)) {
            return static::$goalTableAvailable;
        }
        static::$goalTableAvailable = $this->countGoalTablesInDatabase() > 0;
        static::$goalTableCheckedAt = time();
        return static::$goalTableAvailable;
    }

    /** Forget the cached table check (tests, and a host that runs the DDL inside a long process). */
    public static function forgetGoalTableCheck(): void
    {
        static::$goalTableAvailable = null;
        static::$goalTableCheckedAt = 0;
    }

    /**
     * The current goals of the scope, the highest priority first.
     *
     * @param int $accountId
     * @param int|null $agentSpaceId Empty = the account-level goals only
     * @param bool $heldOnly Only the goals that are still live (ACTIVE, PROPOSED, BLOCKED)
     * @param int|null $limit
     * @return AgentGoals
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCurrentGoalsForScope(int $accountId, ?int $agentSpaceId = null, bool $heldOnly = false, ?int $limit = null): AgentGoals
    {
        return $this->getEntitySetRepoClassInstance()->findCurrentGoalsForScope($accountId, $agentSpaceId, $heldOnly, $limit)
            ?? AgentGoals::newInstance();
    }

    /**
     * Writes the goal row as it is (insert or update); the rules ran before ({@see AgentGoalsService::persistGoal()},
     * {@see AgentGoalsService::transitionGoalStatus()}).
     *
     * @param AgentGoal $agentGoal
     * @return AgentGoal
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function writeGoal(AgentGoal $agentGoal): AgentGoal
    {
        return $this->update($agentGoal, 0);
    }

    /**
     * Closes the current row `$targetGoalId`: one guarded UPDATE, so a row another writer closed first stays as it is.
     * `$successorGoalId` names the row that replaces it; null when the successor is written afterwards
     * ({@see recordSuccessorOfClosedGoal()}).
     *
     * @param int $targetGoalId
     * @param int|null $successorGoalId
     * @param DateTime $validTo
     * @param DateTime|null $invalidatedAt
     * @return int The number of rows closed (0 or 1)
     */
    public function closeValidityWindow(int $targetGoalId, ?int $successorGoalId, DateTime $validTo, ?DateTime $invalidatedAt = null): int
    {
        $tableName = DBAgentGoalModel::getTableName();
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            "UPDATE `{$tableName}` SET `validTo` = :validTo, `invalidatedAt` = :invalidatedAt, `supersededByGoalId` = :successorId"
            . ' WHERE `id` = :targetId AND `validTo` IS NULL AND `invalidatedAt` IS NULL',
            [
                'validTo' => $validTo->format('Y-m-d H:i:s'),
                'invalidatedAt' => ($invalidatedAt ?? new DateTime('now'))->format('Y-m-d H:i:s'),
                'successorId' => $successorGoalId,
                'targetId' => $targetGoalId,
            ]
        );
    }

    /**
     * Points the closed row `$closedGoalId` at the row that replaced it, when it points at none yet: the second step of
     * a correction, whose old row is closed before the new one is written (both carry the same statement, and only one
     * current row per scope and statement may exist).
     *
     * @param int $closedGoalId
     * @param int $successorGoalId
     * @return int The number of rows changed (0 or 1)
     */
    public function recordSuccessorOfClosedGoal(int $closedGoalId, int $successorGoalId): int
    {
        $tableName = DBAgentGoalModel::getTableName();
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            "UPDATE `{$tableName}` SET `supersededByGoalId` = :successorId WHERE `id` = :closedId AND `supersededByGoalId` IS NULL",
            ['successorId' => $successorGoalId, 'closedId' => $closedGoalId]
        );
    }

    /**
     * Invalidates every current goal of exactly this scope (the space's own goals, or the account-level goals when
     * `$agentSpaceId` is empty); the rows stay as history. For a host's housekeeping (a test account reset between
     * evaluation runs); no tool calls it.
     *
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @return int The number of rows invalidated
     */
    public function invalidateCurrentGoalsOfScope(int $accountId, ?int $agentSpaceId): int
    {
        $scopeGoal = AgentGoal::newInstance();
        $scopeGoal->anchorToScope($accountId, $agentSpaceId);
        $now = (new DateTime('now'))->format('Y-m-d H:i:s');
        $tableName = DBAgentGoalModel::getTableName();
        return (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            "UPDATE `{$tableName}` SET `validTo` = :closedAt, `invalidatedAt` = :closedAt"
            . ' WHERE `accountId` = :accountId AND `scopeKey` = :scopeKey AND `validTo` IS NULL AND `invalidatedAt` IS NULL',
            ['closedAt' => $now, 'accountId' => $accountId, 'scopeKey' => $scopeGoal->deriveScopeKey()]
        );
    }

    /**
     * The goal columns that point at a conversation or its rows and are deliberately NOT a step of the evaluation
     * purge, `table.column` => why ({@see \DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService::getColumnsNotPurged()}).
     * The default: the package's table, whatever the switch says (its model exists in every installation). A host
     * store backed by its own rows names its columns, or returns [].
     *
     * @return string[] `table.column` => reason
     */
    public function getColumnsNotPurgedByEvalPurge(): array
    {
        return [
            DBAgentGoalModel::getTableName() . '.sourceMessageId' => 'a plain number recording where a goal was stated; a goal outlives its message',
        ];
    }

    /**
     * How many tables named like the goal table the connected database holds (0 or 1); 0 when the question fails (no
     * connection): the store is then not available and the tools say so.
     *
     * @return int
     */
    protected function countGoalTablesInDatabase(): int
    {
        try {
            $connection = EntityManagerFactory::getInstance()->getConnection();
            $schemaFunction = EntityManagerFactory::getDialect()->name() === SqlDialect::POSTGRES ? 'current_schema()' : 'DATABASE()';
            return (int)$connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = {$schemaFunction} AND table_name = :tableName",
                ['tableName' => DBAgentGoalModel::getTableName()]
            );
        } catch (Throwable) {
            return 0;
        }
    }
}
