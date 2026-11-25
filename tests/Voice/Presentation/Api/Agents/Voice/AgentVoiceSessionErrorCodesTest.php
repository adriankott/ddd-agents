<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Infrastructure\Exceptions\BadGatewayException;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\GoneException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenRequestDto;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The error table of the contract (interfaces, section 5), one row per code: a route answers it, with the framework's
 * exception class of its HTTP status, the status itself as the exception's code, and the code as the whole message.
 */
class AgentVoiceSessionErrorCodesTest extends AgentVoiceSessionsTestCase
{
    /** @return array<string, array{string, class-string<Throwable>, int}> */
    public static function provideContractErrorCodes(): array
    {
        return [
            'invalid_sdp' => ['invalid_sdp', BadRequestException::class, 400],
            'call_not_answered' => ['call_not_answered', BadRequestException::class, 400],
            'feature_not_available' => ['feature_not_available', ForbiddenException::class, 403],
            'no_ai_budget' => ['no_ai_budget', ForbiddenException::class, 403],
            'daily_limit_reached' => ['daily_limit_reached', ForbiddenException::class, 403],
            'call_not_found' => ['call_not_found', NotFoundException::class, 404],
            'conversation_not_found' => ['conversation_not_found', NotFoundException::class, 404],
            'call_already_running' => ['call_already_running', ConflictException::class, 409],
            'already_answered' => ['already_answered', ConflictException::class, 409],
            'ring_expired' => ['ring_expired', GoneException::class, 410],
            'provider_unavailable' => ['provider_unavailable', BadGatewayException::class, 502],
        ];
    }

    /** @param class-string<Throwable> $expectedExceptionClassName */
    #[DataProvider('provideContractErrorCodes')]
    public function testEveryCodeOfTheContractIsAnsweredWithItsHttpClass(string $errorCode, string $expectedExceptionClassName, int $expectedHttpStatus): void
    {
        try {
            $this->runRouteThatAnswers($errorCode);
            static::fail('no route answered ' . $errorCode);
        } catch (Throwable $routeRefusal) {
            static::assertSame($expectedExceptionClassName, $routeRefusal::class, $errorCode);
            static::assertSame($expectedHttpStatus, $routeRefusal->getCode(), $errorCode);
            static::assertSame($errorCode, $routeRefusal->getMessage());
        }
    }

    protected function runRouteThatAnswers(string $errorCode): void
    {
        $openRequestDto = new AgentVoiceSessionOpenRequestDto();
        $openRequestDto->sdpOffer = static::SDP_OFFER;
        $rungCall = static::buildCallOfSignedInAccount(ringDeadlineOffsetSeconds: 60, startedOffsetSeconds: -3, direction: AgentVoiceCall::DIRECTION_OUTBOUND);
        switch ($errorCode) {
            case 'invalid_sdp':
                $openRequestDto->sdpOffer = 'not an offer';
                break;
            case 'call_not_answered':
                $rungCall->startedAt = null;
                $openRequestDto->callId = static::CALL_ID;
                $this->storeAnswersCallOfSignedInAccount($rungCall);
                break;
            case 'feature_not_available':
                $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));
                break;
            case 'no_ai_budget':
                $this->agentVoiceCallAdmissionService->method('findRefusalOfCallStart')->willReturn(AgentVoiceCallRefusal::forNoAiBudget());
                break;
            case 'daily_limit_reached':
                $this->agentVoiceCallAdmissionService->method('findRefusalOfCallStart')->willReturn(AgentVoiceCallRefusal::forDailyLimitReached());
                break;
            case 'call_not_found':
                $openRequestDto->callId = static::CALL_ID + 1;
                $this->storeAnswersCallOfSignedInAccount($rungCall);
                break;
            case 'conversation_not_found':
                $openRequestDto->conversationId = 99;
                $this->aIConversationsService->method('findVisibleToCustomer')->willThrowException(new NotFoundException('AIConversation 99 not found.'));
                break;
            case 'call_already_running':
                $this->agentVoiceCallsService->method('findOpenAppCallForAccountId')->willReturn($rungCall);
                break;
            case 'already_answered':
                $this->storeAnswersCallOfSignedInAccount($rungCall);
                $this->agentVoiceRingService->method('answerRing')->willThrowException(new ConflictException('already_answered'));
                $answerRequestDto = new AgentVoiceSessionAnswerRequestDto();
                $answerRequestDto->callId = static::CALL_ID;
                $answerRequestDto->answerToken = '1800000090.signature';
                $this->fixtureController->answerAgentVoiceSession($answerRequestDto);
                return;
            case 'ring_expired':
                $rungCall->endedAt = static::buildMoment(-1);
                $openRequestDto->callId = static::CALL_ID;
                $this->storeAnswersCallOfSignedInAccount($rungCall);
                break;
            case 'provider_unavailable':
                $windowCall = static::buildCallOfSignedInAccount();
                $windowCall->voiceProvider = 'openAiLive';
                $this->agentVoiceCallsService->method('createCallOfWindowSession')->willReturn($windowCall);
                $this->scriptedSessionVoiceProviderAdapter->providerFailureText = 'GPT-Live refused the SDP offer (HTTP 503): overloaded';
                break;
            default:
                throw new LogicException('No route is staged for ' . $errorCode);
        }
        $this->fixtureController->callBuildVoiceSessionOpenedResponse($openRequestDto, null);
    }
}
