<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallTraceEventsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceRingService;
use DDD\Domain\AI\Services\Voice\AgentVoiceSessionContextsService;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadGatewayException;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\GoneException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationControllerHooksTrait;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAcknowledgeResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionCallStateGetRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionCallStateGetResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeclineRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeviceStatusRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionHangUpRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionRingRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionRingResponseDto;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;
use Throwable;

/**
 * The routes of a voice call in the application's own window: the client negotiates a WebRTC session with the voice
 * provider THROUGH the application, which holds the provider's credential, composes the briefing of the session and
 * attaches the session worker. The audio itself flows between the client and the provider and never passes the
 * application.
 *
 * Two ways into a call. The owner calls the agent: `open` creates the call and its session. The agent calls the owner:
 * the ring reaches every open client of the account as one message of the realtime port, a client answers it with
 * `answer`, and the client that won opens the session with `open` and the call id.
 *
 * An application mounts the trait in a controller of its own, like the chat traits. The five routes that name a call
 * are the trait's. The two that name what the agent works for (`open`, and `ring` for an application with a "call me"
 * button) are declared by the application over a builder method, because only the application knows where its request
 * names the agent space:
 *
 *     #[Route('/ai/voiceSession')]
 *     #[Tag(group: 'AI', name: 'AgentVoiceSession', description: 'The lifecycle of a voice call with the agent')]
 *     class AgentVoiceSessionController extends ClientController
 *     {
 *         use AgentVoiceSessionsTrait;
 *
 *         #[Post('/open')]
 *         #[Summary('AgentVoiceSession Open')]
 *         public function openAgentVoiceSession(AgentVoiceSessionOpenRequestDto &$requestDto): AgentVoiceSessionOpenResponseDto
 *         {
 *             return $this->buildVoiceSessionOpenedResponse($requestDto, … the agent space of the request, or null …);
 *         }
 *     }
 *
 * GUARDS. Every routed method and both builders call {@see self::authorizeConversationAccess()} of the chat's hooks
 * trait first, then {@see self::authorizeVoiceSessionAccess()}. Whether a call may START is asked of
 * {@see AgentVoiceCallAdmissionService} (by `open` here, by the ring start for `ring`); its refusal is answered as
 * `403` with its code. The routes that name a call act on a call of the signed-in account only; a call of another
 * account reads as `404 call_not_found`. The package ships no request listener.
 *
 * SWITCHED OFF. While the voice channel cannot run in the installation
 * ({@see AgentVoiceCallAdmissionService::canVoiceRunHere()}: `AI.agentVoice.enabled` is false, or the session worker's
 * WebSocket library is not installed) every route answers `403 feature_not_available`, from the one place each of them
 * resolves the account of its request ({@see self::requireAccountOfVoiceRequest()}), before the call store is read:
 * the call table is not required while voice is switched off.
 *
 * ERRORS are codes in the message of the framework's exceptions: `400 invalid_sdp`, `400 call_not_answered`,
 * `400 invalid_answer_token`, `403` with the admission's code (`feature_not_available`, `no_ai_budget`,
 * `daily_limit_reached`), `404 call_not_found`, `404 conversation_not_found`, `409 call_already_running`,
 * `409 already_answered`, `410 ring_expired`, `502 provider_unavailable`.
 *
 * OVERRIDE POINTS of the application's controller:
 * - {@see self::authorizeVoiceSessionAccess()}: an access rule that is stricter for voice than for the chat;
 * - {@see self::requireAccountOfVoiceRequest()}: whose request it is;
 * - {@see self::findOwnCall()}: which calls the account of the request may act on (an application with an oversight
 *   role, or one that reads calls under the rights of their conversation);
 * - {@see self::findConversationOfVoiceRequest()}: which conversations a call may continue;
 * - {@see self::throwForbiddenIfCallStartIsRefused()}: the admission of a session open;
 * - {@see self::openProviderSessionForCall()} and {@see self::getVoiceProviderAdapterOfCall()}: the session of a
 *   further provider family;
 * - {@see self::findClientIdOfVoiceRequest()}: the id a client states for itself, for an application whose request
 *   carries one;
 * - the routed methods themselves: each body is a protected `build…Response()` an application calls from its own
 *   redeclared route, and each request DTO is read as the application's subclass of it
 *   ({@see self::resolveRequestDtoOfRoutedMethod()});
 * - the `get…Service()` methods: the points a test replaces.
 */
trait AgentVoiceSessionsTrait
{
    use AgentConversationControllerHooksTrait;

    /**
     * A client answered the ring of a call. The answer token is redeemed and the call is claimed; the claim is atomic
     * and exactly one client per call ever wins it. The ring stops on every other client. The winner goes on to `open`
     * with this call id.
     *
     * @throws NotFoundException when the call is not a call of this account (`call_not_found`)
     * @throws BadRequestException when the token is malformed or forged (`invalid_answer_token`)
     * @throws GoneException when the ring window has passed (`ring_expired`)
     * @throws ConflictException when another client already won (`already_answered`)
     * @throws ForbiddenException
     */
    #[Post('/{callId}/answer', requirements: ['callId' => '\d+'])]
    #[Summary('AgentVoiceSession Answer')]
    public function answerAgentVoiceSession(AgentVoiceSessionAnswerRequestDto &$requestDto): AgentVoiceSessionAnswerResponseDto
    {
        $this->authorizeConversationAccess();
        $this->authorizeVoiceSessionAccess();
        $requestDto = $this->resolveRequestDtoOfRoutedMethod(__FUNCTION__, $requestDto);

        return $this->buildVoiceCallAnsweredResponse($requestDto);
    }

