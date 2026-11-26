<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Controller;

use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationBoundSubject;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationSubjectBindingsService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationBoundSubjectsGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsOfSubjectGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsOfSubjectGetResponseDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationSubjectGetResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;

/**
 * The read routes of conversations about one record, for an application's conversations controller (routes relative
 * to the controller's `#[Route]`): the caller's conversations of a record (`GET …/listConversationsOfSubject`), the
 * record one conversation is about (`GET …/{aiConversationId}/subject`) and the records of a page of the caller's
 * conversations (`GET …/listSubjectsOfConversations`). A trait of its own, so adding the routes is the application's
 * decision and an update of the package never mounts them.
 *
 * An application that adds it lists the entity classes a client may name
 * ({@see AgentConversationControllerHooksTrait::isSubjectEntityClassAllowedForConversationStart()}, default: none —
 * the same list a start with a subject is checked against, {@see AgentConversationStartTrait}), offers a describer
 * per class ({@see \DDD\Domain\AI\Services\Conversations\Subjects\AIConversationSubjectDescriber}) and, when it
 * redeclares its routes, redeclares these as well (the bodies are the protected `build…Response()` methods).
 */
trait AgentConversationSubjectsTrait
{
    use AgentConversationControllerHooksTrait;

    /**
     * Lists the caller's conversations about one record, visible to it: the free conversations started about the
     * record and the purposed ones started for it, of every space of the caller (query options of the conversations
     * list; a client orders it `orderBy=id desc` for newest first), and the record itself as the application's
     * describer names it now (`subject`: name and link). A malformed subject answers 400; a class the
     * application does not allow ({@see self::isSubjectEntityClassAllowedForConversationStart()}) and a record the
     * caller cannot read answer 404.
     *
     * @param AIConversationsOfSubjectGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @param AIConversationSubjectBindingsService $aiConversationSubjectBindingsService
     * @return AIConversationsOfSubjectGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Get('/listConversationsOfSubject')]
    #[Summary('AIConversations Of Subject')]
    public function listConversationsOfSubject(
        AIConversationsOfSubjectGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationSubjectBindingsService $aiConversationSubjectBindingsService
    ): AIConversationsOfSubjectGetResponseDto {
        $this->authorizeConversationAccess();
        $requestDto = $this->resolveRequestDtoOfRoutedMethod(__FUNCTION__, $requestDto);
        return $this->buildConversationsOfSubjectListResponse($requestDto, $aiConversationsService, $aiConversationSubjectBindingsService);
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildConversationsOfSubjectListResponse(
        AIConversationsOfSubjectGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationSubjectBindingsService $aiConversationSubjectBindingsService
    ): AIConversationsOfSubjectGetResponseDto {
        $aIConversationSubject = $requestDto->toSubject();
        $subjectEntity = $this->findReadableSubjectEntityOfConversationRequest($aIConversationSubject);
        /** @var AIConversations $aiConversationsClassName the repository reads the options of the application's class */
        $aiConversationsClassName = $this->getClassNameTheApplicationUsesFor(AIConversations::class);
        $aiConversationsClassName::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
        $aiConversationsService->throwErrors = true;
        $this->hideAgentForensicsUnlessImpersonating();
        $responseDto = new AIConversationsOfSubjectGetResponseDto();
        $responseDto->aiConversations = $aiConversationsService->findAllVisibleToCustomerOfSubject($aIConversationSubject);
        $responseDto->subject = AIConversationBoundSubject::ofSubject(
            $aIConversationSubject,
            $aiConversationSubjectBindingsService->describeSubject($aIConversationSubject, $subjectEntity)
        );
        return $responseDto;
    }

    /**
     * The records a page of the caller's conversations is bound to: the conversations are the ones
     * `GET …/listAllConversations` answers for the same query options (`filters`, `orderBy`, `top`, `skip`), so a
     * client that shows that list asks for its records with the parameters of its list. One entry per bound
     * conversation; a conversation without a record has none.
     *
     * @param AIConversationsGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @param AIConversationSubjectBindingsService $aiConversationSubjectBindingsService
     * @return AIConversationBoundSubjectsGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Get('/listSubjectsOfConversations')]
    #[Summary('AIConversations Subjects')]
    public function listSubjectsOfConversations(
        AIConversationsGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationSubjectBindingsService $aiConversationSubjectBindingsService
    ): AIConversationBoundSubjectsGetResponseDto {
        $this->authorizeConversationAccess();
        $requestDto = $this->resolveRequestDtoOfRoutedMethod(__FUNCTION__, $requestDto);
        return $this->buildSubjectsOfConversationsListResponse($requestDto, $aiConversationsService, $aiConversationSubjectBindingsService);
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildSubjectsOfConversationsListResponse(
        AIConversationsGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationSubjectBindingsService $aiConversationSubjectBindingsService
    ): AIConversationBoundSubjectsGetResponseDto {
        /** @var AIConversations $aiConversationsClassName the repository reads the options of the application's class */
        $aiConversationsClassName = $this->getClassNameTheApplicationUsesFor(AIConversations::class);
        $aiConversationsClassName::getDefaultQueryOptions()->setQueryOptionsFromRequestDto($requestDto);
        $aiConversationsService->throwErrors = true;
        $responseDto = new AIConversationBoundSubjectsGetResponseDto();
        // the conversations under the caller's rights first: a binding is read only for a conversation the caller may see
        $responseDto->subjects = $aiConversationSubjectBindingsService->findBoundSubjectsOfConversations($aiConversationsService->findAllVisibleToCustomer());
        return $responseDto;
    }

    /**
     * The record one conversation of the caller is about: its entity class and id, the purpose of a purposed
     * conversation, and the name and link the application's describer gave when the conversation was bound. Null for
     * a conversation without a subject. An unknown, foreign or hidden conversation answers 404.
     *
     * @param AIConversationGetRequestDto $requestDto
     * @param AIConversationsService $aiConversationsService
     * @param AIConversationSubjectBindingsService $aiConversationSubjectBindingsService
     * @return AIConversationSubjectGetResponseDto
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    #[Get('/{aiConversationId}/subject', requirements: ['aiConversationId' => '\d+'])]
    #[Summary('AIConversation Subject')]
    public function getAIConversationSubject(
        AIConversationGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationSubjectBindingsService $aiConversationSubjectBindingsService
    ): AIConversationSubjectGetResponseDto {
        $this->authorizeConversationAccess();
        $requestDto = $this->resolveRequestDtoOfRoutedMethod(__FUNCTION__, $requestDto);
        return $this->buildConversationSubjectResponse($requestDto, $aiConversationsService, $aiConversationSubjectBindingsService);
    }

    /**
     * @throws \DDD\Infrastructure\Exceptions\BadRequestException
     * @throws \DDD\Infrastructure\Exceptions\InternalErrorException
     * @throws \DDD\Infrastructure\Exceptions\NotFoundException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    protected function buildConversationSubjectResponse(
        AIConversationGetRequestDto &$requestDto,
        AIConversationsService $aiConversationsService,
        AIConversationSubjectBindingsService $aiConversationSubjectBindingsService
    ): AIConversationSubjectGetResponseDto {
        $aiConversationsService->throwErrors = true;
        // the conversation under the caller's rights first: its binding is read only for a conversation the caller may see
        $aiConversation = $this->findVisibleConversation($aiConversationsService, (int)$requestDto->aiConversationId);
        $responseDto = new AIConversationSubjectGetResponseDto();
        $aIConversationSubjectBinding = $aiConversationSubjectBindingsService->findBindingOfConversation((int)$aiConversation->id);
        if ($aIConversationSubjectBinding !== null) {
            $responseDto->subject = AIConversationBoundSubject::of($aIConversationSubjectBinding, $aiConversation->getAgentWorkingState()?->subjectDescription);
        }
        return $responseDto;
    }
}
