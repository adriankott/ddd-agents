<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\GoneException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionCallStateGetRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeclineRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeviceStatusRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionHangUpRequestDto;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The five routes that name a call: each acts on a call of the signed-in account only (another account's call reads
 * as `404 call_not_found`, and nothing else is asked); `answer` hands the token and the client to the ring service and
 * answers the winner; `decline` acknowledges, also a ring that is already over; `hangUp` ends the call through the one
 * end path (FAILED for a dead media path, COMPLETED otherwise); `deviceStatus` writes one event into the trace of the
 * call's conversation; `GET /{callId}` reads whether and why the call ended, its time in UTC.
 */
class AgentVoiceSessionCallRoutesTest extends AgentVoiceSessionsTestCase
{
    public const string ANSWER_TOKEN = '1800000090.signature';

    protected ?string $timeZoneBeforeTheTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeZoneBeforeTheTest = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function onTearDown(): void
    {
        date_default_timezone_set((string)$this->timeZoneBeforeTheTest);
        parent::onTearDown();
    }

    public function testACallOfAnotherAccountReadsAsNotFoundOnEveryRoute(): void
    {
        $foreignCall = static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 60);
        $foreignCall->accountId = static::ACCOUNT_ID + 1;
        $this->storeAnswersCallOfSignedInAccount($foreignCall);
        $this->agentVoiceRingService->expects(static::never())->method(static::anything());
        $this->agentVoiceCallsService->expects(static::never())->method(static::anything());

        $answerRequestDto = $this->buildAnswerRequestDto();
        $declineRequestDto = new AgentVoiceSessionDeclineRequestDto();
        $declineRequestDto->callId = static::CALL_ID;
        $declineRequestDto->answerToken = static::ANSWER_TOKEN;
        $hangUpRequestDto = new AgentVoiceSessionHangUpRequestDto();
        $hangUpRequestDto->callId = static::CALL_ID;
        $deviceStatusRequestDto = new AgentVoiceSessionDeviceStatusRequestDto();
        $deviceStatusRequestDto->callId = static::CALL_ID;
        $deviceStatusRequestDto->status = 'MEDIA_CONNECTED';
        $callStateRequestDto = new AgentVoiceSessionCallStateGetRequestDto();
        $callStateRequestDto->callId = static::CALL_ID;

        $refusals = [];
        foreach (['answer', 'decline', 'hangUp', 'deviceStatus', 'callState'] as $routeName) {
            try {
                match ($routeName) {
                    'answer' => $this->fixtureController->answerAgentVoiceSession($answerRequestDto),
                    'decline' => $this->fixtureController->declineAgentVoiceSession($declineRequestDto),
                    'hangUp' => $this->fixtureController->hangUpAgentVoiceSession($hangUpRequestDto),
                    'deviceStatus' => $this->fixtureController->reportAgentVoiceSessionDeviceStatus($deviceStatusRequestDto),
                    'callState' => $this->fixtureController->getAgentVoiceSessionCallState($callStateRequestDto),
                };
                $refusals[$routeName] = 'answered';
            } catch (Throwable $routeRefusal) {
                $refusals[$routeName] = $routeRefusal::class . ': ' . $routeRefusal->getMessage();
            }
        }

