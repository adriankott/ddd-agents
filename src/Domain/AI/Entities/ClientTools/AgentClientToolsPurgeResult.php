<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one housekeeping pass of the client tool rows did
 * ({@see \DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService::purge()}).
 */
class AgentClientToolsPurgeResult extends ValueObject
{
    /** @var int Requests whose tool stopped waiting, set EXPIRED with their text removed */
    public int $expiredRequestCount = 0;

    /** @var int Requests older than AgentClientToolRequest::KEPT_FOR_DAYS, deleted */
    public int $deletedRequestCount = 0;

    /** @var int Client attachments not seen for longer than the service keeps them, deleted */
    public int $deletedAttachmentCount = 0;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