    /**
     * A client declined the ring of a call. The open clients share the one ring, so it stops on all of them and the
     * call nobody answered ends as DECLINED. Idempotent: a ring that is already over simply acknowledges.
     *
     * @throws NotFoundException when the call is not a call of this account (`call_not_found`)
     * @throws BadRequestException when the token is malformed or forged (`invalid_answer_token`)
     * @throws GoneException when the ring window has passed (`ring_expired`)
     * @throws ForbiddenException
     */
    #[Post('/{callId}/decline', requirements: ['callId' => '\d+'])]
    #[Summary('AgentVoiceSession Decline')]
    public function declineAgentVoiceSession(AgentVoiceSessionDeclineRequestDto &$requestDto): AgentVoiceSessionAcknowledgeResponseDto
    {
        $this->authorizeConversationAccess();
        $this->authorizeVoiceSessionAccess();
        $requestDto = $this->resolveRequestDtoOfRoutedMethod(__FUNCTION__, $requestDto);

        return $this->buildVoiceCallDeclinedResponse($requestDto);
    }

    /**
     * Ends a call. Idempotent: a call that already ended acknowledges without a second call marker. A client sends
     * this even when its media path died; the sweep of stale calls is only the fallback.
     *
     * Ending the call alone is not enough: closing the client's audio leg does NOT end the provider session, because
     * the session worker is still attached to it, and its silence check would then talk the model into a conversation
     * with itself on the dead line. So the provider is asked to hang up too; best effort, the worker's own check for
     * an end from outside is the backstop. Sent even for a call that already ended, so a repeated hang-up can finish
     * what a lost control call could not. A call that still RINGS stops ringing
     * ({@see AgentVoiceCallsService::endCallNow()}).
     *
     * @throws NotFoundException when the call is not a call of this account (`call_not_found`)
     * @throws ForbiddenException
     */
    #[Post('/{callId}/hangUp', requirements: ['callId' => '\d+'])]
    #[Summary('AgentVoiceSession Hang Up')]
    public function hangUpAgentVoiceSession(AgentVoiceSessionHangUpRequestDto &$requestDto): AgentVoiceSessionAcknowledgeResponseDto
    {
        $this->authorizeConversationAccess();
        $this->authorizeVoiceSessionAccess();
        $requestDto = $this->resolveRequestDtoOfRoutedMethod(__FUNCTION__, $requestDto);

        return $this->buildVoiceCallHungUpResponse($requestDto);
    }

    /**
     * A client-side event of a call. The audio never passes the application, so a failure on the client is invisible
     * to support unless the client reports it. The event is logged and written into the trace of the call's
     * conversation; the answer is an acknowledgement whatever the event says.
     *
     * @throws NotFoundException when the call is not a call of this account (`call_not_found`)
     * @throws ForbiddenException
     */
    #[Post('/{callId}/deviceStatus', requirements: ['callId' => '\d+'])]
    #[Summary('AgentVoiceSession Device Status')]
    public function reportAgentVoiceSessionDeviceStatus(AgentVoiceSessionDeviceStatusRequestDto &$requestDto): AgentVoiceSessionAcknowledgeResponseDto
    {
        $this->authorizeConversationAccess();
        $this->authorizeVoiceSessionAccess();
        $requestDto = $this->resolveRequestDtoOfRoutedMethod(__FUNCTION__, $requestDto);

        return $this->buildVoiceCallDeviceStatusRecordedResponse($requestDto);
    }

    /**
     * Whether the call is over and why: `callId`, `endedAt`, `endReason`. A client reads it when it has a reason to
     * believe the SERVER ended the call (the change signal of the call's conversation, the provider's data channel
     * closing, the media connection dropping) and then ends its own view with the right words instead of "call
     * failed": the agent hung up (`ENDED_BY_AGENT`), a limit was reached, or the call simply ended. A read only:
     * nothing is written.
     *
     * @throws NotFoundException when the call is not a call of this account (`call_not_found`)
     * @throws ForbiddenException
     */
    #[Get('/{callId}', requirements: ['callId' => '\d+'])]
    #[Summary('AgentVoiceSession Call State')]
    public function getAgentVoiceSessionCallState(AgentVoiceSessionCallStateGetRequestDto &$requestDto): AgentVoiceSessionCallStateGetResponseDto
    {
        $this->authorizeConversationAccess();
        $this->authorizeVoiceSessionAccess();
        $requestDto = $this->resolveRequestDtoOfRoutedMethod(__FUNCTION__, $requestDto);

        return $this->buildVoiceCallStateResponse($requestDto);
    }

