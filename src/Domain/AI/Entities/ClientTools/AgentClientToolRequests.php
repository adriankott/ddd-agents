<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientToolRequests;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property AgentClientToolRequest[] $elements;
 * @method AgentClientToolRequest getByUniqueKey(string $uniqueKey)
 * @method AgentClientToolRequest first()
 * @method static AgentClientToolRequestsService getService()
 * @method AgentClientToolRequest[] getElements()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentClientToolRequests::class)]
#[QueryOptions]
class AgentClientToolRequests extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AgentClientToolRequestsService::class;
}
