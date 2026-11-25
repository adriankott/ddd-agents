<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\AgentVoiceRingService;
use DDD\Domain\AI\Services\Voice\AgentVoiceSessionContextsService;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Presentation\Api\Agents\Voice\Controller\AgentVoiceSessionsTrait;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionRingRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionRingResponseDto;
use DDD\Presentation\Base\Dtos\RequestDto;

/**
 * An application's controller that mounts the voice routes: the two builders are reached through public methods, the
 * services the trait asks are the doubles the test puts into the public properties (the `get…Service()` override
 * points), and the two guards write their names into {@see $guardCalls} in the order they run. With
 * {@see $guardRefusingAccess} set, that guard throws a ForbiddenException with the message `guard_refused`.
 */
class AgentVoiceSessionsFixtureController
{
    use AgentVoiceSessionsTrait;

    public const string GUARD_CONVERSATION_ACCESS = 'authorizeConversationAccess';

    public const string GUARD_VOICE_SESSION_ACCESS = 'authorizeVoiceSessionAccess';

    public const string GUARD_REFUSAL_MESSAGE = 'guard_refused';

    /** @var string[] The guards in the order they ran */
    public array $guardCalls = [];

    /** @var string|null The guard that refuses (one of the GUARD_* constants); null = none */
    public ?string $guardRefusingAccess = null;

    public ?AgentVoiceCallsService $agentVoiceCallsService = null;

    public ?AgentVoiceCallStoreService $agentVoiceCallStoreService = null;

    public ?AgentVoiceCallStartsService $agentVoiceCallStartsService = null;

    public ?AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService = null;

    public ?AgentVoiceRingService $agentVoiceRingService = null;

    public ?AgentVoiceSessionContextsService $agentVoiceSessionContextsService = null;

    public ?AgentLiveVoiceSessionsService $agentLiveVoiceSessionsService = null;

    public ?AIConversationsService $aIConversationsService = null;

    public ?AIConversationTraceEventsService $aIConversationTraceEventsService = null;

    /** @var RealtimeVoiceProviderAdapter|null The adapter of every call; null = the package's resolution by name */
    public ?RealtimeVoiceProviderAdapter $realtimeVoiceProviderAdapter = null;

    /** @var string[] The provider names the adapter resolution was asked for (the name on the call, null = the default) */
    public array $providerNamesAskedFor = [];

    public function callBuildVoiceSessionOpenedResponse(AgentVoiceSessionOpenRequestDto $requestDto, ?AgentSpace $agentSpace): AgentVoiceSessionOpenResponseDto
    {
        return $this->buildVoiceSessionOpenedResponse($requestDto, $agentSpace);
    }

    public function callBuildVoiceCallRingingResponse(AgentVoiceSessionRingRequestDto $requestDto, ?AgentSpace $agentSpace): AgentVoiceSessionRingResponseDto
    {
        return $this->buildVoiceCallRingingResponse($requestDto, $agentSpace);
    }

    public function callBuildVoiceCallAnsweredResponse(AgentVoiceSessionAnswerRequestDto $requestDto): AgentVoiceSessionAnswerResponseDto
    {
        return $this->buildVoiceCallAnsweredResponse($requestDto);
    }

    public function callFindClientIdOfVoiceRequest(RequestDto $requestDto): ?string
    {
        return $this->findClientIdOfVoiceRequest($requestDto);
    }

    protected function authorizeConversationAccess(): void
    {
        $this->recordGuardCall(static::GUARD_CONVERSATION_ACCESS);
    }

    protected function authorizeVoiceSessionAccess(): void
    {
        $this->recordGuardCall(static::GUARD_VOICE_SESSION_ACCESS);
    }

    /** @throws ForbiddenException when `$guardName` is the refusing guard */
    protected function recordGuardCall(string $guardName): void
    {
        $this->guardCalls[] = $guardName;
        if ($this->guardRefusingAccess === $guardName) {
            throw new ForbiddenException(static::GUARD_REFUSAL_MESSAGE);
        }
    }

    /** @throws InternalErrorException */
    protected function getVoiceProviderAdapterOfCall(AgentVoiceCall $agentVoiceCall): RealtimeVoiceProviderAdapter
    {
        $this->providerNamesAskedFor[] = $agentVoiceCall->voiceProvider;
        if ($this->realtimeVoiceProviderAdapter !== null) {
            return $this->realtimeVoiceProviderAdapter;
        }

        return RealtimeVoiceProviderAdapter::forCall($agentVoiceCall);
    }

    protected function getAgentVoiceCallsService(): AgentVoiceCallsService
    {
        return $this->agentVoiceCallsService;
    }

    protected function getAgentVoiceCallStoreService(): AgentVoiceCallStoreService
    {
        return $this->agentVoiceCallStoreService;
    }

    protected function getAgentVoiceCallStartsService(): AgentVoiceCallStartsService
    {
        return $this->agentVoiceCallStartsService;
    }

    protected function getAgentVoiceCallAdmissionService(): AgentVoiceCallAdmissionService
    {
        return $this->agentVoiceCallAdmissionService;
    }

    protected function getAgentVoiceRingService(): AgentVoiceRingService
    {
        return $this->agentVoiceRingService;
    }

    protected function getAgentVoiceSessionContextsService(): AgentVoiceSessionContextsService
    {
        return $this->agentVoiceSessionContextsService;
    }

    protected function getAgentLiveVoiceSessionsService(): AgentLiveVoiceSessionsService
    {
        return $this->agentLiveVoiceSessionsService;
    }

    protected function getConversationsServiceOfVoiceRoutes(): AIConversationsService
    {
        return $this->aIConversationsService;
    }

    protected function getConversationTraceEventsServiceOfVoiceRoutes(): AIConversationTraceEventsService
    {
        return $this->aIConversationTraceEventsService;
    }
}