    /**
     * The body of the application's `open` route. Opens a voice session: asks the admission, creates the call (and its
     * conversation when the request names none), hands the client's SDP offer to the voice provider with the composed
     * briefing, and answers with the provider's SDP answer. With a `callId` in the request the session attaches to the
     * rung call this client answered instead of creating one.
     *
     * @param AgentVoiceSessionOpenRequestDto $requestDto
     * @param AgentSpace|null $agentSpace The space the call works for, resolved by the application's route; null = the account as a whole
     * @return AgentVoiceSessionOpenResponseDto
     * @throws BadRequestException `invalid_sdp`, `call_not_answered`
     * @throws ForbiddenException the admission's code
     * @throws NotFoundException `conversation_not_found`, `call_not_found`
     * @throws ConflictException `call_already_running`, `already_answered`
     * @throws GoneException `ring_expired`
     * @throws BadGatewayException `provider_unavailable`
     */
    protected function buildVoiceSessionOpenedResponse(
        AgentVoiceSessionOpenRequestDto $requestDto,
        ?AgentSpace $agentSpace
    ): AgentVoiceSessionOpenResponseDto {
        $this->authorizeConversationAccess();
        $this->authorizeVoiceSessionAccess();
        $openReceivedAtUnixMs = AIConversationTraceEvent::nowUnixMs();
        $authAccount = $this->requireAccountOfVoiceRequest();

        if (!str_starts_with(ltrim($requestDto->sdpOffer), 'v=')) {
            throw new BadRequestException('invalid_sdp');
        }

        // The client WON the ring of this call: it attaches to the call that exists instead of creating one. From here
        // it is the same session open on that call.
        if ($requestDto->callId !== null) {
            return $this->openAnsweredCall($requestDto, $authAccount, $agentSpace, $openReceivedAtUnixMs);
        }

        // One live call per account, checked before anything costs money.
        if ($this->getAgentVoiceCallsService()->findOpenAppCallForAccountId((int)$authAccount->id) !== null) {
            throw new ConflictException('call_already_running');
        }

        // The conversation this call continues, read under the caller's rights: a foreign id reads as absent.
        $aiConversation = $requestDto->conversationId !== null
            ? $this->findConversationOfVoiceRequest((int)$requestDto->conversationId)
            : null;

        // Asked BEFORE the provider sees the offer: the audio of a session passes no other check on its way.
        $this->throwForbiddenIfCallStartIsRefused($authAccount, $agentSpace, $aiConversation);

        // The call of a session the owner opens: the owner calls the agent, and who is talking is known from the
        // sign-in, so nothing rings. It gets a new conversation when the request names none. `startedAt` stays empty
        // until the provider accepted: a call that never connected must not trip the one-call check.
        $agentVoiceCall = $this->getAgentVoiceCallsService()->createCallOfWindowSession($authAccount, $agentSpace, $aiConversation);

        return $this->openProviderSessionForCall(
            $agentVoiceCall,
            $aiConversation ?? $this->findConversationOfCall($agentVoiceCall),
            $requestDto->sdpOffer,
            $openReceivedAtUnixMs
        );
    }

    /**
     * The body of the application's `ring` route ("call me"): the agent rings every open client of the account, and
     * the first one to answer gets the call. The trigger is the owner asking to be called, not a client reacting to a
     * ring; the other routes are the client's side of the same call.
     *
     * A ring that reaches nobody answers 200 with `anyDeviceReached: false` rather than an error: the call exists and
     * is over; it is a finished call, not a failed request.
     *
     * @param AgentVoiceSessionRingRequestDto $requestDto
     * @param AgentSpace|null $agentSpace The space the call works for, resolved by the application's route; null = the account as a whole
     * @return AgentVoiceSessionRingResponseDto
     * @throws BadRequestException when nothing can ring for the owner now
     * @throws ForbiddenException the admission's code
     * @throws NotFoundException `conversation_not_found`
     * @throws ConflictException `call_already_running`
     */
    protected function buildVoiceCallRingingResponse(
        AgentVoiceSessionRingRequestDto $requestDto,
        ?AgentSpace $agentSpace
    ): AgentVoiceSessionRingResponseDto {
        $this->authorizeConversationAccess();
        $this->authorizeVoiceSessionAccess();
        $authAccount = $this->requireAccountOfVoiceRequest();
        if ($this->getAgentVoiceCallsService()->findOpenAppCallForAccountId((int)$authAccount->id) !== null) {
            throw new ConflictException('call_already_running');
        }

        $aiConversation = $requestDto->conversationId !== null
            ? $this->findConversationOfVoiceRequest((int)$requestDto->conversationId)
            : null;

        // The ring start asks the admission itself and refuses with its code. A ring for a conversation of another
        // account the caller may read writes a call and markers that belong to the rung account, hence without the
        // read rights of the caller.
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentVoiceCall = $this->getAgentVoiceCallStartsService()->startRing($authAccount, $agentSpace, $aiConversation, $requestDto->task);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        $responseDto = new AgentVoiceSessionRingResponseDto();
        $responseDto->callId = (int)$agentVoiceCall->id;
        $responseDto->conversationId = $agentVoiceCall->aiConversationId !== null
            ? (int)$agentVoiceCall->aiConversationId
            : null;
        $responseDto->ringExpiresAt = $agentVoiceCall->ringDeadlineAt !== null
            ? $this->formatTimeOfVoiceCallInUtc($agentVoiceCall->ringDeadlineAt)
            : null;
        // The ring start ends the call itself when the ring was delivered nowhere, which is the one case a screen must
        // not present as "it is ringing".
        $responseDto->anyDeviceReached = $agentVoiceCall->endedAt === null;

        return $responseDto;
    }

