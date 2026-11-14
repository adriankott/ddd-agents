<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationRunEndNotification;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use RuntimeException;

/**
 * The run-end notifier with its writes untouched and what leaves the database replaced by recorders: the resume of
 * the parent and the realtime signal. On request the write fails after the notification row was written, as a lost
 * connection or a refused statement would end it. On request a second session tries to change the parent's
 * conversation row while the write is open, as a worker writing the parent's summary would.
 */
class DelegationNotificationsServiceWithRecordedParentResume extends AIConversationDelegationNotificationsService
{
    public RecordingRealtimeSignalService $recordingRealtimeSignalService;

    /** @var int[] The id of every parent whose resume was dispatched, in call order */
    public array $resumedParentConversationIds = [];

    /** Whether the write throws after the compare-and-set and the notification row were written */
    public bool $failsAfterTheNotificationRowWasWritten = false;

    /** The session of another worker that tries to change the parent's conversation row during the write; null = none */
    public ?Connection $connectionOfAnotherWorker = null;

    /** The dialect the other worker's lock timeout is set through */
    public ?SqlDialect $sqlDialect = null;

    /** Whether the other worker could change the parent's conversation row during the write; null = not tried */
    public ?bool $anotherWorkerCouldChangeTheParentRowDuringTheWrite = null;

    public function __construct()
    {
        $this->recordingRealtimeSignalService = new RecordingRealtimeSignalService();
    }

    protected function writeNotificationRowIfUnreported(AIConversation $parentConversation, int $delegateCallMessageId, int $runNumber): ?DelegationRunEndNotification
    {
        if ($this->connectionOfAnotherWorker !== null && $this->sqlDialect !== null) {
            $this->anotherWorkerCouldChangeTheParentRowDuringTheWrite = $this->canAnotherWorkerChangeTheConversationRow((int)$parentConversation->id);
        }
        $notification = parent::writeNotificationRowIfUnreported($parentConversation, $delegateCallMessageId, $runNumber);
        if ($this->failsAfterTheNotificationRowWasWritten) {
            throw new RuntimeException('The write failed after the notification row was written.');
        }
        return $notification;
    }

    /** Whether the other worker can change the conversation row within a second; its change is rolled back. */
    protected function canAnotherWorkerChangeTheConversationRow(int $conversationId): bool
    {
        $this->connectionOfAnotherWorker->beginTransaction();
        try {
            $this->sqlDialect->setTransactionTimeouts($this->connectionOfAnotherWorker, 1, null);
            $this->connectionOfAnotherWorker->executeStatement(
                'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runToken` = ? WHERE `id` = ?',
                ['token-of-another-worker', $conversationId]
            );
            return true;
        } catch (LockWaitTimeoutException) {
            return false;
        } finally {
            $this->connectionOfAnotherWorker->rollBack();
            $this->sqlDialect->clearTransactionTimeouts($this->connectionOfAnotherWorker);
        }
    }

    protected function dispatchParentResume(int $parentConversationId): void
    {
        $this->resumedParentConversationIds[] = $parentConversationId;
    }

    protected function realtimeSignalService(): AIConversationRealtimeSignalService
    {
        return $this->recordingRealtimeSignalService;
    }
}
