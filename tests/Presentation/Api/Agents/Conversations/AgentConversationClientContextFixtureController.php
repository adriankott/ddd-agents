<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationInjectSignalPostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationPostMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Presentation\Base\Dtos\RequestDto;

/**
 * A consuming application's controller for the client context, the request DTO of a routed method, the conversation
 * of an injected signal and the request snapshot of a start: every hook observable or set by the test. It runs as an
 * admin session unless told otherwise, so its responses leave the process-wide hides as they are.
 */
class AgentConversationClientContextFixtureController extends AgentConversationTraitsFixtureController
{
    public bool $adminSession = true;

    /** @var array<string, class-string<RequestDto>> the application's request DTO class by routed method name */
    public array $requestDtoClassesByRoutedMethod = [];

    /** The conversation the injected-signal hook answers with */
    public ?AIConversation $conversationOfInjectedSignal = null;

    /** @var int[] the conversation ids the injected-signal hook was asked for */
    public array $conversationIdsAskedForInjectedSignals = [];

    protected function isAdminSession(): bool
    {
        return $this->adminSession;
    }

    protected function afterMessagePosted(AIConversation $conversation): void
    {
    }

    protected function getRequestDtoClassOfRoutedMethod(string $routedMethodName, string $moduleRequestDtoClassName): string
    {
        return $this->requestDtoClassesByRoutedMethod[$routedMethodName] ?? parent::getRequestDtoClassOfRoutedMethod($routedMethodName, $moduleRequestDtoClassName);
    }

    protected function findConversationOfInjectedSignal(AIConversationInjectSignalPostRequestDto $requestDto, AIConversationsService $aIConversationsService): AIConversation
    {
        $this->conversationIdsAskedForInjectedSignals[] = (int)$requestDto->aiConversationId;
        return $this->conversationOfInjectedSignal ?? parent::findConversationOfInjectedSignal($requestDto, $aIConversationsService);
    }

    public function callBuildMessagePostedResponse(AIConversationPostMessagePostRequestDto $requestDto, AIConversationAgentService $aiConversationAgentService): AIConversationGetResponseDto
    {
        return $this->buildMessagePostedResponse($requestDto, $aiConversationAgentService);
    }

    public function callBuildSignalInjectedResponse(
        AIConversationInjectSignalPostRequestDto $requestDto,
        AIConversationTurnSignalsService $aIConversationTurnSignalsService,
        AIConversationsService $aiConversationsService
    ): AIConversationGetResponseDto {
        return $this->buildSignalInjectedResponse($requestDto, $aIConversationTurnSignalsService, $aiConversationsService);
    }

    public function callResolveRequestDtoOfRoutedMethod(string $routedMethodName, RequestDto $requestDto): RequestDto
    {
        return $this->resolveRequestDtoOfRoutedMethod($routedMethodName, $requestDto);
    }

    public function callIsRequestSnapshotStoredForStartRequest(AIConversationStartWithMessagePostRequestDto $requestDto): bool
    {
        return $this->isRequestSnapshotStoredForStartRequest($requestDto);
    }
}