    /**
     * The body of {@see self::answerAgentVoiceSession()}.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws GoneException
     * @throws ConflictException
     * @throws ForbiddenException
     */
    protected function buildVoiceCallAnsweredResponse(AgentVoiceSessionAnswerRequestDto $requestDto): AgentVoiceSessionAnswerResponseDto
    {
        $agentVoiceCall = $this->findOwnCall($requestDto->callId);
        // The claim says no in two cases, and the ring service tells the client which: another client holds the
        // answer (`already_answered`), or the deadline passed between the token check and the claim (`ring_expired`).
        $this->getAgentVoiceRingService()->answerRing(
            $agentVoiceCall,
            $requestDto->answerToken,
            $this->findClientIdOfVoiceRequest($requestDto)
        );

        $responseDto = new AgentVoiceSessionAnswerResponseDto();
        $responseDto->won = true;
        $responseDto->conversationId = $agentVoiceCall->aiConversationId;

        return $responseDto;
    }

    /**
     * The body of {@see self::declineAgentVoiceSession()}.
     *
     * @throws NotFoundException
     * @throws BadRequestException
     * @throws GoneException
     * @throws ForbiddenException
     */
    protected function buildVoiceCallDeclinedResponse(AgentVoiceSessionDeclineRequestDto $requestDto): AgentVoiceSessionAcknowledgeResponseDto
    {
        $agentVoiceCall = $this->findOwnCall($requestDto->callId);
        try {
            // Ends the call as DECLINED behind its end claim, so a deadline expiry at the same moment writes nothing
            // twice; the agent reads at its next turn that the owner declined (no follow-up turn: the owner refused).
            $this->getAgentVoiceRingService()->declineRing($agentVoiceCall, $requestDto->answerToken);
        } catch (ConflictException) {
            // The ring is already over (answered elsewhere, withdrawn, expired): declining it now is a no-op, not an
            // error.
        }

        return new AgentVoiceSessionAcknowledgeResponseDto();
    }

    /**
     * The body of {@see self::hangUpAgentVoiceSession()}.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    protected function buildVoiceCallHungUpResponse(AgentVoiceSessionHangUpRequestDto $requestDto): AgentVoiceSessionAcknowledgeResponseDto
    {
        $agentVoiceCall = $this->findOwnCall($requestDto->callId);
        // The one end path (shared with the agent's endVoiceCall): the call and its call-end marker, the ring of a
        // call that still rang, the provider session hung up by the provider pinned on the call.
        $this->getAgentVoiceCallsService()->endCallNow(
            $agentVoiceCall,
            $requestDto->reason === 'MEDIA_FAILED' ? AgentVoiceCall::END_REASON_FAILED : AgentVoiceCall::END_REASON_COMPLETED
        );

        return new AgentVoiceSessionAcknowledgeResponseDto();
    }

    /**
     * The body of {@see self::reportAgentVoiceSessionDeviceStatus()}.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    protected function buildVoiceCallDeviceStatusRecordedResponse(AgentVoiceSessionDeviceStatusRequestDto $requestDto): AgentVoiceSessionAcknowledgeResponseDto
    {
        $receivedAtUnixMs = AIConversationTraceEvent::nowUnixMs();
        $agentVoiceCall = $this->findOwnCall($requestDto->callId);
        DDDService::instance()->getLogger()->info(
            'AgentVoiceSession deviceStatus: call ' . $agentVoiceCall->id
            . ' status=' . $requestDto->status
            . ($requestDto->iceCandidateType !== null ? ' ice=' . $requestDto->iceCandidateType : '')
            . ($requestDto->roundTripTimeMs !== null ? ' rttMs=' . $requestDto->roundTripTimeMs : '')
            . ($requestDto->detail !== null ? ' detail=' . $requestDto->detail : '')
            . ($requestDto->occurredAtUnixMs !== null ? ' atMs=' . $requestDto->occurredAtUnixMs : '')
        );
        // The client's timeline in the trace of the conversation: the client's time AND the time of receipt, so that a
        // reader sees both and the clock skew between client and server stays visible.
        $deviceStatusDetails = array_filter([
            'iceCandidateType' => $requestDto->iceCandidateType,
            'roundTripTimeMs' => $requestDto->roundTripTimeMs,
            'detail' => $requestDto->detail,
        ], static fn(mixed $value): bool => $value !== null);
        $this->getConversationTraceEventsServiceOfVoiceRoutes()->recordEvent($this->getAgentVoiceCallTraceEventsService()->buildTraceEventForCall(
            $agentVoiceCall,
            AIConversationTraceEvent::SOURCE_DEVICE,
            'device.' . strtolower(mb_substr($requestDto->status, 0, 40)),
            (string)($requestDto->occurredAtUnixMs ?? $receivedAtUnixMs),
            $requestDto->status . ($requestDto->detail !== null ? ': ' . $requestDto->detail : ''),
            $deviceStatusDetails,
            null,
            $requestDto->occurredAtUnixMs ?? $receivedAtUnixMs,
            $receivedAtUnixMs
        ));

        return new AgentVoiceSessionAcknowledgeResponseDto();
    }

    /**
     * The body of {@see self::getAgentVoiceSessionCallState()}.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    protected function buildVoiceCallStateResponse(AgentVoiceSessionCallStateGetRequestDto $requestDto): AgentVoiceSessionCallStateGetResponseDto
    {
        $agentVoiceCall = $this->findOwnCall($requestDto->callId);

        $responseDto = new AgentVoiceSessionCallStateGetResponseDto();
        $responseDto->callId = (int)$agentVoiceCall->id;
        $responseDto->endedAt = $agentVoiceCall->endedAt !== null
            ? $this->formatTimeOfVoiceCallInUtc($agentVoiceCall->endedAt)
            : null;
        $responseDto->endReason = $agentVoiceCall->endedAt !== null ? $agentVoiceCall->endReason : null;

        return $responseDto;
    }

    /**
     * The session open of a rung call: the call was WON by a client of this account. Its state is checked, the
     * admission is asked again, and the same provider session is opened on the call that exists.
     *
     * @throws NotFoundException when the call is not a call of this account, or runs on another medium (`call_not_found`)
     * @throws GoneException when the call already ended (`ring_expired`: the ring deadline beat the open)
     * @throws BadRequestException when the call was never answered (`call_not_answered`)
     * @throws ConflictException when another client answered it (`already_answered`) or its session exists already (`call_already_running`)
     * @throws ForbiddenException when the admission refuses between ring and answer
     * @throws BadGatewayException when the provider refuses (`provider_unavailable`)
     */
    protected function openAnsweredCall(
        AgentVoiceSessionOpenRequestDto $requestDto,
        Account $authAccount,
        ?AgentSpace $agentSpace,
        int $openReceivedAtUnixMs
    ): AgentVoiceSessionOpenResponseDto {
        $agentVoiceCall = $this->findOwnCall((int)$requestDto->callId);
        if ($agentVoiceCall->getChannelType() !== AgentVoiceCall::CHANNEL_TYPE_APP) {
            throw new NotFoundException('call_not_found');
        }
        if ($agentVoiceCall->endedAt !== null) {
            throw new GoneException('ring_expired');
        }
        if ($agentVoiceCall->startedAt === null) {
            // No client has won the claim: `open` with a call id only ever follows an `answer` that said won.
            throw new BadRequestException('call_not_answered');
        }
        if (!$this->getAgentVoiceRingService()->isCallAnsweredByClient($agentVoiceCall, $this->findClientIdOfVoiceRequest($requestDto))) {
            throw new ConflictException('already_answered');
        }
        if ($agentVoiceCall->providerCallId !== null) {
            throw new ConflictException('call_already_running');
        }
        $aiConversation = $this->findConversationOfCall($agentVoiceCall);

        // The admission was asked when the ring started, but a ring can sit for its whole deadline: it is asked again
        // before the provider starts billing audio.
        $this->throwForbiddenIfCallStartIsRefused($authAccount, $agentSpace, $aiConversation);

        return $this->openProviderSessionForCall($agentVoiceCall, $aiConversation, $requestDto->sdpOffer, $openReceivedAtUnixMs);
    }

