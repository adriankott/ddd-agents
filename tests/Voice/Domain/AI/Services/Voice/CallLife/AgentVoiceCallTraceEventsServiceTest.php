<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvents;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvents;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationTraceAnomaly;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSummary;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallTraceEventsService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;

/**
 * The trace events of voice calls ({@see AgentVoiceCallTraceEventsService}): one event key per call step, and the call
 * headers of a conversation's calls, oldest first, each over its own events, built by the application's subclass of
 * the header when it has one. The integration base binds container services only, so the subclass is bound for the
 * package class through the value-object class registry, which is restored after each test.
 */
class AgentVoiceCallTraceEventsServiceTest extends AgentVoiceIntegrationTestCase
{
    protected InMemoryAgentVoiceCallStore $callStore;

    protected ScriptedAIConversationTurnEventsService $turnEventsService;

    protected AgentVoiceCallTraceEventsService $agentVoiceCallTraceEventsService;

    /** @var array<string, string> */
    protected array $applicationClassNamesOfValueObjectsBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applicationClassNamesOfValueObjectsBeforeTheTest = StaticRegistry::$applicationClassNamesOfValueObjects;
        $this->callStore = new InMemoryAgentVoiceCallStore();
        $this->turnEventsService = new ScriptedAIConversationTurnEventsService();
        $this->replaceService(AgentVoiceCallStoreService::class, new InMemoryStoreAgentVoiceCallStoreService($this->callStore));
        $this->replaceService(AIConversationTurnEventsService::class, $this->turnEventsService);
        $this->agentVoiceCallTraceEventsService = new AgentVoiceCallTraceEventsService();
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$applicationClassNamesOfValueObjects = $this->applicationClassNamesOfValueObjectsBeforeTheTest;
        parent::onTearDown();
    }

    public function testTheApplicationsHeaderClassBuildsTheHeadersAndReadsItsSpellingOfTheFirstWord(): void
    {
        StaticRegistry::$applicationClassNamesOfValueObjects[AgentVoiceCallTraceSummary::class] = EarlierSpellingAgentVoiceCallTraceSummary::class;
        $this->callStore->callsOfConversation = [$this->buildCall(9, 30), $this->buildCall(8, 30)];

        $callTraceSummaries = $this->agentVoiceCallTraceEventsService->buildVoiceCallTraceSummaries($this->buildConversation(30), $this->buildFirstWordEvents());

        static::assertSame(
            [EarlierSpellingAgentVoiceCallTraceSummary::class, EarlierSpellingAgentVoiceCallTraceSummary::class],
            array_map(static fn (AgentVoiceCallTraceSummary $summary): string => $summary::class, $callTraceSummaries->getElements())
        );
        static::assertSame([600, null], array_map(static fn (AgentVoiceCallTraceSummary $summary): ?int => $summary->firstAgentWordAfterOpenMs, $callTraceSummaries->getElements()));
    }

    public function testWithoutAnApplicationClassThePackageHeaderReadsAgentFirstTranscript(): void
    {
        unset(StaticRegistry::$applicationClassNamesOfValueObjects[AgentVoiceCallTraceSummary::class]);
        $this->callStore->callsOfConversation = [$this->buildCall(8, 30)];

        $callTraceSummary = $this->agentVoiceCallTraceEventsService->buildVoiceCallTraceSummaries($this->buildConversation(30), $this->buildFirstWordEvents())->first();

        static::assertSame(AgentVoiceCallTraceSummary::class, $callTraceSummary::class);
        static::assertSame('agent.first_transcript', AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT);
        static::assertSame(300, $callTraceSummary->firstAgentWordAfterOpenMs);
    }

    public function testTheEventOfACallStepIsKeyedByCallTypeAndDiscriminator(): void
    {
        $agentVoiceCall = $this->buildCall(7, 30);

        $traceEvent = $this->agentVoiceCallTraceEventsService->buildTraceEventForCall(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            'call.ended',
            discriminator: '3',
            summary: 'ended',
            payload: ['endReason' => 'COMPLETED'],
            durationMs: 40,
            occurredAtUnixMs: 1_800_000_000_000,
            receivedAtUnixMs: 1_800_000_000_050
        );

        static::assertSame('call:7:call.ended:3', $traceEvent->eventKey);
        static::assertSame(AIConversationTraceEvent::SOURCE_VOICE_WORKER, $traceEvent->source);
        static::assertSame('call.ended', $traceEvent->eventType);
        static::assertSame(7, $traceEvent->agentVoiceCallId);
        static::assertSame(30, $traceEvent->aiConversationId);
        static::assertSame('ended', $traceEvent->summary);
        static::assertSame(['endReason' => 'COMPLETED'], $traceEvent->getPayloadArray());
        static::assertSame(40, $traceEvent->durationMs);
        static::assertSame(1_800_000_000_000, $traceEvent->occurredAtUnixMs);
        static::assertSame(1_800_000_000_050, $traceEvent->receivedAtUnixMs);
    }

    public function testTheSameStepIsTheSameKeyAndACallWithoutIdSaysNone(): void
    {
        $first = $this->agentVoiceCallTraceEventsService->buildTraceEventForCall($this->buildCall(7, 30), AIConversationTraceEvent::SOURCE_VOICE_WORKER, 'call.ended');
        $retried = $this->agentVoiceCallTraceEventsService->buildTraceEventForCall($this->buildCall(7, 30), AIConversationTraceEvent::SOURCE_VOICE_WORKER, 'call.ended');
        $callWithoutId = $this->buildCall(7, null);
        $callWithoutId->id = null;
        $withoutId = $this->agentVoiceCallTraceEventsService->buildTraceEventForCall($callWithoutId, AIConversationTraceEvent::SOURCE_VOICE_WORKER, 'call.ended');

        static::assertSame('call:7:call.ended:0', $first->eventKey);
        static::assertSame($first->eventKey, $retried->eventKey);
        static::assertSame('call:none:call.ended:0', $withoutId->eventKey);
        static::assertNull($withoutId->agentVoiceCallId);
        static::assertNull($withoutId->aiConversationId);
        static::assertNull($withoutId->payload);
    }

    public function testAConversationWithoutCallsHasNoHeadersAndReadsNoTurnEvents(): void
    {
        $callTraceSummaries = $this->agentVoiceCallTraceEventsService->buildVoiceCallTraceSummaries($this->buildConversation(30), new AIConversationTraceEvents());

        static::assertSame(0, $callTraceSummaries->count());
        static::assertSame([[30]], $this->callStore->getArgumentsOf('findCallsOfConversation'));
        static::assertSame([], $this->turnEventsService->askedConversationIds);
    }

    public function testTheHeadersReadOldestFirstEachOverItsOwnEvents(): void
    {
        $this->callStore->callsOfConversation = [$this->buildCall(9, 30), $this->buildCall(8, 30)];
        $traceEvents = $this->buildTraceEventSet(
            $this->buildTraceEvent(8, AgentVoiceCallTraceSummary::EVENT_TYPE_OPEN_ANSWERED, 1_000),
            $this->buildTraceEvent(8, AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED, 1_400),
            $this->buildTraceEvent(9, AgentVoiceCallTraceSummary::EVENT_TYPE_OPEN_ANSWERED, 5_000),
            $this->buildTraceEvent(null, AgentVoiceCallTraceSummary::EVENT_TYPE_WORKER_ATTACHED, 5_100)
        );

        $callTraceSummaries = $this->agentVoiceCallTraceEventsService->buildVoiceCallTraceSummaries($this->buildConversation(30), $traceEvents);

        static::assertSame([8, 9], array_map(static fn (AgentVoiceCallTraceSummary $summary): ?int => $summary->agentVoiceCallId, $callTraceSummaries->getElements()));
        static::assertSame([2, 1], array_map(static fn (AgentVoiceCallTraceSummary $summary): int => $summary->traceEventCount, $callTraceSummaries->getElements()));
        static::assertSame([400, null], array_map(static fn (AgentVoiceCallTraceSummary $summary): ?int => $summary->workerAttachAfterOpenMs, $callTraceSummaries->getElements()));
        static::assertSame([30], $this->turnEventsService->askedConversationIds);
    }

    public function testWithoutTraceEventsEveryHeaderHasNoNumbers(): void
    {
        $this->callStore->callsOfConversation = [$this->buildCall(8, 30)];

        $callTraceSummary = $this->agentVoiceCallTraceEventsService->buildVoiceCallTraceSummaries($this->buildConversation(30), null)->first();

        static::assertSame(0, $callTraceSummary->traceEventCount);
        static::assertNull($callTraceSummary->openRequestMs);
        static::assertNull($callTraceSummary->medianTurnGapMs);
    }

    public function testTheTurnEndEventsFlagASlowPickupAndTheirFailureIsTolerated(): void
    {
        $this->callStore->callsOfConversation = [$this->buildCall(8, 30)];
        $traceEvents = new AIConversationTraceEvents();
        $taskStarted = $this->buildTraceEvent(8, AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED, 2_000);
        $taskStarted->messageIndex = 4;
        $traceEvents->add($taskStarted);
        $turnEndEvent = AIConversationTurnEvent::newInstance();
        $turnEndEvent->kind = AIConversationTurnEvent::KIND_TURN_END;
        $turnEndEvent->initiatorMessageIndex = 4;
        $turnEndEvent->setPayloadArray(['turnStartMs' => ['sendToJob' => 1500]]);
        $turnEvents = new AIConversationTurnEvents();
        $turnEvents->add($turnEndEvent);
        $this->turnEventsService->turnEvents = $turnEvents;

        $withTurnEvents = $this->agentVoiceCallTraceEventsService->buildVoiceCallTraceSummaries($this->buildConversation(30), $traceEvents)->first();
        $this->turnEventsService->failureMessage = 'turn events gone';
        $withoutTurnEvents = $this->agentVoiceCallTraceEventsService->buildVoiceCallTraceSummaries($this->buildConversation(30), $traceEvents)->first();

        static::assertSame([AIConversationTraceAnomaly::CODE_SLOW_PICKUP], $this->collectAnomalyCodes($withTurnEvents));
        static::assertSame([], $this->collectAnomalyCodes($withoutTurnEvents));
    }

    /** Call 8 opened at 1000 and has its first word in both spellings: the package's at 1300, the earlier one at 1600. */
    protected function buildFirstWordEvents(): AIConversationTraceEvents
    {
        return $this->buildTraceEventSet(
            $this->buildTraceEvent(8, AgentVoiceCallTraceSummary::EVENT_TYPE_OPEN_ANSWERED, 1_000),
            $this->buildTraceEvent(8, 'agent.first_transcript', 1_300),
            $this->buildTraceEvent(8, 'earlier.first_transcript', 1_600)
        );
    }

    protected function buildTraceEventSet(AIConversationTraceEvent ...$traceEventsInOrder): AIConversationTraceEvents
    {
        $traceEvents = new AIConversationTraceEvents();
        foreach ($traceEventsInOrder as $traceEvent) {
            $traceEvents->add($traceEvent);
        }

        return $traceEvents;
    }

    /** @return string[] */
    protected function collectAnomalyCodes(AgentVoiceCallTraceSummary $callTraceSummary): array
    {
        return array_map(static fn (AIConversationTraceAnomaly $anomaly): ?string => $anomaly->code, $callTraceSummary->anomalies->getElements());
    }

    protected function buildTraceEvent(?int $callId, string $eventType, int $occurredAtUnixMs): AIConversationTraceEvent
    {
        return AIConversationTraceEvent::create(
            source: AIConversationTraceEvent::SOURCE_VOICE_WORKER,
            eventType: $eventType,
            eventKey: 'call:' . ($callId ?? 'none') . ':' . $eventType . ':' . $occurredAtUnixMs,
            aiConversationId: 30,
            agentVoiceCallId: $callId,
            occurredAtUnixMs: $occurredAtUnixMs
        );
    }

    protected function buildCall(int $callId, ?int $conversationId): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCall::newInstance();
        $agentVoiceCall->id = $callId;
        $agentVoiceCall->aiConversationId = $conversationId;

        return $agentVoiceCall;
    }

    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = $conversationId;

        return $conversation;
    }
}
