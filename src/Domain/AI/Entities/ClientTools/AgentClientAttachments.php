<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientAttachments;
use DDD\Domain\AI\Services\ClientTools\AgentClientAttachmentsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property AgentClientAttachment[] $elements;
 * @method AgentClientAttachment getByUniqueKey(string $uniqueKey)
 * @method AgentClientAttachment first()
 * @method static AgentClientAttachmentsService getService()
 * @method AgentClientAttachment[] getElements()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentClientAttachments::class)]
#[QueryOptions]
class AgentClientAttachments extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AgentClientAttachmentsService::class;
}