    /**
     * The provider tail both ways into a call end in: compose the briefing of the session, resolve the adapter of the
     * call and write its name on a call that carries none ({@see self::pinVoiceProviderOnCallWithoutOne()}), create the
     * provider session from the SDP offer, store the provider's id of it, stamp the call as running (with its
     * call-start marker), start the session worker, and answer the client with the SDP answer.
     *
     * The session gets a SHORT instruction text plus the briefing as seed rows, and the model delegates what it cannot
     * answer to the agent, which stays the one that works. A missing conversation shrinks the briefing to a bare
     * opening rather than blocking the call. The client must have opened the provider's event data channel before its
     * offer: an offer without it is refused by the provider, which surfaces here as `provider_unavailable`.
     *
     * @throws BadGatewayException when the provider refuses the offer, or no adapter answers to the provider pinned on the call (`provider_unavailable`)
     */
    protected function openProviderSessionForCall(
        AgentVoiceCall $agentVoiceCall,
        ?AIConversation $aiConversation,
        string $sdpOffer,
        int $openReceivedAtUnixMs
    ): AgentVoiceSessionOpenResponseDto {
        $briefingStartedAtUnixMs = AIConversationTraceEvent::nowUnixMs();
        $agentVoiceSessionContextsService = $this->getAgentVoiceSessionContextsService();
        $effectiveVoiceSettings = $agentVoiceSessionContextsService->findEffectiveVoiceSettingsOfCall($agentVoiceCall, $aiConversation);
        // Without a conversation the model speaks before it hears anyone, so the language of the call's voice settings
        // decides the opening.
        $voiceSessionContext = $aiConversation instanceof AIConversation
            ? $agentVoiceSessionContextsService->buildVoiceSessionContext($aiConversation, $agentVoiceCall)
            : $agentVoiceSessionContextsService->buildVoiceSessionContextWithoutConversation($agentVoiceCall);
        $liveInstructions = $voiceSessionContext->composeLiveInstructions();
        $seedInputMessages = $voiceSessionContext->buildLiveCreationSeedInputMessages();

        try {
            $voiceProviderAdapter = $this->getVoiceProviderAdapterOfCall($agentVoiceCall);
        } catch (InternalErrorException $adapterResolutionFailure) {
            $this->endCallThatNeverConnected($agentVoiceCall);
            DDDService::instance()->getLogger()->error(
                'AgentVoiceSessionsTrait: no voice provider adapter for call ' . $agentVoiceCall->id
                . ': ' . $adapterResolutionFailure->getMessage()
            );
            throw new BadGatewayException('provider_unavailable');
        }
        $voiceProviderName = $voiceProviderAdapter->getProviderName();
        // Before the session exists: the session worker and the hang-up resolve their adapter by the name on the call.
        $this->pinVoiceProviderOnCallWithoutOne($agentVoiceCall, $voiceProviderName);
        $sessionCreationPayload = $voiceProviderAdapter->buildSessionCreationPayload(
            $sdpOffer,
            $liveInstructions,
            $effectiveVoiceSettings,
            $seedInputMessages
        );
        $providerStartedAtUnixMs = AIConversationTraceEvent::nowUnixMs();
        try {
            $liveSessionCreationResponse = $voiceProviderAdapter->createSessionFromSdpOffer($sessionCreationPayload);
        } catch (Throwable $providerRefusal) {
            // A shipped adapter answers every failure of the session creation (a refusal, a timeout, a missing key) as
            // a BadGatewayException with the provider's own words in the previous one: they go to the log and the
            // trace, never to a client. An application's adapter may throw anything else; whatever it is, no session
            // exists, so the call ends here and does not stay open on the account.
            $providerRefusalText = $voiceProviderAdapter->describeProviderFailure($providerRefusal);
            $this->endCallThatNeverConnected($agentVoiceCall);
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceSessionsTrait: the provider refused the SDP offer for call ' . $agentVoiceCall->id
                . ': ' . $providerRefusalText
            );
            $this->recordOpenTimeline(
                $agentVoiceCall,
                $voiceProviderName,
                $openReceivedAtUnixMs,
                $briefingStartedAtUnixMs,
                $providerStartedAtUnixMs,
                AIConversationTraceEvent::nowUnixMs(),
                strlen($liveInstructions),
                $providerRefusalText
            );
            throw new BadGatewayException('provider_unavailable');
        }
        $providerAnsweredAtUnixMs = AIConversationTraceEvent::nowUnixMs();

