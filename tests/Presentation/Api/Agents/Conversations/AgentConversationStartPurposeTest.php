<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Purposes\AgentPurposedConversationStart;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartPurposePostRequestDto;
use DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes\AgentSpacesServiceWithStoredSpaces;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;

/**
 * The purposed start route `POST …/startPurpose` ({@see \DDD\Presentation\Api\Agents\Conversations\Controller\AgentConversationWritesTrait::startPurpose()}):
 * the space it runs in, the subject check before anything starts (400 malformed, 404 unreadable), the hand-over to
 * the PHP starter with scope CUSTOMER, and the answer the client opens.
 *
 * No database: the space service and the starter are doubles; the reuse by subject is proved against the database in
 * {@see \DDD\Tests\Agents\Integration\Conversations\AIConversationSubjectsDatabaseTest}.
 */
class AgentConversationStartPurposeTest extends IntegrationTestCase
{
    protected const int ACCOUNT_ID = 12;

    protected const int AGENT_SPACE_ID = 7;

    protected const string PURPOSE_NAME = 'Support.AnswerTicket';

    public function testAPurposeRunsInASpaceSoARequestWithoutOneIsRefused(): void
    {
        $this->replaceService(AgentSpacesService::class, new AgentSpacesServiceWithStoredSpaces());

        $this->expectException(BadRequestException::class);
        (new AgentConversationTraitsFixtureController())->callFindAgentSpaceOfPurposeStartRequest($this->buildRequestDto(null));
    }

    public function testASubjectTheCallerCannotReadAnswers404AndNothingStarts(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::never())->method('startOrResumePurposedConversationOfSubject');

        // the subject is a space the space service does not answer: unknown or not readable, one answer for both
        $requestDto = $this->buildRequestDto(self::AGENT_SPACE_ID, AIConversationSubject::of(AgentSpace::class, 999));

        $this->expectException(NotFoundException::class);
        (new AgentConversationTraitsFixtureController())->callBuildPurposeStartedResponse($requestDto, $aIConversationAgentService);
    }

    public function testASubjectClassTheApplicationDoesNotAllowAnswers404LikeAnUnknownRecord(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::never())->method('startOrResumePurposedConversationOfSubject');
        $fixtureController = new AgentConversationTraitsFixtureController();
        $fixtureController->onlyAllowedSubjectEntityClass = AIConversation::class;

        // a readable record of a class the application does not list for its purposes
        $requestDto = $this->buildRequestDto(self::AGENT_SPACE_ID, AIConversationSubject::of(AgentSpace::class, self::AGENT_SPACE_ID));

        $this->expectException(NotFoundException::class);
        $fixtureController->callBuildPurposeStartedResponse($requestDto, $aIConversationAgentService);
    }

    public function testAMalformedOrForeignSubjectClassIsRefusedWith400(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::never())->method('startOrResumePurposedConversationOfSubject');
        $fixtureController = new AgentConversationTraitsFixtureController();

        foreach (['not a class', self::class, 'App\\Domain\\Missing\\Thing'] as $subjectEntityClass) {
            try {
                $fixtureController->callBuildPurposeStartedResponse(
                    $this->buildRequestDto(self::AGENT_SPACE_ID, AIConversationSubject::of($subjectEntityClass, 5)),
                    $aIConversationAgentService
                );
                self::fail('a subject class "' . $subjectEntityClass . '" must be refused');
            } catch (BadRequestException $badRequestException) {
                self::assertStringContainsString('subjectEntityClass', $badRequestException->getMessage());
            }
        }
    }

    public function testAReadableSubjectIsHandedToTheStarterWithScopeCustomerAndTheAnswerNamesTheConversation(): void
    {
        $agentSpacesService = $this->buildSpacesServiceWithTheSpace();
        $this->replaceService(AgentSpacesService::class, $agentSpacesService);
        $startedConversation = new AIConversation();
        $startedConversation->id = 4711;
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::never())->method('startOrResumePurposedConversation');
        $aIConversationAgentService->expects(self::once())
            ->method('startOrResumePurposedConversationOfSubject')
            ->with(
                AIConversationDriveScope::CUSTOMER,
                $agentSpacesService->storedAgentSpacesById[self::AGENT_SPACE_ID],
                self::PURPOSE_NAME,
                self::callback(static fn (AIConversationSubject $subject): bool => $subject->describe() === AgentSpace::class . '#' . self::AGENT_SPACE_ID),
                self::anything(),
                self::anything(),
                'Ticket #13082'
            )
            ->willReturn(AgentPurposedConversationStart::started($startedConversation));

        $requestDto = $this->buildRequestDto(self::AGENT_SPACE_ID, AIConversationSubject::of('\\' . AgentSpace::class, self::AGENT_SPACE_ID), 'Ticket #13082');
        $responseDto = (new AgentConversationTraitsFixtureController())->callBuildPurposeStartedResponse($requestDto, $aIConversationAgentService);

        self::assertSame(4711, $responseDto->aiConversationId);
        self::assertFalse($responseDto->resumedExistingConversation);
        self::assertTrue($responseDto->prefillDelivered);
    }

    public function testAReopenedConversationSaysSoAndThePrefillWasNotDelivered(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $openConversation = new AIConversation();
        $openConversation->id = 4711;
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->method('startOrResumePurposedConversation')
            ->willReturn(AgentPurposedConversationStart::resumedExisting($openConversation));

        $responseDto = (new AgentConversationTraitsFixtureController())->callBuildPurposeStartedResponse(
            $this->buildRequestDto(self::AGENT_SPACE_ID, null, 'Ticket #13082'),
            $aIConversationAgentService
        );

        self::assertSame(4711, $responseDto->aiConversationId);
        self::assertTrue($responseDto->resumedExistingConversation);
        self::assertFalse($responseDto->prefillDelivered);
    }

    public function testAPrefillOverItsCapIsRefused(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::never())->method('startOrResumePurposedConversation');

        $this->expectException(BadRequestException::class);
        (new AgentConversationTraitsFixtureController())->callBuildPurposeStartedResponse(
            $this->buildRequestDto(self::AGENT_SPACE_ID, null, str_repeat('x', AgentConversationTraitsFixtureController::MAX_PURPOSE_PREFILL_CHARS + 1)),
            $aIConversationAgentService
        );
    }

    protected function buildSpacesServiceWithTheSpace(): AgentSpacesServiceWithStoredSpaces
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;
        $agentSpace->accountId = self::ACCOUNT_ID;
        $agentSpacesService = new AgentSpacesServiceWithStoredSpaces();
        $agentSpacesService->storedAgentSpacesById[self::AGENT_SPACE_ID] = $agentSpace;
        return $agentSpacesService;
    }

    protected function buildRequestDto(?int $agentSpaceId, ?AIConversationSubject $subject = null, ?string $prefill = null): AIConversationStartPurposePostRequestDto
    {
        /** @var AIConversationStartPurposePostRequestDto $requestDto */
        $requestDto = (new ReflectionClass(AIConversationStartPurposePostRequestDto::class))->newInstanceWithoutConstructor();
        $requestDto->purposeId = self::PURPOSE_NAME;
        $requestDto->agentSpaceId = $agentSpaceId;
        $requestDto->subject = $subject;
        $requestDto->prefill = $prefill;
        return $requestDto;
    }
}
