<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequests;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentClientToolRequests find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentClientToolRequests extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentClientToolRequest::class;

    public const string BASE_ENTITY_SET_CLASS = AgentClientToolRequests::class;
}
