<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;

/**
 * The conversations service of a stored judged-run test: storing a conversation gives it an id and keeps it; a read
 * of that id answers a copy of it (what the database would return), counted; nothing reaches a database.
 */
class AIConversationsServiceWithStoredConversationDouble extends AIConversationsServiceRecordingInboundActivityDouble
{
    /** @var int The id the stored conversation gets */
    public const int STORED_CONVERSATION_ID = 4711;

    /** @var AIConversation|null The conversation stored last */
    public ?AIConversation $storedConversation = null;

    /** @var int How often the stored conversation was read back */
    public int $reads = 0;

    public function update(DefaultObject $entity, int $depth = DatabaseRepoEntity::UPDATE_DEFAULT_RECURSIVE_DEPTH): DefaultObject
    {
        if ($entity instanceof AIConversation) {
            $entity->id = static::STORED_CONVERSATION_ID;
            $this->storedConversation = $entity;
        }
        return $entity;
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $this->reads++;
        if ($this->storedConversation === null || (int)$entityId !== static::STORED_CONVERSATION_ID) {
            return null;
        }
        return clone $this->storedConversation;
    }
}
