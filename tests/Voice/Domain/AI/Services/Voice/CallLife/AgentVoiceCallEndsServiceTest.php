<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallEnd;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallEndsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;

/**
 * The agent ends a call ({@see AgentVoiceCallEndsService::endVoiceCall()}): which call, whether it ends now or after
 * the goodbye, the refusals, and the event on the call's trace. The calls service is scripted; the times of a call are
 * set a minute around the clock of the run, because the service reads the clock itself.
 */
class AgentVoiceCallEndsServiceTest extends AgentVoiceIntegrationTestCase
{
    protected const int OWNER_ACCOUNT_ID = 5;

    protected ScriptedAgentVoiceCallsService $agentVoiceCallsService;

    protected RecordingAIConversationTraceEventsService $traceEventsService;

    protected AgentVoiceCallEndsService $agentVoiceCallEndsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentVoiceCallsService = new ScriptedAgentVoiceCallsService();
        $this->traceEventsService = new RecordingAIConversationTraceEventsService();
        $this->replaceService(AgentVoiceCallsService::class, $this->agentVoiceCallsService);
        $this->replaceService(AIConversationTraceEventsService::class, $this->traceEventsService);
        $this->agentVoiceCallEndsService = new AgentVoiceCallEndsService();
        $this->declareAgentPersonaConfiguration(['agentName' => '']);
    }

    public function testAForeignCallIdIsRefusedAsNotFoundAndNothingEnds(): void
    {
        $this->agentVoiceCallsService->openCallsOfTheAccount = [$this->buildRunningCall(8, 30)];

        $agentVoiceCallEnd = $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, 99);

        static::assertTrue($agentVoiceCallEnd->isRefused());
        static::assertSame(AgentVoiceCallEnd::REFUSED_CALL_NOT_FOUND, $agentVoiceCallEnd->refusalReason);
        static::assertNull($agentVoiceCallEnd->agentVoiceCall);
        static::assertSame([8], $agentVoiceCallEnd->otherOpenCallIds);
        static::assertSame(
            [['method' => 'findOpenCallsOfAccountId', 'arguments' => [5]], ['method' => 'findCallOfAccountIdById', 'arguments' => [99, 5]]],
            $this->agentVoiceCallsService->recordedReads
        );
        static::assertSame([], $this->agentVoiceCallsService->recordedActions);
        static::assertSame([], $this->traceEventsService->recordedEvents);
    }

    public function testACallThatIsOverIsRefusedAsAlreadyEnded(): void
    {
        $endedCall = $this->buildRunningCall(9, 30);
        $endedCall->endedAt = DateTime::fromTimestamp(time() - 5);
        $this->agentVoiceCallsService->callsOfTheAccountById[9] = $endedCall;

        $agentVoiceCallEnd = $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, 9);

        static::assertSame(AgentVoiceCallEnd::REFUSED_CALL_ALREADY_ENDED, $agentVoiceCallEnd->refusalReason);
        static::assertSame($endedCall, $agentVoiceCallEnd->agentVoiceCall);
        static::assertSame([], $this->agentVoiceCallsService->recordedActions);
    }

    public function testWithoutACallInThisConversationTheRefusalNamesTheOtherOpenCalls(): void
    {
        $this->agentVoiceCallsService->openCallsOfTheAccount = [$this->buildRunningCall(8, 31), $this->buildRingingCall(6, 32)];

        $agentVoiceCallEnd = $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, null);

        static::assertSame(AgentVoiceCallEnd::REFUSED_NO_CALL_IN_THIS_CONVERSATION, $agentVoiceCallEnd->refusalReason);
        static::assertSame([8, 6], $agentVoiceCallEnd->otherOpenCallIds);
        static::assertSame([], $this->agentVoiceCallsService->recordedActions);
    }

    public function testWithoutAConversationAndACallIdNothingIsEnded(): void
    {
        $this->agentVoiceCallsService->openCallsOfTheAccount = [$this->buildRunningCall(8, 30)];

        static::assertSame(
            AgentVoiceCallEnd::REFUSED_NO_CALL_IN_THIS_CONVERSATION,
            $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, null, null)->refusalReason
        );
    }

    public function testTheCallTheAgentIsOnEndsAfterTheGoodbye(): void
    {
        $runningCall = $this->buildRunningCall(8, 30);
        $this->agentVoiceCallsService->openCallsOfTheAccount = [$runningCall];

        $agentVoiceCallEnd = $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, null);

        static::assertSame(AgentVoiceCallEnd::OUTCOME_ENDS_AFTER_GOODBYE, $agentVoiceCallEnd->outcome);
        static::assertTrue($agentVoiceCallEnd->endsAfterGoodbye());
        static::assertSame($runningCall, $agentVoiceCallEnd->agentVoiceCall);
        static::assertSame(AgentVoiceCall::CALL_STATE_IN_PROGRESS, $agentVoiceCallEnd->callStateBeforeTheEnd);
        static::assertSame(['requestCallEndAfterGoodbye:8:ENDED_BY_AGENT'], $this->agentVoiceCallsService->recordedActions);
        $this->assertOneTraceEvent(
            $runningCall,
            AgentVoiceCallEndsService::TRACE_EVENT_TYPE_END_AFTER_GOODBYE_REQUESTED,
            'The agent ended the call it is on: hang-up after the goodbye'
        );
    }

    public function testTheCallTheAgentIsOnNamedByIdAlsoEndsAfterTheGoodbye(): void
    {
        $runningCall = $this->buildRunningCall(8, 30);
        $this->agentVoiceCallsService->callsOfTheAccountById[8] = $runningCall;

        static::assertSame(
            AgentVoiceCallEnd::OUTCOME_ENDS_AFTER_GOODBYE,
            $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, 8)->outcome
        );
    }

    public function testAFailedGoodbyeRequestEndsTheCallNow(): void
    {
        $runningCall = $this->buildRunningCall(8, 30);
        $this->agentVoiceCallsService->openCallsOfTheAccount = [$runningCall];
        $this->agentVoiceCallsService->goodbyeRequestFails = true;

        $agentVoiceCallEnd = $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, null);

        static::assertSame(AgentVoiceCallEnd::OUTCOME_ENDED_NOW, $agentVoiceCallEnd->outcome);
        static::assertFalse($agentVoiceCallEnd->endsAfterGoodbye());
        static::assertSame(['requestCallEndAfterGoodbye:8:ENDED_BY_AGENT', 'endCallNow:8:ENDED_BY_AGENT'], $this->agentVoiceCallsService->recordedActions);
        $this->assertOneTraceEvent($runningCall, AgentVoiceCallEndsService::TRACE_EVENT_TYPE_ENDED_BY_AGENT, 'The agent hung the call up');
    }

    public function testARunningCallOfAnotherConversationEndsNow(): void
    {
        $runningCall = $this->buildRunningCall(8, 31);
        $this->agentVoiceCallsService->callsOfTheAccountById[8] = $runningCall;

        $agentVoiceCallEnd = $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, 8);

        static::assertSame(AgentVoiceCallEnd::OUTCOME_ENDED_NOW, $agentVoiceCallEnd->outcome);
        static::assertSame(['endCallNow:8:ENDED_BY_AGENT'], $this->agentVoiceCallsService->recordedActions);
    }

    public function testARingingCallIsWithdrawnNow(): void
    {
        $ringingCall = $this->buildRingingCall(6, 30);
        $this->agentVoiceCallsService->openCallsOfTheAccount = [$ringingCall];
        $this->declareAgentPersonaConfiguration(['agentName' => 'Mira']);

        $agentVoiceCallEnd = $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, null);

        static::assertSame(AgentVoiceCallEnd::OUTCOME_ENDED_NOW, $agentVoiceCallEnd->outcome);
        static::assertSame(AgentVoiceCall::CALL_STATE_RINGING, $agentVoiceCallEnd->callStateBeforeTheEnd);
        static::assertSame(['endCallNow:6:ENDED_BY_AGENT'], $this->agentVoiceCallsService->recordedActions);
        $this->assertOneTraceEvent($ringingCall, AgentVoiceCallEndsService::TRACE_EVENT_TYPE_ENDED_BY_AGENT, 'Mira withdrew the ringing call');
    }

    public function testTheRunningCallOfThisConversationWinsOverItsRingingOne(): void
    {
        $this->agentVoiceCallsService->openCallsOfTheAccount = [$this->buildRingingCall(9, 30), $this->buildRunningCall(8, 30)];

        $agentVoiceCallEnd = $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, null);

        static::assertSame(8, $agentVoiceCallEnd->agentVoiceCall->id);
        static::assertSame(['requestCallEndAfterGoodbye:8:ENDED_BY_AGENT'], $this->agentVoiceCallsService->recordedActions);
    }

    public function testAFailingTraceNeverFailsTheEnd(): void
    {
        $this->traceEventsService->failureMessage = 'trace table gone';
        $this->agentVoiceCallsService->openCallsOfTheAccount = [$this->buildRingingCall(6, 30)];

        static::assertSame(AgentVoiceCallEnd::OUTCOME_ENDED_NOW, $this->agentVoiceCallEndsService->endVoiceCall(static::OWNER_ACCOUNT_ID, 30, null)->outcome);
    }

    protected function assertOneTraceEvent(AgentVoiceCall $agentVoiceCall, string $eventType, string $summary): void
    {
        static::assertCount(1, $this->traceEventsService->recordedEvents);
        $traceEvent = $this->traceEventsService->recordedEvents[0];
        static::assertSame($eventType, $traceEvent->eventType);
        static::assertSame(AIConversationTraceEvent::SOURCE_VOICE_WORKER, $traceEvent->source);
        static::assertSame('call:' . $agentVoiceCall->id . ':' . $eventType . ':0', $traceEvent->eventKey);
        static::assertSame((int)$agentVoiceCall->id, $traceEvent->agentVoiceCallId);
        static::assertSame($agentVoiceCall->aiConversationId, $traceEvent->aiConversationId);
        static::assertSame($summary, $traceEvent->summary);
        static::assertSame(['endReason' => AgentVoiceCall::END_REASON_ENDED_BY_AGENT, 'channelType' => AgentVoiceCall::CHANNEL_TYPE_APP], $traceEvent->getPayloadArray());
    }

    protected function buildRunningCall(int $callId, int $conversationId): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCall::newInstance();
        $agentVoiceCall->id = $callId;
        $agentVoiceCall->accountId = static::OWNER_ACCOUNT_ID;
        $agentVoiceCall->aiConversationId = $conversationId;
        $agentVoiceCall->startedAt = DateTime::fromTimestamp(time() - 60);

        return $agentVoiceCall;
    }

    protected function buildRingingCall(int $callId, int $conversationId): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCall::newInstance();
        $agentVoiceCall->id = $callId;
        $agentVoiceCall->accountId = static::OWNER_ACCOUNT_ID;
        $agentVoiceCall->aiConversationId = $conversationId;
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->ringDeadlineAt = DateTime::fromTimestamp(time() + 60);

        return $agentVoiceCall;
    }
}
