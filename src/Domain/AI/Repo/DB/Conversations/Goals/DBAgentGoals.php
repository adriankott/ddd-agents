<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Goals;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoals;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The set repository of {@see AgentGoal}. Every read takes the scope as an account id plus an optional space id, as the
 * memory store does: with a space it reads the account-level goals of the account and the goals of that space, without
 * one the account-level goals only (never those of the account's spaces).
 *
 * @method AgentGoals|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAgentGoals extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentGoal::class;
    public const string BASE_ENTITY_SET_CLASS = AgentGoals::class;

    /**
     * The current goals of the scope, the highest priority first, then the oldest first; `$heldOnly` keeps the goals
     * that are still live (ACTIVE, PROPOSED, BLOCKED).
     *
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param bool $heldOnly
     * @param int|null $limit
     * @return AgentGoals|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCurrentGoalsForScope(int $accountId, ?int $agentSpaceId = null, bool $heldOnly = false, ?int $limit = null): ?AgentGoals
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $this->applyScope($queryBuilder, $alias, $accountId, $agentSpaceId);
        $queryBuilder->andWhere("$alias.validTo IS NULL")->andWhere("$alias.invalidatedAt IS NULL");
        if ($heldOnly) {
            $queryBuilder->andWhere("$alias.status IN (:heldStatuses)")->setParameter('heldStatuses', AgentGoal::HELD_STATUSES);
        }
        $queryBuilder->orderBy("$alias.priority", 'DESC')->addOrderBy("$alias.id", 'ASC');
        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }
        return $this->find($queryBuilder, false);
    }

    /**
     * The scope condition: the account's account-level rows, plus the rows of `$agentSpaceId` when one is given.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $alias
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @return void
     */
    protected function applyScope(DoctrineQueryBuilder $queryBuilder, string $alias, int $accountId, ?int $agentSpaceId): void
    {
        $queryBuilder->andWhere("$alias.accountId = :scopeAccountId")->setParameter('scopeAccountId', $accountId);
        if ($agentSpaceId === null) {
            $queryBuilder->andWhere("$alias.agentSpaceId IS NULL");
            return;
        }
        $queryBuilder
            ->andWhere("($alias.agentSpaceId IS NULL OR $alias.agentSpaceId = :scopeAgentSpaceId)")
            ->setParameter('scopeAgentSpaceId', $agentSpaceId);
    }
}
