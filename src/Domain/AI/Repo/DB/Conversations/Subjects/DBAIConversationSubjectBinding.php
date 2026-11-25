<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Subjects;

use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectBinding;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Services\DDDService;

/**
 * The subject rows of purposed conversations: readable exactly when the conversation is (its account), through
 * {@see AgentAccountRightsService::applyAccountReadRights()} on the joined conversation.
 */
#[EntityCache(useExtendedRegistryCache: false, ttl: 0)]
class DBAIConversationSubjectBinding extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AIConversationSubjectBinding::class;
    public const string BASE_ORM_MODEL = DBAIConversationSubjectBindingModel::class;

    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, static::getBaseModelAlias(), 'aiConversation');
    }

    public static function applyUpdateRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyReadRightsQuery($queryBuilder);
    }

    public static function applyDeleteRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        return static::applyReadRightsQuery($queryBuilder);
    }
}
