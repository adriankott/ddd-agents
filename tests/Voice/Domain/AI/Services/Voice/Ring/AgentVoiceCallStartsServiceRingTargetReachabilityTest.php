<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * The reachability question of a ring target with the agent space and its three answers
 * ({@see AgentVoiceCallStartsService::findRingTargetReachability()}): without an override every target answers yes or
 * no as before; a target that decides when it is rung counts as a target to ring, is rung in the configured order
 * after the targets before it, and its ring answers; a ring whose every target deferred and none rang ends as a ring
 * that reached nobody. An application that answers the older list itself keeps its answer.
 */
class AgentVoiceCallStartsServiceRingTargetReachabilityTest extends AgentVoiceCallStartsStagingTestCase
{
    public const int AGENT_SPACE_ID = 77;

    public function testWithoutAnOverrideEveryTargetAnswersYesOrNoAsBefore(): void
    {
        $agentVoiceCallStartsService = new DeferringRingTargetAgentVoiceCallStartsServiceDouble();
        $agentVoiceCallStartsService->ringTargetNames = [AgentVoiceCallStartsService::RING_TARGET_OPEN_CLIENTS, DeferringRingTargetAgentVoiceCallStartsServiceDouble::PAGER_RING_TARGET];
        $ownerAccount = static::buildAccount(static::OWNER_ACCOUNT_ID);

        static::assertSame(
            AgentVoiceCallStartsService::RING_TARGET_REACHABLE_NOW,
            $agentVoiceCallStartsService->callFindRingTargetReachability(AgentVoiceCallStartsService::RING_TARGET_OPEN_CLIENTS, $ownerAccount, null, null)
        );
        static::assertSame(
            AgentVoiceCallStartsService::RING_TARGET_NOT_REACHABLE_NOW,
            $agentVoiceCallStartsService->callFindRingTargetReachability(DeferringRingTargetAgentVoiceCallStartsServiceDouble::PAGER_RING_TARGET, $ownerAccount, null, null)
        );
        static::assertSame([AgentVoiceCallStartsService::RING_TARGET_OPEN_CLIENTS], $agentVoiceCallStartsService->callFindRingTargetNamesToRing($ownerAccount, null, null));

        $this->realtimeChannel->listeningByMailboxName['account-' . static::OWNER_ACCOUNT_ID] = false;
        static::assertSame(
            AgentVoiceCallStartsService::RING_TARGET_NOT_REACHABLE_NOW,
            $agentVoiceCallStartsService->callFindRingTargetReachability(AgentVoiceCallStartsService::RING_TARGET_OPEN_CLIENTS, $ownerAccount, null, null)
        );
        static::assertSame([], $agentVoiceCallStartsService->callFindRingTargetNamesToRing($ownerAccount, null, null));
    }

