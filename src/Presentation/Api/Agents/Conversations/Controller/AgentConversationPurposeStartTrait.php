<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Controller;

use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartPurposePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartPurposePostResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The purposed start of the agent chat for an application's conversations controller (`POST …/startPurpose`, relative
 * to the controller's `#[Route]`): a trait of its own, so adding the route is the application's decision and an
 * update of the package never mounts it. An application that adds it lists the entity classes a client may name as
 * the subject ({@see AgentConversationControllerHooksTrait::isSubjectEntityClassAllowedForPurposeStart()}, default:
 * none) and, when it redeclares its routes to add a quota or an envelope attribute, redeclares this one as well
 * (the body is {@see buildPurposeStartedResponse()}).
 */
trait AgentConversationPurposeStartTrait
{
    use AgentConversationControllerHooksTrait;

    /** @var int The characters of a purposed start's prefill */
    public const int MAX_PURPOSE_PREFILL_CHARS = 4000;

    /**
     * Start a purposed conversation in a space, or reopen its open conversation: the purpose by name, optionally the
     * record it works on (one conversation per space, purpose and record) and a prefill for the kickoff of a new one.
     * The client then opens the answered conversation by its id.
     *
     * The same rules as the PHP starter ({@see AIConversationAgentService::startOrResumePurposedConversation()}, with a
     * subject {@see AIConversationAgentService::startOrResumePurposedConversationOfSubject()}, scope CUSTOMER): a registered purpose that may be started through the API, its required feature flags against the
     * space, its rail and seeded plan on the new conversation, a space the account may use. A subject the caller
     * cannot read answers 404, a malformed one 400.
     *
     * @param AIConversationStartPurposePostRequestDto $requestDto
     * @param AIConversationAgentService $aiConversationAgentService
     * @return AIConversationStartPurposePostResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\ForbiddenException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Post('/startPurpose')]
    #[Summary('AIConversation Start Purpose')]
    public function startPurpose(
        AIConversationStartPurposePostRequestDto &$requestDto,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationStartPurposePostResponseDto {
        $this->authorizeConversationAccess();
        $requestDto = $this->resolveRequestDtoOfRoutedMethod(__FUNCTION__, $requestDto);
        $aIUsageEnvelopeContext = $this->openConversationEnvelopeIfNoneIsOpen(AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN);
        try {
            return $this->buildPurposeStartedResponse($requestDto, $aiConversationAgentService);
        } finally {
            $this->closeConversationEnvelope($aIUsageEnvelopeContext);
        }
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\ForbiddenException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildPurposeStartedResponse(
        AIConversationStartPurposePostRequestDto &$requestDto,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationStartPurposePostResponseDto {
        $purposeName = trim($requestDto->purposeId);
        if ($purposeName === '') {
            throw new BadRequestException('purposeId names the purpose to start.');
        }
        $prefill = trim((string)($requestDto->prefill ?? ''));
        if (mb_strlen($prefill) > static::MAX_PURPOSE_PREFILL_CHARS) {
            throw new BadRequestException('prefill is at most ' . static::MAX_PURPOSE_PREFILL_CHARS . ' characters.');
        }
        $agentSpace = $this->findAgentSpaceOfPurposeStartRequest($requestDto);
        $aIConversationSubject = $requestDto->subject ?? null;
        if ($aIConversationSubject !== null) {
            // a class the application does not allow as a subject of this purpose answers like an unknown record
            if ($aIConversationSubject->findCapViolation() === null
                && !$this->isSubjectEntityClassAllowedForPurposeStart($purposeName, $aIConversationSubject->getNormalizedEntityClass())) {
                throw new NotFoundException('The subject of this purpose was not found.');
            }
            // 400 for a malformed subject, 404 for a record the caller cannot read (or that does not exist)
            $aIConversationSubject->findReadableSubjectEntity();
        }
        $agentPurposedConversationStart = $aIConversationSubject !== null
            ? $aiConversationAgentService->startOrResumePurposedConversationOfSubject(
                AIConversationDriveScope::CUSTOMER,
                $agentSpace,
                $purposeName,
                $aIConversationSubject,
                kickoffSignalAppendix: $prefill !== '' ? $prefill : null
            )
            : $aiConversationAgentService->startOrResumePurposedConversation(
                AIConversationDriveScope::CUSTOMER,
                $agentSpace,
                $purposeName,
                kickoffSignalAppendix: $prefill !== '' ? $prefill : null
            );
        $responseDto = new AIConversationStartPurposePostResponseDto();
        $responseDto->aiConversationId = (int)$agentPurposedConversationStart->conversation->id;
        $responseDto->resumedExistingConversation = $agentPurposedConversationStart->resumedExisting;
        $responseDto->prefillDelivered = $prefill !== '' && !$agentPurposedConversationStart->resumedExisting;
        return $responseDto;
    }
}
