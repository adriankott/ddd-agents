<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionReplay;

use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;

/** The conversations service answering a lookup with the conversation the test scripted for the id; reads nothing. */
class ConversationsServiceWithScriptedConversationsDouble extends AIConversationsService
{
    /** @var array<int, Entity> The conversations by id */
    public array $conversationsById = [];

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->conversationsById[(int)$entityId] ?? null;
    }
}
