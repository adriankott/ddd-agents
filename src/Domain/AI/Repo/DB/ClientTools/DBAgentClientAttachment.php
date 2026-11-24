<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientAttachment;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Rights: the Account's own rows, while it holds the right to use the UI tools ({@see AgentClientToolRowRights}).
 *
 * @method AgentClientAttachment find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentClientAttachment update(DefaultObject &$entity, int $depth = 1)
 * @property DBAgentClientAttachmentModel $ormInstance
 */
class DBAgentClientAttachment extends DBEntity
{
    use AgentClientToolRowRights;

    public const string BASE_ENTITY_CLASS = AgentClientAttachment::class;

    public const string BASE_ORM_MODEL = DBAgentClientAttachmentModel::class;
}
