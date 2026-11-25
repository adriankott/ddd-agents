<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Services\Conversations\AIConversationDelegationBatchesService;

/**
 * The delegation batches of the release stage: "the oldest batch whose answers are unread" and "an unfinished batch"
 * answer a scripted queue, in call order (an empty queue answers none / no); the read mark is recorded.
 */
class RunReleaseDelegationBatchesServiceDouble extends AIConversationDelegationBatchesService
{
    public RunReleaseEventLog $eventLog;

    /** @var array<int, int|null> the answers of {@see findOldestBatchIdWithUnreadChildResultsForParent()}, in call order */
    public array $unreadBatchIdAnswers = [];

    /** @var bool[] the answers of {@see hasUnfinishedBatchForParent()}, in call order */
    public array $unfinishedBatchAnswers = [];

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function findOldestBatchIdWithUnreadChildResultsForParent(int $parentConversationId): ?int
    {
        return $this->unreadBatchIdAnswers === [] ? null : array_shift($this->unreadBatchIdAnswers);
    }

    public function hasUnfinishedBatchForParent(int $parentConversationId): bool
    {
        return $this->unfinishedBatchAnswers !== [] && (bool)array_shift($this->unfinishedBatchAnswers);
    }

    public function markChildResultsReadByParent(int $batchId): void
    {
        $this->eventLog->record('markChildResultsReadByParent batch=' . $batchId);
    }
}
