<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;

/**
 * The device counts of a ring target in the trace of a started ring
 * ({@see AgentVoiceCallStartsService::findSelectedDeviceCountOfRingTarget()},
 * {@see AgentVoiceCallStartsService::findRungDeviceCountOfRingTarget()}): unknown by default, and an unknown count writes
 * nothing, so the events are the ones of before; a known count lands in the event of its own target only; the started
 * ring keeps counting ring targets.
 */
class AgentVoiceCallStartsServiceRingDeviceCountsTest extends AgentVoiceCallStartsStagingTestCase
{
    public function testWithoutAnOverrideTheEventsOfTheRingAreTheOnesOfBefore(): void
    {
        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        $writtenEvents = $this->traceEventsService->recordedWrites[0];
        static::assertCount(2, $writtenEvents);
        static::assertSame('ring.browser_tab_ring_written', $writtenEvents[0]->eventType);
        static::assertSame('{"delivered":true,"targetAccountId":41}', $writtenEvents[0]->payload, 'byte for byte the payload of before');
        static::assertSame('call:' . $agentVoiceCall->id . ':ring.browser_tab_ring_written:browserTab', $writtenEvents[0]->eventKey);
        static::assertSame('ring message sent to the open clients of account 41', $writtenEvents[0]->summary);
        static::assertFalse($writtenEvents[0]->truncated);
        static::assertSame(AgentVoiceCallStartsService::TRACE_EVENT_RING_STARTED, $writtenEvents[1]->eventType);
        static::assertSame('{"devicesRung":1,"devicesSelected":1}', $writtenEvents[1]->payload);
        static::assertSame('1 of 1 devices rung', $writtenEvents[1]->summary);
    }

    public function testAKnownCountLandsInTheEventOfItsTargetAndNowhereElse(): void
    {
        $agentVoiceCallStartsService = new DeferringRingTargetAgentVoiceCallStartsServiceDouble();
        $agentVoiceCallStartsService->ringTargetNames = [DeferringRingTargetAgentVoiceCallStartsServiceDouble::PAGER_RING_TARGET, AgentVoiceCallStartsService::RING_TARGET_OPEN_CLIENTS, DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET];
        $agentVoiceCallStartsService->reachabilityByRingTargetName = [
            DeferringRingTargetAgentVoiceCallStartsServiceDouble::PAGER_RING_TARGET => AgentVoiceCallStartsService::RING_TARGET_REACHABLE_NOW,
            DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET => AgentVoiceCallStartsService::RING_TARGET_REACHABLE_NOW,
        ];
        $agentVoiceCallStartsService->deliveryByRingTargetName = [
            DeferringRingTargetAgentVoiceCallStartsServiceDouble::PAGER_RING_TARGET => false,
            DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET => true,
        ];
        $agentVoiceCallStartsService->selectedDeviceCountsByRingTargetName[DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET] = 3;
        $agentVoiceCallStartsService->rungDeviceCountsByRingTargetName[DeferringRingTargetAgentVoiceCallStartsServiceDouble::PHONES_RING_TARGET] = 2;

        $agentVoiceCallStartsService->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        $writtenEvents = $this->traceEventsService->recordedWrites[0];
        static::assertCount(4, $writtenEvents);
        static::assertSame(['delivered' => false, 'targetAccountId' => 41], $writtenEvents[0]->getPayloadArray(), 'the pager: no count');
        static::assertSame(['delivered' => true, 'targetAccountId' => 41], $writtenEvents[1]->getPayloadArray(), 'the open clients: no count');
        static::assertSame(['delivered' => true, 'targetAccountId' => 41, 'devicesSelected' => 3, 'devicesRung' => 2], $writtenEvents[2]->getPayloadArray(), 'the phones');
        static::assertSame(['devicesRung' => 2, 'devicesSelected' => 3], $writtenEvents[3]->getPayloadArray(), 'the started ring still counts targets');
        static::assertSame('2 of 3 devices rung', $writtenEvents[3]->summary);
    }

    public function testOneKnownCountIsWrittenWithoutTheOther(): void
    {
        $agentVoiceCallStartsService = new DeferringRingTargetAgentVoiceCallStartsServiceDouble();
        $agentVoiceCallStartsService->ringTargetNames = [AgentVoiceCallStartsService::RING_TARGET_OPEN_CLIENTS];
        $agentVoiceCallStartsService->rungDeviceCountsByRingTargetName[AgentVoiceCallStartsService::RING_TARGET_OPEN_CLIENTS] = 0;

        $agentVoiceCallStartsService->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        /** @var AIConversationTraceEvent $ringTargetEvent */
        $ringTargetEvent = $this->traceEventsService->recordedWrites[0][0];
        static::assertSame(['delivered' => true, 'targetAccountId' => 41, 'devicesRung' => 0], $ringTargetEvent->getPayloadArray());
    }
}
