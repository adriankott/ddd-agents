<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAdmission;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The description of one admission: whether a spawned child was left queued although it could have run, the combined
 * admission of a retry, and the reason the `delegate` answer names — the real count, never the cap.
 */
class AgentDelegationAdmissionTest extends UnitTestCase
{
    public function testAChildLeftQueuedWithAFreeSlotOrWithoutTheLockCouldHaveRun(): void
    {
        $admissionThatDidNotSeeTheChild = AgentDelegationAdmission::forTree(900, 5);
        self::assertTrue($admissionThatDidNotSeeTheChild->leavesChildQueuedWhileSlotCouldBeFree([201]), 'no slot occupied, the child not admitted');

        $fullAdmission = AgentDelegationAdmission::forTree(900, 5);
        $fullAdmission->occupiedSlotCount = 4;
        $fullAdmission->admittedChildIds = [200];
        self::assertFalse($fullAdmission->leavesChildQueuedWhileSlotCouldBeFree([201]), 'every slot is taken');
        self::assertFalse($fullAdmission->leavesChildQueuedWhileSlotCouldBeFree([200]), 'the child was admitted');

        $admissionWithoutTheLock = AgentDelegationAdmission::forTree(900, 5);
        $admissionWithoutTheLock->occupiedSlotCount = 5;
        $admissionWithoutTheLock->lockOutcome = AgentDelegationAdmission::LOCK_TIMED_OUT;
        self::assertTrue($admissionWithoutTheLock->leavesChildQueuedWhileSlotCouldBeFree([201]), 'the count without the lock decides nothing');
    }

    public function testARetryKeepsTheChildrenEitherAdmittedAndTheTakenSlotsOfTheRetry(): void
    {
        $firstAdmission = AgentDelegationAdmission::forTree(900, 5);
        $firstAdmission->occupiedSlotCount = 1;
        $firstAdmission->admittedChildIds = [201];
        $retryAdmission = AgentDelegationAdmission::forTree(900, 5);
        $retryAdmission->occupiedSlotCount = 2; // the first admission's child counts now
        $retryAdmission->queuedChildIds = [202];
        $retryAdmission->admittedChildIds = [202];

        $combinedAdmission = $firstAdmission->withRetry($retryAdmission);

        self::assertSame([201, 202], $combinedAdmission->admittedChildIds);
        self::assertSame(3, $combinedAdmission->getTakenSlotCountAfterAdmission());
        self::assertTrue($combinedAdmission->retried);
        self::assertTrue($combinedAdmission->hasAdmittedChild(201));
    }

    public function testTheQueuedReasonNamesTheRealCountOrTheMissingLock(): void
    {
        $fullAdmission = AgentDelegationAdmission::forTree(900, 5);
        $fullAdmission->occupiedSlotCount = 3;
        $fullAdmission->admittedChildIds = [201, 202];
        self::assertSame('no slot is free: 5 of 5 sub-agent slots of this conversation are taken; it starts when one of them ends', $fullAdmission->buildQueuedReasonText());

        $admissionWithoutTheLock = AgentDelegationAdmission::forTree(900, 5);
        $admissionWithoutTheLock->lockOutcome = AgentDelegationAdmission::LOCK_REFUSED;
        self::assertSame(
            'the admission could not run now, its lock was not obtained; 0 of 5 sub-agent slots of this conversation are taken; it starts at the next admission',
            $admissionWithoutTheLock->buildQueuedReasonText()
        );

        $admissionWithAFreeSlot = AgentDelegationAdmission::forTree(900, 5);
        self::assertSame('0 of 5 sub-agent slots of this conversation are taken; it starts at the next admission', $admissionWithAFreeSlot->buildQueuedReasonText());
        self::assertSame(['rootConversationId', 'lockOutcome', 'lockAttempts', 'concurrencyCap', 'occupiedSlotCount', 'queuedChildIds', 'admittedChildIds', 'retried'], array_keys($admissionWithAFreeSlot->buildTurnEventPayload()));
    }
}