        $agentVoiceCallsService = $this->getAgentVoiceCallsService();
        $agentVoiceCallsService->persistProviderCallIdForOutboundCall($agentVoiceCall, $liveSessionCreationResponse->providerSessionId);
        // Stamps startedAt and writes the call-start marker into the conversation: from here the call is running. (On
        // a rung call the answer claim already stamped startedAt; the moment the media is up is the truer call start.)
        $agentVoiceCallsService->persistCallAnswered($agentVoiceCall);
        // The session worker: delegation, transcript, the admission while the call runs. Asynchronous: the client is
        // waiting for its SDP answer.
        $this->getAgentLiveVoiceSessionsService()->attachToCallSession($agentVoiceCall, true);
        $this->recordOpenTimeline(
            $agentVoiceCall,
            $voiceProviderName,
            $openReceivedAtUnixMs,
            $briefingStartedAtUnixMs,
            $providerStartedAtUnixMs,
            $providerAnsweredAtUnixMs,
            strlen($liveInstructions)
        );

        $responseDto = new AgentVoiceSessionOpenResponseDto();
        $responseDto->agentVoiceCall = $agentVoiceCall;
        $responseDto->sdpAnswer = $liveSessionCreationResponse->sdpAnswer;
        $responseDto->conversationId = $agentVoiceCall->aiConversationId;

