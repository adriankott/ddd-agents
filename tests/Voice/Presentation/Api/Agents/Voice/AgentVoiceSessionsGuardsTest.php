<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionCallStateGetRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeclineRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeviceStatusRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionHangUpRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionRingRequestDto;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The guards of every voice route (the five routed methods and the two builders): the chat's access gate first, the
 * voice gate second, before anything else; a gate that refuses stops the route before the next gate and before any
 * read. While `AI.agentVoice.enabled` is false, and without a signed-in account, every route answers
 * `403 feature_not_available` before the call store, the calls service or the ring start is asked.
 */
class AgentVoiceSessionsGuardsTest extends AgentVoiceSessionsTestCase
{
    public const string ROUTE_ANSWER = 'answer';

    public const string ROUTE_DECLINE = 'decline';

    public const string ROUTE_HANG_UP = 'hangUp';

    public const string ROUTE_DEVICE_STATUS = 'deviceStatus';

    public const string ROUTE_CALL_STATE = 'callState';

    public const string ROUTE_OPEN = 'open';

    public const string ROUTE_RING = 'ring';

    /** @return array<string, array{string}> */
    public static function provideRoutes(): array
    {
        return [
            'POST /{callId}/answer' => [static::ROUTE_ANSWER],
            'POST /{callId}/decline' => [static::ROUTE_DECLINE],
            'POST /{callId}/hangUp' => [static::ROUTE_HANG_UP],
            'POST /{callId}/deviceStatus' => [static::ROUTE_DEVICE_STATUS],
            'GET /{callId}' => [static::ROUTE_CALL_STATE],
            'open (builder)' => [static::ROUTE_OPEN],
            'ring (builder)' => [static::ROUTE_RING],
        ];
    }

    #[DataProvider('provideRoutes')]
    public function testTheChatGateRunsFirstThenTheVoiceGate(string $routeName): void
    {
        $this->fixtureController->guardRefusingAccess = AgentVoiceSessionsFixtureController::GUARD_VOICE_SESSION_ACCESS;
        $this->expectNothingIsRead();

        try {
            $this->invokeRoute($routeName);
            static::fail('the refusing voice gate must stop the route');
        } catch (ForbiddenException $forbiddenException) {
            static::assertSame(AgentVoiceSessionsFixtureController::GUARD_REFUSAL_MESSAGE, $forbiddenException->getMessage());
        }
        static::assertSame(
            [AgentVoiceSessionsFixtureController::GUARD_CONVERSATION_ACCESS, AgentVoiceSessionsFixtureController::GUARD_VOICE_SESSION_ACCESS],
            $this->fixtureController->guardCalls
        );
    }

    #[DataProvider('provideRoutes')]
    public function testARefusingChatGateStopsTheRouteBeforeTheVoiceGate(string $routeName): void
    {
        $this->fixtureController->guardRefusingAccess = AgentVoiceSessionsFixtureController::GUARD_CONVERSATION_ACCESS;
        $this->expectNothingIsRead();

        try {
            $this->invokeRoute($routeName);
            static::fail('the refusing chat gate must stop the route');
        } catch (ForbiddenException $forbiddenException) {
            static::assertSame(AgentVoiceSessionsFixtureController::GUARD_REFUSAL_MESSAGE, $forbiddenException->getMessage());
        }
        static::assertSame([AgentVoiceSessionsFixtureController::GUARD_CONVERSATION_ACCESS], $this->fixtureController->guardCalls);
    }

    #[DataProvider('provideRoutes')]
    public function testASwitchedOffVoiceChannelAnswersFeatureNotAvailableBeforeAnyRead(string $routeName): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));
        $this->expectNothingIsRead();

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('feature_not_available');
        try {
            $this->invokeRoute($routeName);
        } finally {
            static::assertSame(
                [AgentVoiceSessionsFixtureController::GUARD_CONVERSATION_ACCESS, AgentVoiceSessionsFixtureController::GUARD_VOICE_SESSION_ACCESS],
                $this->fixtureController->guardCalls,
                'the gates still run first'
            );
        }
    }

    #[DataProvider('provideRoutes')]
    public function testARequestWithoutASignedInAccountAnswersFeatureNotAvailableBeforeAnyRead(string $routeName): void
    {
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        $this->expectNothingIsRead();

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('feature_not_available');
        $this->invokeRoute($routeName);
    }

    protected function expectNothingIsRead(): void
    {
        $this->agentVoiceCallStoreService->expects(static::never())->method(static::anything());
        $this->agentVoiceCallsService->expects(static::never())->method(static::anything());
        $this->agentVoiceCallStartsService->expects(static::never())->method(static::anything());
        $this->expectTheAdmissionIsAskedNothingButWhetherVoiceCanRunHere();
        $this->agentVoiceRingService->expects(static::never())->method(static::anything());
        $this->aIConversationsService->expects(static::never())->method(static::anything());
    }

    protected function invokeRoute(string $routeName): void
    {
        switch ($routeName) {
            case static::ROUTE_ANSWER:
                $answerRequestDto = new AgentVoiceSessionAnswerRequestDto();
                $answerRequestDto->callId = static::CALL_ID;
                $answerRequestDto->answerToken = '1800000090.token';
                $this->fixtureController->answerAgentVoiceSession($answerRequestDto);
                return;
            case static::ROUTE_DECLINE:
                $declineRequestDto = new AgentVoiceSessionDeclineRequestDto();
                $declineRequestDto->callId = static::CALL_ID;
                $declineRequestDto->answerToken = '1800000090.token';
                $this->fixtureController->declineAgentVoiceSession($declineRequestDto);
                return;
            case static::ROUTE_HANG_UP:
                $hangUpRequestDto = new AgentVoiceSessionHangUpRequestDto();
                $hangUpRequestDto->callId = static::CALL_ID;
                $this->fixtureController->hangUpAgentVoiceSession($hangUpRequestDto);
                return;
            case static::ROUTE_DEVICE_STATUS:
                $deviceStatusRequestDto = new AgentVoiceSessionDeviceStatusRequestDto();
                $deviceStatusRequestDto->callId = static::CALL_ID;
                $deviceStatusRequestDto->status = 'MEDIA_CONNECTED';
                $this->fixtureController->reportAgentVoiceSessionDeviceStatus($deviceStatusRequestDto);
                return;
            case static::ROUTE_CALL_STATE:
                $callStateRequestDto = new AgentVoiceSessionCallStateGetRequestDto();
                $callStateRequestDto->callId = static::CALL_ID;
                $this->fixtureController->getAgentVoiceSessionCallState($callStateRequestDto);
                return;
            case static::ROUTE_OPEN:
                $openRequestDto = new AgentVoiceSessionOpenRequestDto();
                $openRequestDto->sdpOffer = static::SDP_OFFER;
                $this->fixtureController->callBuildVoiceSessionOpenedResponse($openRequestDto, null);
                return;
            case static::ROUTE_RING:
                $this->fixtureController->callBuildVoiceCallRingingResponse(new AgentVoiceSessionRingRequestDto(), null);
                return;
        }
        throw new LogicException('Unknown route ' . $routeName);
    }
}
