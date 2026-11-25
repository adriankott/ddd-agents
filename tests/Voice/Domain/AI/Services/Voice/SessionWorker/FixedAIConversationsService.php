<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;

/**
 * The conversations as `AIConversation::getService()` reads them: the session worker reads the call's conversation
 * once (registry cache on) and its fresh run state again and again (cache off); it has no method of its own for either.
 * A cached read answers the conversation the test handed in; a fresh read answers a copy with the next run state of
 * `$freshRunStates` (none left = the conversation's own). Every read is kept.
 */
class FixedAIConversationsService extends AIConversationsService
{
    /** @var array<int, AIConversation> */
    public array $conversationsById = [];

    /** @var string[] The run states the fresh reads answer, in order */
    public array $freshRunStates = [];

    /** @var array<int, array{conversationId: int|string|null, useEntityRegistrCache: bool}> */
    public array $conversationReads = [];

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $this->conversationReads[] = ['conversationId' => $entityId, 'useEntityRegistrCache' => $useEntityRegistrCache];
        $aiConversation = $this->conversationsById[(int)$entityId] ?? null;
        if ($aiConversation === null || $useEntityRegistrCache) {
            return $aiConversation;
        }
        $freshConversation = clone $aiConversation;
        $freshConversation->runState = $this->freshRunStates !== [] ? array_shift($this->freshRunStates) : $aiConversation->runState;

        return $freshConversation;
    }

    /** How many fresh reads (registry cache off) were made */
    public function countFreshReads(): int
    {
        return count(array_filter(
            $this->conversationReads,
            static fn (array $conversationRead): bool => !$conversationRead['useEntityRegistrCache']
        ));
    }
}
