<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;

/**
 * The conversations service the recovery cron reads its candidates from, answering from scripted rows; the marker
 * writes are recorded in the pass log instead of stored. Records the caps and windows the cron asked with.
 */
class OwedRunsConversationsServiceDouble extends AIConversationsService
{
    /** @var Entity[] The conversations, by id */
    public array $conversationsById = [];

    /** @var int[] */
    public array $conversationIdsOwingReports = [];

    /** @var int[] */
    public array $conversationIdsOwingContinuations = [];

    /** @var int[] */
    public array $conversationIdsWithInboundLeftIdle = [];

    /** @var string[] The caps and windows asked, as `<finder>:<quiet seconds>/<limit>` */
    public array $askedSelections = [];

    /**
     * @param RecoveryPassLog $recoveryPassLog
     */
    public function __construct(public RecoveryPassLog $recoveryPassLog)
    {
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->conversationsById[(int)$entityId] ?? null;
    }

    public function findConversationIdsOwingReports(int $limit = self::OWED_CONVERSATIONS_BATCH_LIMIT): array
    {
        $this->askedSelections[] = "reports:-/{$limit}";
        return $this->conversationIdsOwingReports;
    }

    public function findConversationIdsOwingContinuations(
        int $quietSeconds = self::AGENT_TURN_OWED_QUIET_SECONDS,
        int $limit = self::OWED_CONVERSATIONS_BATCH_LIMIT
    ): array {
        $this->askedSelections[] = "continuations:{$quietSeconds}/{$limit}";
        return $this->conversationIdsOwingContinuations;
    }

    public function findConversationIdsWithInboundLeftIdle(
        int $quietSeconds = self::AGENT_TURN_OWED_QUIET_SECONDS,
        int $limit = self::OWED_CONVERSATIONS_BATCH_LIMIT,
        int $lookbackSeconds = self::LOST_RESUME_LOOKBACK_SECONDS
    ): array {
        $this->askedSelections[] = "inboundLeftIdle:{$quietSeconds}/{$limit}";
        return $this->conversationIdsWithInboundLeftIdle;
    }

    public function clearAgentTurnOwed(AIConversation $conversation): void
    {
        $this->recoveryPassLog->record("clearMarker:{$conversation->id}");
    }

    public function deferOwedTurn(AIConversation $conversation): void
    {
        $this->recoveryPassLog->record("deferTurn:{$conversation->id}");
    }
}
