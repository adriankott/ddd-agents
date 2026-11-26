<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationSubjectBindingsService;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationPurposeStartTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationReadsTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationStartTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationSubjectsTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationWritesTrait;
use DDD\Presentation\Api\Agents\Conversations\Controller\AgentSelectableModelsTrait;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsOfSubjectGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsOfSubjectGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartPurposePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartPurposePostResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostResponseDto;

/** A consuming application's controller that only mounts the traits. */
class AgentConversationTraitsFixtureController
{
    use AgentConversationReadsTrait, AgentConversationWritesTrait, AgentConversationStartTrait, AgentConversationPurposeStartTrait, AgentConversationSubjectsTrait, AgentSelectableModelsTrait;

    public function callHideAgentForensicsUnlessImpersonating(): void
    {
        $this->hideAgentForensicsUnlessImpersonating();
    }

    public function callHidePropertiesOnEveryObjectOfClass(string $moduleClassName, string ...$propertyNames): void
    {
        $this->hidePropertiesOnEveryObjectOfClass($moduleClassName, ...$propertyNames);
    }

    public function callAuthorizeConversationAccess(): void
    {
        $this->authorizeConversationAccess();
    }

    public function callFindAgentSpaceOfStartRequest(AIConversationStartWithMessagePostRequestDto $requestDto): ?AgentSpace
    {
        return $this->findAgentSpaceOfStartRequest($requestDto);
    }

    public function callBuildConversationStartedResponse(
        AIConversationStartWithMessagePostRequestDto $requestDto,
        ?AgentSpace $agentSpace,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationStartWithMessagePostResponseDto {
        return $this->buildConversationStartedResponse($requestDto, $agentSpace, $aiConversationAgentService);
    }

    public function callFindAgentSpaceOfPurposeStartRequest(AIConversationStartPurposePostRequestDto $requestDto): AgentSpace
    {
        return $this->findAgentSpaceOfPurposeStartRequest($requestDto);
    }

    /** @var string[]|null The subject classes a purposed start may name; null = the trait's default (none) */
    public ?array $allowedSubjectEntityClasses = null;

    protected function isSubjectEntityClassAllowedForPurposeStart(string $purposeName, string $subjectEntityClass): bool
    {
        return $this->allowedSubjectEntityClasses !== null && in_array($subjectEntityClass, $this->allowedSubjectEntityClasses, true);
    }

    /** @var string[]|null The subject classes a free conversation may name; null = the trait's default (none) */
    public ?array $allowedSubjectEntityClassesOfConversationStart = null;

    protected function isSubjectEntityClassAllowedForConversationStart(string $subjectEntityClass): bool
    {
        return $this->allowedSubjectEntityClassesOfConversationStart !== null
            && in_array($subjectEntityClass, $this->allowedSubjectEntityClassesOfConversationStart, true);
    }

    public function callBuildConversationsOfSubjectListResponse(
        AIConversationsOfSubjectGetRequestDto $requestDto,
        AIConversationsService $aiConversationsService,
        ?AIConversationSubjectBindingsService $aiConversationSubjectBindingsService = null
    ): AIConversationsOfSubjectGetResponseDto {
        return $this->buildConversationsOfSubjectListResponse(
            $requestDto,
            $aiConversationsService,
            $aiConversationSubjectBindingsService ?? new AIConversationSubjectBindingsService()
        );
    }

    public function callBuildPurposeStartedResponse(
        AIConversationStartPurposePostRequestDto $requestDto,
        AIConversationAgentService $aiConversationAgentService
    ): AIConversationStartPurposePostResponseDto {
        return $this->buildPurposeStartedResponse($requestDto, $aiConversationAgentService);
    }
}
