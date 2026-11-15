<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;

/**
 * The conversations service of an inspector test: finds no conversation, and records per lookup the id and whether
 * soft-deleted messages were switched on at that moment. Reads nothing.
 */
class ConversationsServiceRecordingLookupsDouble extends AIConversationsService
{
    /** @var array<int, array{conversationId: int, includeTruncated: bool}> The lookups in order */
    public array $lookups = [];

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $this->lookups[] = ['conversationId' => (int)$entityId, 'includeTruncated' => DBAIConversationMessage::$includeTruncated];
        return null;
    }
}
