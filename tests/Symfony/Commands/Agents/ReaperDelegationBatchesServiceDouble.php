<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationDelegationBatch;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;
use DDD\Domain\Base\Entities\Entity;

/**
 * The delegation batches service the reaper reads from, answering from scripted rows; writes nothing. A batch's
 * timeout answers the parent to wake that the test scripted, and the call is recorded in the shared pass log.
 */
class ReaperDelegationBatchesServiceDouble extends AIConversationDelegationBatchesService
{
    /** @var int[] What the timed-out query answers */
    public array $timedOutBatchIds = [];

    /** @var int[] What the unfolded-parents query answers */
    public array $settledUnfoldedParentIds = [];

    /** @var int[] What the unreported sub-agent run query answers */
    public array $unreportedDeadlineIds = [];

    /** @var array<int, int> The parent conversation of each batch, by batch id */
    public array $parentConversationIdsByBatchId = [];

    /** @var array<int, int|null> What the timeout of a batch answers, by batch id */
    public array $wokenParentIdsByTimedOutBatchId = [];

    /**
     * @param RecoveryPassLog $recoveryPassLog
     */
    public function __construct(public RecoveryPassLog $recoveryPassLog)
    {
    }

    public function findTimedOutUnfinishedBatchIds(): array
    {
        return $this->timedOutBatchIds;
    }

    public function findParentConversationIdsWithUnreadChildResults(): array
    {
        return $this->settledUnfoldedParentIds;
    }

    public function findEndedUnreportedSubAgentRunDeadlineIds(?AIConversation $parentConversation = null): array
    {
        return $this->unreportedDeadlineIds;
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        if (!isset($this->parentConversationIdsByBatchId[(int)$entityId])) {
            return null;
        }
        $batch = new AIConversationDelegationBatch();
        $batch->id = (int)$entityId;
        $batch->parentConversationId = $this->parentConversationIdsByBatchId[(int)$entityId];
        return $batch;
    }

    public function timeoutBatch(int $batchId): ?int
    {
        $this->recoveryPassLog->record("timeout:{$batchId}");
        return $this->wokenParentIdsByTimedOutBatchId[$batchId] ?? null;
    }
}
