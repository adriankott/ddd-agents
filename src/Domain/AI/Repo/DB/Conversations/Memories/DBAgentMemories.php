<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The set repository of {@see AgentMemory}. Every read takes the scope as an account id plus an optional space id:
 * with a space it reads the account-level memories of the account and the memories of that space, without one the
 * account-level memories only (never those of the account's spaces).
 *
 * @method AgentMemories|null find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAgentMemories extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentMemory::class;
    public const string BASE_ENTITY_SET_CLASS = AgentMemories::class;

    /**
     * The current memories of the scope, newest first, optionally of one type and at most `$limit`.
     *
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param string|null $memoryType
     * @param int|null $limit
     * @param bool $useEntityRegistryCache
     * @return AgentMemories|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCurrentMemoriesForScope(
        int $accountId,
        ?int $agentSpaceId = null,
        ?string $memoryType = null,
        ?int $limit = null,
        bool $useEntityRegistryCache = true
    ): ?AgentMemories {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $this->applyScope($queryBuilder, $alias, $accountId, $agentSpaceId);
        $this->applyCurrentValidity($queryBuilder, $alias);
        $queryBuilder->orderBy("$alias.id", 'DESC');
        if ($memoryType !== null) {
            $queryBuilder->andWhere("$alias.memoryType = :memoryType")->setParameter('memoryType', $memoryType);
        }
        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }
        return $this->find($queryBuilder, $useEntityRegistryCache);
    }

    /**
     * The current memories of the scope nearest to `$queryEmbedding` (cosine distance), at most `$limit`; rows with the
     * zero vector (never embedded) are left out. An empty query embedding answers an empty set.
     *
     * @param TextEmbedding $queryEmbedding
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param int $limit
     * @param string|null $memoryType
     * @return AgentMemories|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findNearestByEmbedding(
        TextEmbedding $queryEmbedding,
        int $accountId,
        ?int $agentSpaceId = null,
        int $limit = 10,
        ?string $memoryType = null
    ): ?AgentMemories {
        if ($queryEmbedding->isEmpty()) {
            return AgentMemories::newInstance();
        }
        $queryVectorValues = $queryEmbedding->vectorValues ?? [];
        $searchVector = '[' . implode(',', $queryVectorValues) . ']';
        $zeroVector = '[' . implode(',', array_fill(0, count($queryVectorValues), '0')) . ']';

        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $this->applyScope($queryBuilder, $alias, $accountId, $agentSpaceId);
        $this->applyCurrentValidity($queryBuilder, $alias);
        $queryBuilder
            ->andWhere("$alias.embedding != VEC_FROM_TEXT(:zeroVector)")
            ->addOrderBy("COSINE_DISTANCE($alias.embedding, VEC_FROM_TEXT(:searchVector))", 'ASC')
            ->setParameter('zeroVector', $zeroVector)
            ->setParameter('searchVector', $searchVector)
            ->setMaxResults($limit);
        if ($memoryType !== null) {
            $queryBuilder->andWhere("$alias.memoryType = :memoryType")->setParameter('memoryType', $memoryType);
        }
        return $this->find($queryBuilder);
    }

    /**
     * The current memories of the scope whose content contains `$searchText` (case-insensitive), newest first: the
     * search without an embedding provider.
     *
     * @param string $searchText
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param int $limit
     * @param string|null $memoryType
     * @return AgentMemories|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findContainingText(
        string $searchText,
        int $accountId,
        ?int $agentSpaceId = null,
        int $limit = 10,
        ?string $memoryType = null
    ): ?AgentMemories {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $this->applyScope($queryBuilder, $alias, $accountId, $agentSpaceId);
        $this->applyCurrentValidity($queryBuilder, $alias);
        $queryBuilder
            ->andWhere("LOWER($alias.content) LIKE :searchPattern")
            ->setParameter('searchPattern', '%' . addcslashes(mb_strtolower(trim($searchText)), '%_\\') . '%')
            ->orderBy("$alias.id", 'DESC')
            ->setMaxResults($limit);
        if ($memoryType !== null) {
            $queryBuilder->andWhere("$alias.memoryType = :memoryType")->setParameter('memoryType', $memoryType);
        }
        return $this->find($queryBuilder);
    }

    /**
     * The current memory of exactly this scope (the space, or the account level when `$agentSpaceId` is empty) with
     * this content hash, or null.
     *
     * @param int $accountId
     * @param int|null $agentSpaceId
     * @param string $contentHash
     * @return AgentMemory|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCurrentMemoryByContentHash(int $accountId, ?int $agentSpaceId, string $contentHash): ?AgentMemory
    {
        $alias = static::getBaseModelAlias();
        $queryBuilder = static::createQueryBuilder();
        $queryBuilder->andWhere("$alias.accountId = :accountId")->setParameter('accountId', $accountId);
        if ($agentSpaceId !== null) {
            $queryBuilder->andWhere("$alias.agentSpaceId = :agentSpaceId")->setParameter('agentSpaceId', $agentSpaceId);
        } else {
            $queryBuilder->andWhere("$alias.agentSpaceId IS NULL");
        }
        $this->applyCurrentValidity($queryBuilder, $alias);
        $queryBuilder
            ->andWhere("$alias.contentHash = :contentHash")
            ->setParameter('contentHash', $contentHash)
            ->orderBy("$alias.id", 'DESC');
        return $this->find($queryBuilder)?->first();
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

    /**
     * Only current rows: neither closed nor invalidated.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $alias
     * @return void
     */
    protected function applyCurrentValidity(DoctrineQueryBuilder $queryBuilder, string $alias): void
    {
        $queryBuilder->andWhere("$alias.validTo IS NULL")->andWhere("$alias.invalidatedAt IS NULL");
    }
}
