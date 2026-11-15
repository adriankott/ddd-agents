<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;
use RuntimeException;

/**
 * The conversations service the reaper reads its candidates from, answering from scripted rows; reads and writes
 * nothing. Records the windows the reaper asked with.
 */
class ReaperConversationsServiceDouble extends AIConversationsService
{
    /** @var Entity[] The conversations, by id */
    public array $conversationsById = [];

    /** @var int[] What the expiry answers */
    public array $expiredConversationIds = [];

    /** @var int[] What the stuck query answers */
    public array $stuckConversationIds = [];

    /** @var int[] What the settle query answers for every purpose */
    public array $quietIdlePurposedConversationIds = [];

    /** @var int[] The windows the reaper passed: revive max age of the expiry, hung-after and revive max age of the stuck query */
    public array $askedWindows = [];

    /** @var int[] The conversations whose lookup throws */
    public array $failingConversationIds = [];

    public const string LOOKUP_FAILURE_MESSAGE = 'the conversation row is locked';

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        if (in_array((int)$entityId, $this->failingConversationIds, true)) {
            throw new RuntimeException(self::LOOKUP_FAILURE_MESSAGE);
        }
        return $this->conversationsById[(int)$entityId] ?? null;
    }

    public function expireStuckConversationsPastMaxAge(int $reviveMaxAgeSeconds = self::RUN_REVIVE_MAX_AGE_SECONDS): array
    {
        $this->askedWindows['expiryReviveMaxAge'] = $reviveMaxAgeSeconds;
        return $this->expiredConversationIds;
    }

    public function findStuckConversationIds(
        int $hungAfterSeconds = AIConversationRunClaimsService::RUN_HUNG_AFTER_SECONDS,
        int $reviveMaxAgeSeconds = self::RUN_REVIVE_MAX_AGE_SECONDS
    ): array {
        $this->askedWindows['stuckHungAfter'] = $hungAfterSeconds;
        $this->askedWindows['stuckReviveMaxAge'] = $reviveMaxAgeSeconds;
        return $this->stuckConversationIds;
    }

    public function findQuietIdlePurposedConversationIds(string $purposeName, int $quietSeconds, int $limit = self::OWED_CONVERSATIONS_BATCH_LIMIT): array
    {
        return $this->quietIdlePurposedConversationIds;
    }
}