        return $responseDto;
    }

    /**
     * Writes the name of the adapter the session is created on onto a call that carries none: a rung call is created
     * before anyone knows which provider its session will run on, so its provider is decided when it is opened. From
     * then on the session worker and the hang-up resolve the SAME adapter by the name on the call, whatever the
     * configured default says later. A call that carries a name (a session the owner opened) keeps it.
     *
     * Best effort on purpose: a name that cannot be written leaves the call without one, which resolves to the
     * configured default, the adapter resolved here. It must never take the call down.
     */
    protected function pinVoiceProviderOnCallWithoutOne(AgentVoiceCall $agentVoiceCall, string $voiceProviderName): void
    {
        if ($agentVoiceCall->voiceProvider !== null && $agentVoiceCall->voiceProvider !== '') {
            return;
        }
        try {
            $this->getAgentVoiceCallStoreService()->setVoiceProviderOfCall($agentVoiceCall, $voiceProviderName);
        } catch (Throwable $pinWriteFailure) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceSessionsTrait: could not write the voice provider on call ' . $agentVoiceCall->id
                . ': ' . $pinWriteFailure->getMessage()
            );
        }
    }

    /**
     * Ends a call whose provider session was never created, as FAILED and WITHOUT the call-end marker: the marker
     * would put a call into the chat that never happened. Best effort: a call nobody answered stops counting as open
     * on its own, and the sweep of stale calls closes an answered one.
     */
    protected function endCallThatNeverConnected(AgentVoiceCall $agentVoiceCall): void
    {
        try {
            $this->getAgentVoiceCallStoreService()->markCallEnded($agentVoiceCall, AgentVoiceCall::END_REASON_FAILED);
        } catch (Throwable) {
            // the call is left to the paths named above
        }
    }

    /**
     * The start-up timeline of an `open` as ONE batched trace write after the provider answered: request received,
     * briefing composed, provider session created, the application's own writes and the start of the worker done. The
     * durations are what the time a client waits for `open` splits into. The write fails open, and it comes after the
     * provider call so that a slow trace insert never delays the session itself.
     */
    protected function recordOpenTimeline(
        AgentVoiceCall $agentVoiceCall,
        string $voiceProviderName,
        int $openReceivedAtUnixMs,
        int $briefingStartedAtUnixMs,
        int $providerStartedAtUnixMs,
        int $providerAnsweredAtUnixMs,
        int $instructionsLength,
        ?string $providerRefusal = null
    ): void {
        $openFinishedAtUnixMs = AIConversationTraceEvent::nowUnixMs();
        $source = AIConversationTraceEvent::SOURCE_VOICE_WORKER;
        $discriminator = (string)$openReceivedAtUnixMs;
        $agentVoiceCallTraceEventsService = $this->getAgentVoiceCallTraceEventsService();
        $traceEvents = [
            $agentVoiceCallTraceEventsService->buildTraceEventForCall(
                $agentVoiceCall,
                $source,
                'call.open_received',
                $discriminator,
                'open received (' . $voiceProviderName . ', ' . strtolower((string)$agentVoiceCall->direction) . ')',
                [],
                null,
                $openReceivedAtUnixMs
            ),
            $agentVoiceCallTraceEventsService->buildTraceEventForCall(
                $agentVoiceCall,
                $source,
                'briefing.composed',
                $discriminator,
                'session briefing composed (' . $instructionsLength . ' chars)',
                ['instructionsLength' => $instructionsLength],
                $providerStartedAtUnixMs - $briefingStartedAtUnixMs,
                $providerStartedAtUnixMs
            ),
            $agentVoiceCallTraceEventsService->buildTraceEventForCall(
                $agentVoiceCall,
                $source,
                $providerRefusal === null ? 'provider.session_created' : 'provider.session_refused',
                $discriminator,
                $providerRefusal === null
                    ? 'provider session created'
                    : 'provider refused the offer: ' . $providerRefusal,
                ['voiceProvider' => $voiceProviderName, 'providerCallId' => $agentVoiceCall->providerCallId ?? null],
                $providerAnsweredAtUnixMs - $providerStartedAtUnixMs,
                $providerAnsweredAtUnixMs
            ),
        ];
        if ($providerRefusal === null) {
            $traceEvents[] = $agentVoiceCallTraceEventsService->buildTraceEventForCall(
                $agentVoiceCall,
                $source,
                'call.open_answered',
                $discriminator,
                'open answered after ' . ($openFinishedAtUnixMs - $openReceivedAtUnixMs) . ' ms',
                [
                    'beforeBriefingMs' => $briefingStartedAtUnixMs - $openReceivedAtUnixMs,
                    'briefingMs' => $providerStartedAtUnixMs - $briefingStartedAtUnixMs,
                    'providerMs' => $providerAnsweredAtUnixMs - $providerStartedAtUnixMs,
                    'afterProviderMs' => $openFinishedAtUnixMs - $providerAnsweredAtUnixMs,
                ],
                $openFinishedAtUnixMs - $openReceivedAtUnixMs,
                $openFinishedAtUnixMs
            );
        }
        $this->getConversationTraceEventsServiceOfVoiceRoutes()->recordEvents($traceEvents);
    }

    /**
     * The access gate of the voice routes beside the chat's ({@see self::authorizeConversationAccess()}), called
     * second by every routed method and both builders. Does nothing by default; an application that is stricter for
     * voice than for its chat throws a ForbiddenException here.
     *
     * @throws ForbiddenException
     */
    protected function authorizeVoiceSessionAccess(): void
    {
    }

    /**
     * The account a voice request acts for: the signed-in account. The one place every route passes before it reads a
     * call, so this is also where the routes refuse while the voice channel cannot run in the installation.
     *
     * @throws ForbiddenException `feature_not_available` while the voice channel cannot run here
     *     ({@see AgentVoiceCallAdmissionService::canVoiceRunHere()}), and without a signed-in account
     */
    protected function requireAccountOfVoiceRequest(): Account
    {
        if (!$this->getAgentVoiceCallAdmissionService()->canVoiceRunHere()) {
            throw new ForbiddenException(AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE);
        }
        $authAccount = AuthService::instance()->getAccount();
        if (!$authAccount instanceof Account || !isset($authAccount->id)) {
            throw new ForbiddenException(AgentVoiceCallRefusal::CODE_FEATURE_NOT_AVAILABLE);
        }

        return $authAccount;
    }

    /**
     * The call `$callId` when it is a call of the account of this request. The account is a filter of the read, so a
     * call of another account cannot be told from one that does not exist.
     *
     * @throws NotFoundException `call_not_found`
     * @throws ForbiddenException
     */
    protected function findOwnCall(int $callId): AgentVoiceCall
    {
        $agentVoiceCall = $this->getAgentVoiceCallStoreService()->findCallOfAccount($callId, $this->requireAccountOfVoiceRequest());
        if (!$agentVoiceCall instanceof AgentVoiceCall) {
            throw new NotFoundException('call_not_found');
        }

        return $agentVoiceCall;
    }

    /**
     * The conversation a request asks its call to continue, under the caller's read rights and the visibility of the
     * chat ({@see self::findVisibleConversation()}).
     *
     * @throws NotFoundException `conversation_not_found` for an unknown, foreign or hidden id
     */
    protected function findConversationOfVoiceRequest(int $conversationId): AIConversation
    {
        try {
            return $this->findVisibleConversation($this->getConversationsServiceOfVoiceRoutes(), $conversationId);
        } catch (NotFoundException) {
            throw new NotFoundException('conversation_not_found');
        }
    }

    /**
     * The conversation a call runs on, read by its id under the caller's read rights; null when the call has none or
     * it cannot be read (the briefing then shrinks, the call is not blocked).
     */
    protected function findConversationOfCall(AgentVoiceCall $agentVoiceCall): ?AIConversation
    {
        if ($agentVoiceCall->aiConversationId === null) {
            return null;
        }
        try {
            $aiConversation = $this->getConversationsServiceOfVoiceRoutes()->find((int)$agentVoiceCall->aiConversationId);
        } catch (NotFoundException) {
            return null;
        }

        return $aiConversation instanceof AIConversation ? $aiConversation : null;
    }

    /**
     * Asks the admission whether a call of `$account` may start and refuses with the admission's code when not
     * (`feature_not_available`, `no_ai_budget`, `daily_limit_reached`, or a code of the application).
     *
     * @throws ForbiddenException the code of the refusal
     */
    protected function throwForbiddenIfCallStartIsRefused(Account $account, ?AgentSpace $agentSpace, ?AIConversation $aiConversation): void
    {
        $agentVoiceCallRefusal = $this->getAgentVoiceCallAdmissionService()->findRefusalOfCallStart($account, $agentSpace, $aiConversation);
        if ($agentVoiceCallRefusal !== null) {
            throw new ForbiddenException($agentVoiceCallRefusal->code);
        }
    }

    /**
     * The id the client of this request states for itself, when its request carries one (`clientId`, the field the
     * chat's requests use). The request DTOs of this package carry none, so every client of the account counts as the
     * same; an application whose subclass of a request DTO declares the field gets the client that answered a ring
     * told apart from the others at `open`.
     */
    protected function findClientIdOfVoiceRequest(RequestDto $requestDto): ?string
    {
        $clientId = $requestDto->clientId ?? null;

        return is_string($clientId) && $clientId !== '' ? $clientId : null;
    }

    /**
     * The adapter of the provider the session of `$agentVoiceCall` is created on: the one pinned on the call, else the
     * configured default.
     *
     * @throws InternalErrorException when no adapter is registered under that name
     */
    protected function getVoiceProviderAdapterOfCall(AgentVoiceCall $agentVoiceCall): RealtimeVoiceProviderAdapter
    {
        return RealtimeVoiceProviderAdapter::forCall($agentVoiceCall);
    }

    /** A time of a call as the clients read it: UTC, `Y-m-d\TH:i:s\Z`. */
    protected function formatTimeOfVoiceCallInUtc(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    protected function getAgentVoiceCallsService(): AgentVoiceCallsService
    {
        /** @var AgentVoiceCallsService $agentVoiceCallsService */
        $agentVoiceCallsService = DDDService::instance()->getService(AgentVoiceCallsService::class);

        return $agentVoiceCallsService;
    }

    protected function getAgentVoiceCallStoreService(): AgentVoiceCallStoreService
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);

        return $agentVoiceCallStoreService;
    }

    protected function getAgentVoiceCallStartsService(): AgentVoiceCallStartsService
    {
        /** @var AgentVoiceCallStartsService $agentVoiceCallStartsService */
        $agentVoiceCallStartsService = DDDService::instance()->getService(AgentVoiceCallStartsService::class);

        return $agentVoiceCallStartsService;
    }

    protected function getAgentVoiceCallAdmissionService(): AgentVoiceCallAdmissionService
    {
        /** @var AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService */
        $agentVoiceCallAdmissionService = DDDService::instance()->getService(AgentVoiceCallAdmissionService::class);

        return $agentVoiceCallAdmissionService;
    }

    protected function getAgentVoiceRingService(): AgentVoiceRingService
    {
        /** @var AgentVoiceRingService $agentVoiceRingService */
        $agentVoiceRingService = DDDService::instance()->getService(AgentVoiceRingService::class);

        return $agentVoiceRingService;
    }

    protected function getAgentVoiceSessionContextsService(): AgentVoiceSessionContextsService
    {
        /** @var AgentVoiceSessionContextsService $agentVoiceSessionContextsService */
        $agentVoiceSessionContextsService = DDDService::instance()->getService(AgentVoiceSessionContextsService::class);

        return $agentVoiceSessionContextsService;
    }

    protected function getAgentVoiceCallTraceEventsService(): AgentVoiceCallTraceEventsService
    {
        /** @var AgentVoiceCallTraceEventsService $agentVoiceCallTraceEventsService */
        $agentVoiceCallTraceEventsService = DDDService::instance()->getService(AgentVoiceCallTraceEventsService::class);

        return $agentVoiceCallTraceEventsService;
    }

    protected function getAgentLiveVoiceSessionsService(): AgentLiveVoiceSessionsService
    {
        /** @var AgentLiveVoiceSessionsService $agentLiveVoiceSessionsService */
        $agentLiveVoiceSessionsService = DDDService::instance()->getService(AgentLiveVoiceSessionsService::class);

        return $agentLiveVoiceSessionsService;
    }

    /** The conversations service, for the conversation reads of the voice routes. */
    protected function getConversationsServiceOfVoiceRoutes(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);

        return $aIConversationsService;
    }

    /** The trace of a conversation, which the events of a call are written into. */
    protected function getConversationTraceEventsServiceOfVoiceRoutes(): AIConversationTraceEventsService
    {
        /** @var AIConversationTraceEventsService $aIConversationTraceEventsService */
        $aIConversationTraceEventsService = DDDService::instance()->getService(AIConversationTraceEventsService::class);

        return $aIConversationTraceEventsService;
    }
}
