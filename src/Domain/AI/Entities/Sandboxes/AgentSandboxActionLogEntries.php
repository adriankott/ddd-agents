<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\AI\Repo\DB\Sandboxes\DBAgentSandboxActionLogEntries;
use DDD\Domain\AI\Services\Sandboxes\AgentSandboxActionLogEntriesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Audit entries of workspace actions, newest first.
 *
 * @property AgentSandboxActionLogEntry[] $elements
 * @method AgentSandboxActionLogEntry|null first()
 * @method AgentSandboxActionLogEntry|null getByUniqueKey(string $uniqueKey)
 * @method AgentSandboxActionLogEntry[] getElements()
 * @method static AgentSandboxActionLogEntriesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentSandboxActionLogEntries::class)]
class AgentSandboxActionLogEntries extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AgentSandboxActionLogEntriesService::class;
}