        static::assertSame(array_fill_keys(['answer', 'decline', 'hangUp', 'deviceStatus', 'callState'], NotFoundException::class . ': call_not_found'), $refusals);
        static::assertSame([], $this->recordingConversationTraceEventsService->recordedTraceEvents);
    }

    public function testTheWinnerOfAnAnswerIsToldItWonAndTheConversationOfTheCall(): void
    {
        $agentVoiceCall = static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 60, direction: AgentVoiceCall::DIRECTION_OUTBOUND);
        $this->storeAnswersCallOfSignedInAccount($agentVoiceCall);
        $this->agentVoiceRingService->expects(static::once())->method('answerRing')->with(static::identicalTo($agentVoiceCall), static::ANSWER_TOKEN, null);

        $answerRequestDto = $this->buildAnswerRequestDto();
        $responseDto = $this->fixtureController->answerAgentVoiceSession($answerRequestDto);

        static::assertTrue($responseDto->won);
        static::assertSame(static::CONVERSATION_ID, $responseDto->conversationId);
    }

    public function testTheAnswerHandsTheIdTheClientStatesToTheRingService(): void
    {
        $agentVoiceCall = static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 60, direction: AgentVoiceCall::DIRECTION_OUTBOUND);
        $this->storeAnswersCallOfSignedInAccount($agentVoiceCall);
        $this->agentVoiceRingService->expects(static::once())->method('answerRing')->with(static::identicalTo($agentVoiceCall), static::ANSWER_TOKEN, 'tab-1');
        $answerRequestDto = new ClientIdentifyingAnswerRequestDto();
        $answerRequestDto->callId = static::CALL_ID;
        $answerRequestDto->answerToken = static::ANSWER_TOKEN;
        $answerRequestDto->clientId = 'tab-1';

        static::assertTrue($this->fixtureController->callBuildVoiceCallAnsweredResponse($answerRequestDto)->won);
    }

    /** @return array<string, array{Throwable}> */
    public static function provideAnswerRefusals(): array
    {
        return [
            'another client won' => [new ConflictException('already_answered')],
            'the ring expired' => [new GoneException('ring_expired')],
            'the token is forged' => [new BadRequestException('invalid_answer_token')],
        ];
    }

    #[DataProvider('provideAnswerRefusals')]
    public function testARefusedAnswerIsAnsweredWithTheRingServicesCode(Throwable $ringServiceRefusal): void
    {
        $this->storeAnswersCallOfSignedInAccount(static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 60, direction: AgentVoiceCall::DIRECTION_OUTBOUND));
        $this->agentVoiceRingService->method('answerRing')->willThrowException($ringServiceRefusal);

        try {
            $answerRequestDto = $this->buildAnswerRequestDto();
            $this->fixtureController->answerAgentVoiceSession($answerRequestDto);
            static::fail('a refused answer must not answer won');
        } catch (Throwable $answerRefusal) {
            static::assertSame($ringServiceRefusal::class, $answerRefusal::class);
            static::assertSame($ringServiceRefusal->getMessage(), $answerRefusal->getMessage());
        }
    }

    public function testADeclineIsHandedToTheRingServiceAndAcknowledged(): void
    {
        $agentVoiceCall = static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 60, direction: AgentVoiceCall::DIRECTION_OUTBOUND);
        $this->storeAnswersCallOfSignedInAccount($agentVoiceCall);
        $this->agentVoiceRingService->expects(static::once())->method('declineRing')->with(static::identicalTo($agentVoiceCall), static::ANSWER_TOKEN);

        $declineRequestDto = $this->buildDeclineRequestDto();
        static::assertTrue($this->fixtureController->declineAgentVoiceSession($declineRequestDto)->acknowledged);
    }

    public function testADeclineOfARingThatIsAlreadyOverIsAcknowledged(): void
    {
        $this->storeAnswersCallOfSignedInAccount(static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 60, startedOffsetSeconds: -1, direction: AgentVoiceCall::DIRECTION_OUTBOUND));
        $this->agentVoiceRingService->expects(static::once())->method('declineRing')->willThrowException(new ConflictException('already_answered'));

        $declineRequestDto = $this->buildDeclineRequestDto();
        static::assertTrue($this->fixtureController->declineAgentVoiceSession($declineRequestDto)->acknowledged);
    }

    public function testADeclineWithAnExpiredTokenAnswersRingExpired(): void
    {
        $this->storeAnswersCallOfSignedInAccount(static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: -1, direction: AgentVoiceCall::DIRECTION_OUTBOUND));
        $this->agentVoiceRingService->method('declineRing')->willThrowException(new GoneException('ring_expired'));

        $this->expectException(GoneException::class);
        $this->expectExceptionMessage('ring_expired');
        $declineRequestDto = $this->buildDeclineRequestDto();
        $this->fixtureController->declineAgentVoiceSession($declineRequestDto);
    }

    /** @return array<string, array{string|null, string}> */
    public static function provideHangUpReasons(): array
    {
        return [
            'the media path failed' => ['MEDIA_FAILED', AgentVoiceCall::END_REASON_FAILED],
            'the owner ended it' => ['USER_ENDED', AgentVoiceCall::END_REASON_COMPLETED],
            'the app went to the background' => ['APP_BACKGROUNDED', AgentVoiceCall::END_REASON_COMPLETED],
            'no reason' => [null, AgentVoiceCall::END_REASON_COMPLETED],
        ];
    }

    #[DataProvider('provideHangUpReasons')]
    public function testAHangUpEndsTheCallThroughTheOneEndPath(?string $reason, string $expectedEndReason): void
    {
        $agentVoiceCall = static::buildCallOfSignedInAccount(startedOffsetSeconds: -120);
        $this->storeAnswersCallOfSignedInAccount($agentVoiceCall);
        $this->agentVoiceCallsService->expects(static::once())->method('endCallNow')->with(static::identicalTo($agentVoiceCall), $expectedEndReason)->willReturn(true);
        $hangUpRequestDto = new AgentVoiceSessionHangUpRequestDto();
        $hangUpRequestDto->callId = static::CALL_ID;
        $hangUpRequestDto->reason = $reason;

        static::assertTrue($this->fixtureController->hangUpAgentVoiceSession($hangUpRequestDto)->acknowledged);
    }

    public function testAHangUpOfACallThatAlreadyEndedIsAcknowledgedAndStillHandedToTheEndPath(): void
    {
        $agentVoiceCall = static::buildCallOfSignedInAccount(startedOffsetSeconds: -120, endedOffsetSeconds: -10);
        $this->storeAnswersCallOfSignedInAccount($agentVoiceCall);
        $this->agentVoiceCallsService->expects(static::once())->method('endCallNow')->willReturn(false);
        $hangUpRequestDto = new AgentVoiceSessionHangUpRequestDto();
        $hangUpRequestDto->callId = static::CALL_ID;

        static::assertTrue($this->fixtureController->hangUpAgentVoiceSession($hangUpRequestDto)->acknowledged);
    }

    public function testADeviceStatusIsOneEventInTheTraceOfTheCallsConversation(): void
    {
        $this->storeAnswersCallOfSignedInAccount(static::buildCallOfSignedInAccount(startedOffsetSeconds: -30));
        $this->agentVoiceCallStoreService->expects(static::never())->method('markCallEnded');
        $deviceStatusRequestDto = new AgentVoiceSessionDeviceStatusRequestDto();
        $deviceStatusRequestDto->callId = static::CALL_ID;
        $deviceStatusRequestDto->status = 'MEDIA_FAILED';
        $deviceStatusRequestDto->iceCandidateType = 'relay';
        $deviceStatusRequestDto->detail = 'ice failed';
        $deviceStatusRequestDto->occurredAtUnixMs = 1_800_000_000_123;

        static::assertTrue($this->fixtureController->reportAgentVoiceSessionDeviceStatus($deviceStatusRequestDto)->acknowledged);

        static::assertSame([1], $this->recordingConversationTraceEventsService->eventCountsPerWrite);
        $traceEvent = $this->recordingConversationTraceEventsService->recordedTraceEvents[0];
        static::assertSame(AIConversationTraceEvent::SOURCE_DEVICE, $traceEvent->source);
        static::assertSame('device.media_failed', $traceEvent->eventType);
        static::assertSame('call:' . static::CALL_ID . ':device.media_failed:1800000000123', $traceEvent->eventKey);
        static::assertSame('MEDIA_FAILED: ice failed', $traceEvent->summary);
        static::assertSame(static::CONVERSATION_ID, $traceEvent->aiConversationId);
        static::assertSame(static::CALL_ID, $traceEvent->agentVoiceCallId);
        static::assertSame(1_800_000_000_123, $traceEvent->occurredAtUnixMs);
        static::assertSame(['iceCandidateType' => 'relay', 'detail' => 'ice failed'], json_decode((string)$traceEvent->payload, true), 'the fields the client did not send are left out');
    }

    public function testTheStateOfARunningCallNamesNoEnd(): void
    {
        $agentVoiceCall = static::buildCallOfSignedInAccount(startedOffsetSeconds: -30);
        $agentVoiceCall->endReason = AgentVoiceCall::END_REASON_COMPLETED;
        $this->storeAnswersCallOfSignedInAccount($agentVoiceCall);

        $callStateRequestDto = $this->buildCallStateRequestDto();
        $responseDto = $this->fixtureController->getAgentVoiceSessionCallState($callStateRequestDto);

        static::assertSame(static::CALL_ID, $responseDto->callId);
        static::assertNull($responseDto->endedAt);
        static::assertNull($responseDto->endReason, 'a reason without an end is not an end');
    }

    public function testTheStateOfAnEndedCallNamesItsEndInUtcAndItsReason(): void
    {
        $agentVoiceCall = static::buildCallOfSignedInAccount(startedOffsetSeconds: -300, endedOffsetSeconds: -60);
        $agentVoiceCall->endReason = AgentVoiceCall::END_REASON_ENDED_BY_AGENT;
        $this->storeAnswersCallOfSignedInAccount($agentVoiceCall);
        $this->agentVoiceCallsService->expects(static::never())->method(static::anything());

        $callStateRequestDto = $this->buildCallStateRequestDto();
        $responseDto = $this->fixtureController->getAgentVoiceSessionCallState($callStateRequestDto);

        static::assertSame('2027-01-15T07:59:00Z', $responseDto->endedAt);
        static::assertSame(AgentVoiceCall::END_REASON_ENDED_BY_AGENT, $responseDto->endReason);
    }

    protected function buildAnswerRequestDto(): AgentVoiceSessionAnswerRequestDto
    {
        $answerRequestDto = new AgentVoiceSessionAnswerRequestDto();
        $answerRequestDto->callId = static::CALL_ID;
        $answerRequestDto->answerToken = static::ANSWER_TOKEN;

        return $answerRequestDto;
    }

    protected function buildDeclineRequestDto(): AgentVoiceSessionDeclineRequestDto
    {
        $declineRequestDto = new AgentVoiceSessionDeclineRequestDto();
        $declineRequestDto->callId = static::CALL_ID;
        $declineRequestDto->answerToken = static::ANSWER_TOKEN;

        return $declineRequestDto;
    }

    protected function buildCallStateRequestDto(): AgentVoiceSessionCallStateGetRequestDto
    {
        $callStateRequestDto = new AgentVoiceSessionCallStateGetRequestDto();
        $callStateRequestDto->callId = static::CALL_ID;

        return $callStateRequestDto;
    }
}
