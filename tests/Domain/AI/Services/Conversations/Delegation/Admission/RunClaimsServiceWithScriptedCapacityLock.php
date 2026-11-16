<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Delegation\Admission;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAdmission;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use Doctrine\DBAL\Connection;

/**
 * The run-claims service whose capacity-lock attempts answer from a script, on a clock the attempts move: each scripted
 * attempt is `[obtained, secondsItTakes]`; a missing entry answers at once without the lock.
 */
class RunClaimsServiceWithScriptedCapacityLock extends AIConversationRunClaimsService
{
    /** @var array<int, array{0: bool, 1: float}> the scripted attempts, in order */
    public array $scriptedLockAttempts = [];

    /** @var int[] the wait each attempt was given, in order */
    public array $waitSecondsOfAttempts = [];

    public float $clockSeconds = 1000.0;

    /** The clock moves by this much per pause before a retry. */
    public float $pauseSeconds = 0.1;

    public function acquireWithinTheWait(): AgentDelegationAdmission
    {
        $agentDelegationAdmission = AgentDelegationAdmission::forTree(900, self::DELEGATION_CONCURRENCY_CAP);
        $this->acquireDelegationCapacityLockWithinTheWait($this->buildConnectionThatRunsNoStatement(), 'delegation-capacity-900', $agentDelegationAdmission);
        return $agentDelegationAdmission;
    }

    protected function acquireDelegationCapacityLockOnce(Connection $connection, string $lockName, int $timeoutSeconds): bool
    {
        $this->waitSecondsOfAttempts[] = $timeoutSeconds;
        [$obtained, $secondsItTakes] = array_shift($this->scriptedLockAttempts) ?? [false, 0.0];
        $this->clockSeconds += $secondsItTakes;
        return $obtained;
    }

    protected function readMonotonicSeconds(): float
    {
        return $this->clockSeconds;
    }

    protected function pauseBeforeDelegationCapacityLockRetry(): void
    {
        $this->clockSeconds += $this->pauseSeconds;
    }

    /** A connection no statement reaches: the scripted attempt never uses it. */
    protected function buildConnectionThatRunsNoStatement(): Connection
    {
        return (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
    }
}
