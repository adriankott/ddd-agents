<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Voice\Calls;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Repo\DB\Attributes\EntityCache;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Services\DDDService;

/**
 * @method AgentVoiceCall find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistrCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentVoiceCall update(AgentVoiceCall &$entity, int $depth = 1)
 * @property DBAgentVoiceCallModel $ormObject
 */
#[EntityCache]
class DBAgentVoiceCall extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AgentVoiceCall::class;
    public const string BASE_ORM_MODEL = DBAgentVoiceCallModel::class;

    /**
     * A call is read under the read rights of the conversation it runs on: the account of that conversation, and an
     * account the application lets read every agent row (its oversight role). The same rule, through the same
     * service, as the messages and trace events of a conversation. A call always has its conversation (from the open
     * of a session or from the ring on); an application whose older rows carry none adds its own branch in its
     * repository at the same path.
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        return $agentAccountRightsService->applyAccountReadRights($queryBuilder, static::getBaseModelAlias(), 'aiConversation');
    }
}
