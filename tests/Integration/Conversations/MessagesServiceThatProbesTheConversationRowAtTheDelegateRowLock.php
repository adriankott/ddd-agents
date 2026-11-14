<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;

/**
 * The messages service with its writes untouched. At the moment a writer locks a `delegate` row, a second session
 * tries to change one conversation row, as a worker would that writes into that conversation: the answer tells whether
 * the writer held that conversation's row before it took the `delegate` row.
 */
class MessagesServiceThatProbesTheConversationRowAtTheDelegateRowLock extends AIConversationMessagesService
{
    /** The session of another worker that tries to change the conversation row */
    public Connection $connectionOfAnotherWorker;

    /** The dialect the other worker's lock timeout is set through */
    public SqlDialect $sqlDialect;

    /** The conversation whose row the other worker tries to change */
    public int $probedConversationId;

    /** Whether the other worker could change the conversation row when the `delegate` row was locked; null = no lock taken */
    public ?bool $anotherWorkerCouldChangeTheConversationRowAtTheDelegateRowLock = null;

    public function lockStoredDelegateCallRow(int $delegateCallMessageId): ?AIConversationMessage
    {
        $this->anotherWorkerCouldChangeTheConversationRowAtTheDelegateRowLock = $this->canAnotherWorkerChangeTheConversationRow();
        return parent::lockStoredDelegateCallRow($delegateCallMessageId);
    }

    /** Whether the other worker can change the conversation row within a second; its change is rolled back. */
    protected function canAnotherWorkerChangeTheConversationRow(): bool
    {
        $this->connectionOfAnotherWorker->beginTransaction();
        try {
            $this->sqlDialect->setTransactionTimeouts($this->connectionOfAnotherWorker, 1, null);
            $this->connectionOfAnotherWorker->executeStatement(
                'UPDATE `' . DBAIConversationModel::getTableName() . '` SET `runToken` = ? WHERE `id` = ?',
                ['token-of-another-worker', $this->probedConversationId]
            );
            return true;
        } catch (LockWaitTimeoutException) {
            return false;
        } finally {
            $this->connectionOfAnotherWorker->rollBack();
            $this->sqlDialect->clearTransactionTimeouts($this->connectionOfAnotherWorker);
        }
    }
}
