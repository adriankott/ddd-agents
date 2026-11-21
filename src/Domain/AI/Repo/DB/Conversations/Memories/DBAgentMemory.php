<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Services\DDDService;

/**
 * The repository of {@see AgentMemory}: an account reads its own memories (the account-level ones and those of its
 * spaces), a reader of every agent row reads all.
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAgentMemory extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentMemory::class;
    public const string BASE_ORM_MODEL = DBAgentMemoryModel::class;

    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, static::getBaseModelAlias());
    }
}
