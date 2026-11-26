<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Goals;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Services\DDDService;

/**
 * The repository of {@see AgentGoal}: an account reads its own goals (the account-level ones and those of its spaces),
 * a reader of every agent row reads all — the rights of the memory table.
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAgentGoal extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentGoal::class;
    public const string BASE_ORM_MODEL = DBAgentGoalModel::class;

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
