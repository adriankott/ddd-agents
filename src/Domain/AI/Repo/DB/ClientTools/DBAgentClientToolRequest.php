<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Rights: the Account's own rows, while it holds the right to use the UI tools ({@see AgentClientToolRowRights}).
 *
 * @method AgentClientToolRequest find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AgentClientToolRequest update(DefaultObject &$entity, int $depth = 1)
 * @property DBAgentClientToolRequestModel $ormInstance
 */
class DBAgentClientToolRequest extends DBEntity
{
    use AgentClientToolRowRights;

    public const string BASE_ENTITY_CLASS = AgentClientToolRequest::class;

    public const string BASE_ORM_MODEL = DBAgentClientToolRequestModel::class;
}
