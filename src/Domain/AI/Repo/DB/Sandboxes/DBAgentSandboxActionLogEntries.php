<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntries;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AgentSandboxActionLogEntries find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAgentSandboxActionLogEntries extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAgentSandboxActionLogEntry::class;
    public const string BASE_ENTITY_SET_CLASS = AgentSandboxActionLogEntries::class;
}
