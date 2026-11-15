<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Scorecards;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;
use RuntimeException;

/**
 * The conversations service answering a lookup with the conversation the test scripted for that id, or throwing for an
 * id the test lists as failing; reads nothing.
 */
class ConversationsServiceWithScriptedConversationsByIdDouble extends AIConversationsService
{
    /** @var array<int, AIConversation> The scripted conversations, by id */
    public array $scriptedConversationsById = [];

    /** @var int[] The ids whose lookup throws */
    public array $failingConversationIds = [];

    public const string LOOKUP_FAILURE_MESSAGE = 'the conversation row is locked';

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        if (in_array((int)$entityId, $this->failingConversationIds, true)) {
            throw new RuntimeException(self::LOOKUP_FAILURE_MESSAGE);
        }
        return $this->scriptedConversationsById[(int)$entityId] ?? null;
    }
}