    public function testTheAgentSpaceOfTheRingReachesTheQuestionOfEveryTarget(): void
    {
        $agentVoiceCallStartsService = new DeferringRingTargetAgentVoiceCallStartsServiceDouble();

        $agentVoiceCallStartsService->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), static::buildAgentSpace(), static::buildConversation(300));

        static::assertSame(['asked openClients for space 77', 'asked phones for space 77'], array_slice($agentVoiceCallStartsService->steps, 0, 2));
    }

    public function testTheAgentSpaceOfTheAgentsCallStartReachesTheQuestionOfTheRouting(): void
    {
        $agentVoiceCallStartsService = new DeferringRingTargetAgentVoiceCallStartsServiceDouble();
        $agentVoiceCallStartsService->ringTargetNames = [DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET];
        $agentVoiceCallStartsService->reachabilityByRingTargetName[DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET] = AgentVoiceCallStartsService::RING_TARGET_NOT_REACHABLE_NOW;

        $agentVoiceCallStart = $agentVoiceCallStartsService->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), static::buildAgentSpace(), static::buildConversation(300), false, 'task');
        $agentVoiceCallReachableChannels = $agentVoiceCallStartsService->findReachableChannelsForOwner(static::buildAccount(static::OWNER_ACCOUNT_ID), static::buildAgentSpace(), null);

        static::assertSame(AgentVoiceCallStart::REFUSED_APP_NOT_RINGABLE, $agentVoiceCallStart->refusalReason);
        static::assertFalse($agentVoiceCallReachableChannels->appIsRingableNow);
        static::assertSame(['asked phones for space 77', 'asked phones for space 77'], $agentVoiceCallStartsService->steps);
    }

    public function testADeferredTargetIsRungAfterTheOtherTargetInTheConfiguredOrderAndItsRingAnswers(): void
    {
        $agentVoiceCallStartsService = new DeferringRingTargetAgentVoiceCallStartsServiceDouble();
        $agentVoiceCallStartsService->reachabilityByRingTargetName[DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET] = AgentVoiceCallStartsService::RING_TARGET_DECIDED_WHEN_RUNG;
        $agentVoiceCallStartsService->deliveryByRingTargetName[DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET] = true;

        $agentVoiceCall = $agentVoiceCallStartsService->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), static::buildAgentSpace(), static::buildConversation(300));

        static::assertSame(
            ['asked openClients for space 77', 'asked phones for space 77', 'rang openClients', 'rang phones'],
            $agentVoiceCallStartsService->steps
        );
        static::assertNull($agentVoiceCall->endedAt);
        static::assertSame([501], array_keys($this->findRingPayloadsInMailbox()), 'the open clients were rung');
        static::assertSame('2 of 2 devices rung', $this->findRingStartedEvent()->summary);
    }

    public function testADeferredTargetKeepsTheRingFromBeingRefusedWhenNoOtherTargetIsReachable(): void
    {
        $this->realtimeChannel->listeningByMailboxName['account-' . static::OWNER_ACCOUNT_ID] = false;
        $agentVoiceCallStartsService = new DeferringRingTargetAgentVoiceCallStartsServiceDouble();
        $agentVoiceCallStartsService->reachabilityByRingTargetName[DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET] = AgentVoiceCallStartsService::RING_TARGET_DECIDED_WHEN_RUNG;
        $agentVoiceCallStartsService->deliveryByRingTargetName[DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET] = true;

        $agentVoiceCallStart = $agentVoiceCallStartsService->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), static::buildAgentSpace(), static::buildConversation(300), false, 'task');

        static::assertFalse($agentVoiceCallStart->isRefused());
        static::assertSame(501, $agentVoiceCallStart->agentVoiceCall?->id);
        static::assertNull($agentVoiceCallStart->agentVoiceCall?->endedAt);
        static::assertSame(['rang phones'], array_values(array_filter($agentVoiceCallStartsService->steps, [static::class, 'isRingStep'])), 'the open clients that listen nowhere are not rung');
        static::assertSame([], $this->findRingPayloadsInMailbox());
    }

    public function testEveryTargetDeferredAndNoneRangEndsTheRingAsOneThatReachedNobody(): void
    {
        $agentVoiceCallStartsService = $this->buildAllDeferredService();

        $agentVoiceCall = $agentVoiceCallStartsService->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), static::buildAgentSpace(), static::buildConversation(300));

        static::assertSame(AgentVoiceCall::END_REASON_FAILED, $agentVoiceCall->endReason);
        static::assertNotNull($agentVoiceCall->endedAt);
        static::assertSame(['findUnansweredCallsOfAccountPastRingDeadline', 'createCall', 'markCallEnded'], $this->callStore->getRecordedMethodNames());
        static::assertSame([], $this->messageBus->dispatchedMessages, 'no deadline look');
        static::assertSame(['rang phones', 'rang pager'], array_values(array_filter($agentVoiceCallStartsService->steps, [static::class, 'isRingStep'])));
        static::assertSame('0 of 2 devices rung', $this->findRingStartedEvent()->summary);
    }

    public function testEveryTargetDeferredAndNoneRangIsTheAgentsRefusalOfARingDeliveredNowhere(): void
    {
        $agentVoiceCallStartsService = $this->buildAllDeferredService();

        $agentVoiceCallStart = $agentVoiceCallStartsService->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), static::buildAgentSpace(), static::buildConversation(300), false, 'task');

        static::assertSame(AgentVoiceCallStart::REFUSED_APP_RING_DELIVERED_NOWHERE, $agentVoiceCallStart->refusalReason);
        static::assertTrue($agentVoiceCallStart->appIsIncluded);
        static::assertSame('FAILED', $this->callStore->storedCallsById[501]->endReason);
    }

    public function testAnApplicationThatAnswersTheOlderListItselfKeepsItsAnswer(): void
    {
        $agentVoiceCallStartsService = new OlderRingTargetListAgentVoiceCallStartsServiceDouble();
        $ownerAccount = static::buildAccount(static::OWNER_ACCOUNT_ID);

        static::assertSame([], $agentVoiceCallStartsService->callFindRingTargetNamesToRing($ownerAccount, static::buildAgentSpace(), null));
        static::assertFalse($agentVoiceCallStartsService->callIsAppRingableInAgentSpace($ownerAccount, static::buildAgentSpace(), null), 'the listening open clients are not asked');
        $agentVoiceCallStartsService->reachableRingTargetNames = ['pager'];
        static::assertSame(['pager'], $agentVoiceCallStartsService->callFindRingTargetNamesToRing($ownerAccount, static::buildAgentSpace(), null));
        static::assertSame(3, $agentVoiceCallStartsService->listReads);
        static::assertSame([], $this->realtimeChannel->listenerReadAudiences);
        try {
            $agentVoiceCallStartsService->reachableRingTargetNames = [];
            $agentVoiceCallStartsService->startRing($ownerAccount, null, static::buildConversation(300));
            static::fail('the older list names no target');
        } catch (BadRequestException $badRequestException) {
            static::assertSame('no_registered_devices', $badRequestException->getMessage());
        }
    }

    public static function isRingStep(string $step): bool
    {
        return str_starts_with($step, 'rang ');
    }

    protected function buildAllDeferredService(): DeferringRingTargetAgentVoiceCallStartsServiceDouble
    {
        $agentVoiceCallStartsService = new DeferringRingTargetAgentVoiceCallStartsServiceDouble();
        $agentVoiceCallStartsService->ringTargetNames = [DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET, DeferringRingTargetAgentVoiceCallStartsServiceDouble::PAGER_RING_TARGET];
        foreach ($agentVoiceCallStartsService->ringTargetNames as $ringTargetName) {
            $agentVoiceCallStartsService->reachabilityByRingTargetName[$ringTargetName] = AgentVoiceCallStartsService::RING_TARGET_DECIDED_WHEN_RUNG;
            $agentVoiceCallStartsService->deliveryByRingTargetName[$ringTargetName] = false;
        }
        return $agentVoiceCallStartsService;
    }

    protected function findRingStartedEvent(): \DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent
    {
        $writtenEvents = $this->traceEventsService->recordedWrites[0];
        return $writtenEvents[count($writtenEvents) - 1];
    }

    protected static function buildAgentSpace(): AgentSpace
    {
        $agentSpace = AgentSpace::newInstance();
        $agentSpace->id = static::AGENT_SPACE_ID;
        return $agentSpace;
    }
}
