<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSessionInterface;
use RuntimeException;

/**
 * A recording session that installs nothing and records the calls a harness makes on it, in order. On demand running
 * the queued messages fails, or reports one message run per turn.
 */
class RecordingAgentRecordedRunSessionDouble implements AgentRecordedRunSessionInterface
{
    public const string QUEUE_FAILURE_MESSAGE = 'a queued message failed';

    /** @var string[] the calls, in order (`beginTurn(1)`, `runQueuedMessages`, `stop(false)`, …) */
    public array $calls = [];

    public bool $runningQueuedMessagesFails = false;

    public int $messagesRunPerCall = 0;

    public function isReplay(): bool
    {
        return false;
    }

    public function readHighWaterMarks(): void
    {
        $this->calls[] = 'readHighWaterMarks';
    }

    public function setScopeIds(array $scopeIdsByName): void
    {
        $this->calls[] = 'setScopeIds';
    }

    public function beginTurn(int $turnOrdinal): void
    {
        $this->calls[] = "beginTurn({$turnOrdinal})";
    }

    public function setRootConversation(AIConversation $rootConversation): void
    {
        $this->calls[] = 'setRootConversation';
    }

    public function describeSharedScopeTables(): ?string
    {
        return null;
    }

    public function start(): void
    {
        $this->calls[] = 'start';
    }

    public function runQueuedMessages(): int
    {
        $this->calls[] = 'runQueuedMessages';
        if ($this->runningQueuedMessagesFails) {
            throw new RuntimeException(self::QUEUE_FAILURE_MESSAGE);
        }
        return $this->messagesRunPerCall;
    }

    public function stop(bool $runRemainingMessages = true): void
    {
        $this->calls[] = 'stop(' . ($runRemainingMessages ? 'true' : 'false') . ')';
    }

    public function concludeRun(AIConversation $rootConversation, array $scopeIdsByName): void
    {
        $this->calls[] = 'concludeRun';
    }

    public function buildRecording(AIConversation $rootConversation, array $scopeIdsByName, array $runFields): array
    {
        return $runFields;
    }

    public function compareWithRecording(AIConversation $rootConversation, array $scopeIdsByName): AgentRecordedRunComparison
    {
        return new AgentRecordedRunComparison();
    }

    public function describeRun(array $snapshot): string
    {
        return '';
    }
}
