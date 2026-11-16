<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Delegation\Admission;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAdmission;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The capacity lock of a delegation tree: an attempt that answers at once without the lock (GET_LOCK NULL, or 0 without
 * waiting) is repeated within the wait and never read as "another admission holds it"; an attempt that waited the wait
 * out is a timeout. The outcome is on the admission, never a silent "queued".
 */
class DelegationCapacityLockOutcomeTest extends UnitTestCase
{
    public function testTheLockObtainedAtTheFirstAttempt(): void
    {
        $runClaimsService = new RunClaimsServiceWithScriptedCapacityLock();
        $runClaimsService->scriptedLockAttempts = [[true, 0.01]];

        $agentDelegationAdmission = $runClaimsService->acquireWithinTheWait();

        self::assertSame(AgentDelegationAdmission::LOCK_OBTAINED, $agentDelegationAdmission->lockOutcome);
        self::assertSame(1, $agentDelegationAdmission->lockAttempts);
        self::assertSame([5], $runClaimsService->waitSecondsOfAttempts);
    }

    public function testAnAttemptThatAnsweredAtOnceIsRepeatedWithinTheWait(): void
    {
        $runClaimsService = new RunClaimsServiceWithScriptedCapacityLock();
        $runClaimsService->scriptedLockAttempts = [[false, 0.0], [false, 0.01], [true, 0.01]];

        $agentDelegationAdmission = $runClaimsService->acquireWithinTheWait();

        self::assertSame(AgentDelegationAdmission::LOCK_OBTAINED_AFTER_RETRY, $agentDelegationAdmission->lockOutcome);
        self::assertSame(3, $agentDelegationAdmission->lockAttempts);
        self::assertTrue($agentDelegationAdmission->isLockObtained());
    }

    public function testEveryAttemptAnsweringAtOnceIsARefusalAfterTheWait(): void
    {
        $runClaimsService = new RunClaimsServiceWithScriptedCapacityLock();
        $runClaimsService->pauseSeconds = 1.0;

        $agentDelegationAdmission = $runClaimsService->acquireWithinTheWait();

        self::assertSame(AgentDelegationAdmission::LOCK_REFUSED, $agentDelegationAdmission->lockOutcome);
        self::assertSame(5, $agentDelegationAdmission->lockAttempts, 'one attempt per pause until the 5 s wait has passed');
        self::assertSame([5, 4, 3, 2, 1], $runClaimsService->waitSecondsOfAttempts, 'each attempt gets the remaining wait');
        self::assertFalse($agentDelegationAdmission->isLockObtained());
    }

    public function testAnAttemptThatWaitedTheWaitOutIsATimeout(): void
    {
        $runClaimsService = new RunClaimsServiceWithScriptedCapacityLock();
        $runClaimsService->scriptedLockAttempts = [[false, 5.0]];

        $agentDelegationAdmission = $runClaimsService->acquireWithinTheWait();

        self::assertSame(AgentDelegationAdmission::LOCK_TIMED_OUT, $agentDelegationAdmission->lockOutcome);
        self::assertSame(1, $agentDelegationAdmission->lockAttempts);
    }
}
