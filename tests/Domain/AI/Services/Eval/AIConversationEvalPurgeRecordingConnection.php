<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver;

/**
 * A connection that never connects: it records the transaction calls and the statements in the order they were
 * made, answers a write with a scripted row count, a read with a scripted value, and throws a scripted error instead
 * when one is set.
 */
class AIConversationEvalPurgeRecordingConnection extends Connection
{
    /** @var string[] `begin`, `commit`, `rollBack` and every statement, in order */
    public array $recordedCalls = [];

    /** @var int The rows a write statement answers */
    public int $writtenRows = 0;

    /** @var \Throwable|null Thrown by the next write statement (a statement that is no `SET`) */
    public ?\Throwable $writeError = null;

    /** @var \Throwable|null Thrown by the next read statement */
    public ?\Throwable $readError = null;

    /** @var int[] The column a `fetchFirstColumn()` read answers */
    public array $firstColumnValues = [];

    /** @var mixed The value a `fetchOne()` read answers */
    public mixed $oneValue = null;

    /**
     * @var int[] The session's lock wait timeout and statement time limit a `fetchNumeric()` read answers: the read the
     *      MariaDB dialect makes before it changes the two limits, to restore them afterwards
     */
    public array $sessionTimeoutValues = [50, 0];

    protected int $openTransactionDepth = 0;

    public function __construct()
    {
        parent::__construct([], new Driver());
    }

    public function beginTransaction()
    {
        $this->recordedCalls[] = 'begin';
        $this->openTransactionDepth++;
        return true;
    }

    public function commit()
    {
        $this->recordedCalls[] = 'commit';
        $this->openTransactionDepth--;
        return true;
    }

    public function rollBack()
    {
        $this->recordedCalls[] = 'rollBack';
        $this->openTransactionDepth--;
        return true;
    }

    public function isTransactionActive()
    {
        return $this->openTransactionDepth > 0;
    }

    public function getTransactionNestingLevel()
    {
        return $this->openTransactionDepth;
    }

    public function isRollbackOnly()
    {
        return false;
    }

    public function executeStatement($sql, array $params = [], array $types = [])
    {
        $this->recordedCalls[] = (string)$sql;
        if (str_starts_with((string)$sql, 'SET ')) {
            return 0;
        }
        if ($this->writeError !== null) {
            $writeError = $this->writeError;
            $this->writeError = null;
            throw $writeError;
        }
        return $this->writtenRows;
    }

    public function fetchFirstColumn(string $query, array $params = [], array $types = []): array
    {
        $this->recordRead($query);
        return $this->firstColumnValues;
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $this->recordRead($query);
        return [];
    }

    public function fetchOne(string $query, array $params = [], array $types = [])
    {
        $this->recordRead($query);
        return $this->oneValue;
    }

    /**
     * The dialect's read of the session limits before it changes them: recorded, answered with
     * {@see $sessionTimeoutValues}; never the purge's own read, so the scripted read error stays for that one.
     */
    public function fetchNumeric(string $query, array $params = [], array $types = [])
    {
        $this->recordedCalls[] = $query;
        return $this->sessionTimeoutValues;
    }

    /** Records a read statement and throws the scripted read error once. */
    protected function recordRead(string $query): void
    {
        $this->recordedCalls[] = $query;
        if ($this->readError !== null) {
            $readError = $this->readError;
            $this->readError = null;
            throw $readError;
        }
    }
}
