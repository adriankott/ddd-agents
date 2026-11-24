<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientAttachments;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentClientAttachments find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentClientAttachments extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentClientAttachment::class;

    public const string BASE_ENTITY_SET_CLASS = AgentClientAttachments::class;
}
