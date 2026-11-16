<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Delegation\Admission;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAdmission;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A child its spawn's admission left queued although it could have run — a slot was free and the child was not among
 * the rows the admission read, or the lock was not obtained — is admitted once more before the `delegate` call is
 * answered; a child queued behind a full tree is not. The admission is recorded as the turn event either way.
 */
class SpawnAdmissionIsRetriedOnceWhileASlotIsFreeTest extends UnitTestCase
{
    public function testAChildNotSeenWhileASlotWasFreeIsAdmittedByTheRetry(): void
    {
        $spawnService = new SpawnServiceWithScriptedAdmissions();
        $admissionThatDidNotSeeTheChild = AgentDelegationAdmission::forTree(900, 5);
        $retryAdmission = AgentDelegationAdmission::forTree(900, 5);
        $retryAdmission->queuedChildIds = [201];
        $retryAdmission->admittedChildIds = [201];
        $spawnService->scriptedAdmissions = [$admissionThatDidNotSeeTheChild, $retryAdmission];

        $agentDelegationAdmission = $spawnService->admitSpawnedChildren(self::buildRootConversation(), [201]);

        self::assertSame(2, $spawnService->admissionCount);
        self::assertTrue($agentDelegationAdmission->hasAdmittedChild(201));
        self::assertTrue($agentDelegationAdmission->retried);
        self::assertSame($agentDelegationAdmission, $spawnService->recordedAdmission);
        self::assertSame([201], $spawnService->recordedSpawnedChildIds);
    }

    public function testAnAdmissionWithoutTheLockIsRetriedOnceAndThenNamesTheLock(): void
    {
        $spawnService = new SpawnServiceWithScriptedAdmissions();
        $refusedAdmission = AgentDelegationAdmission::forTree(900, 5);
        $refusedAdmission->lockOutcome = AgentDelegationAdmission::LOCK_REFUSED;
        $spawnService->scriptedAdmissions = [$refusedAdmission, clone $refusedAdmission];

        $agentDelegationAdmission = $spawnService->admitSpawnedChildren(self::buildRootConversation(), [201]);

        self::assertSame(2, $spawnService->admissionCount, 'retried once, not more');
        self::assertFalse($agentDelegationAdmission->hasAdmittedChild(201));
        self::assertStringStartsWith('the admission could not run now, its lock was not obtained', $agentDelegationAdmission->buildQueuedReasonText());
    }

    public function testAChildQueuedBehindAFullTreeIsNotRetried(): void
    {
        $spawnService = new SpawnServiceWithScriptedAdmissions();
        $fullAdmission = AgentDelegationAdmission::forTree(900, 5);
        $fullAdmission->occupiedSlotCount = 5;
        $spawnService->scriptedAdmissions = [$fullAdmission];

        $agentDelegationAdmission = $spawnService->admitSpawnedChildren(self::buildRootConversation(), [201]);

        self::assertSame(1, $spawnService->admissionCount);
        self::assertFalse($agentDelegationAdmission->retried);
        self::assertSame('no slot is free: 5 of 5 sub-agent slots of this conversation are taken; it starts when one of them ends', $agentDelegationAdmission->buildQueuedReasonText());
    }

    public function testAnAdmittedChildIsNotRetried(): void
    {
        $spawnService = new SpawnServiceWithScriptedAdmissions();
        $admittingAdmission = AgentDelegationAdmission::forTree(900, 5);
        $admittingAdmission->queuedChildIds = [201];
        $admittingAdmission->admittedChildIds = [201];
        $spawnService->scriptedAdmissions = [$admittingAdmission];

        $spawnService->admitSpawnedChildren(self::buildRootConversation(), [201]);

        self::assertSame(1, $spawnService->admissionCount);
    }

    protected static function buildRootConversation(): AIConversation
    {
        $rootConversation = new AIConversation();
        $rootConversation->id = 900;
        return $rootConversation;
    }
}
