<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\EntryPoints\AIConversationEntryPointStart;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectDescription;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationSubjectBindingsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsOfSubjectGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes\AgentSpacesServiceWithStoredSpaces;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Subjects\AIConversationSubjectDescriberDouble;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The subject of a FREE conversation: a start that names a record hands it to the drive (which binds the new
 * conversation before its first turn), the check before anything is stored (400 malformed, 404 a class the
 * application does not allow or a record the caller cannot read), and the list of a record's conversations under the
 * same check.
 *
 * No database: the drive and the conversations service are doubles; the binding and the list are proved against the
 * database in {@see \DDD\Tests\Agents\Integration\Conversations\AIConversationFreeSubjectsDatabaseTest}.
 */
class AgentConversationSubjectOfStartTest extends IntegrationTestCase
{
    protected const int ACCOUNT_ID = 12;

    protected const int AGENT_SPACE_ID = 7;

    public function testAStartWithoutASubjectDrivesWithoutOne(): void
    {
        $driveArguments = $this->startAndCaptureDriveArguments($this->buildStartRequestDto(null), new AgentConversationClientContextFixtureController());

        self::assertNull($driveArguments[$this->getDriveArgumentIndexOfTheSubject()] ?? null);
    }

    public function testByDefaultNoSubjectClassIsAllowedSoAStartThatNamesOneAnswers404AndNothingIsDriven(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::never())->method('driveConversation');
        $aIConversationAgentService->expects(self::never())->method('startOrReopenConversationAtEntryPoint');

        $this->expectException(NotFoundException::class);
        (new AgentConversationClientContextFixtureController())->callBuildConversationStartedResponse(
            $this->buildStartRequestDto(AIConversationSubject::of(AgentSpace::class, self::AGENT_SPACE_ID)),
            null,
            $aIConversationAgentService
        );
    }

    public function testARecordTheCallerCannotReadAnswers404AndNothingIsDriven(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::never())->method('driveConversation');
        $fixtureController = new AgentConversationClientContextFixtureController();
        $fixtureController->allowedSubjectEntityClassesOfConversationStart = [AgentSpace::class];

        $this->expectException(NotFoundException::class);
        // a space the space service does not answer: unknown or not readable, one answer for both
        $fixtureController->callBuildConversationStartedResponse($this->buildStartRequestDto(AIConversationSubject::of(AgentSpace::class, 999)), null, $aIConversationAgentService);
    }

    public function testAMalformedSubjectIsRefusedWith400(): void
    {
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::never())->method('driveConversation');
        $fixtureController = new AgentConversationClientContextFixtureController();
        $fixtureController->allowedSubjectEntityClassesOfConversationStart = [AgentSpace::class];

        foreach ([AIConversationSubject::of('not a class', 5), AIConversationSubject::of(self::class, 5), AIConversationSubject::of(AgentSpace::class, 0)] as $subject) {
            try {
                $fixtureController->callBuildConversationStartedResponse($this->buildStartRequestDto($subject), null, $aIConversationAgentService);
                self::fail('the subject ' . $subject->describe() . ' must be refused');
            } catch (BadRequestException $badRequestException) {
                self::assertStringContainsString('subjectEntity', $badRequestException->getMessage());
            }
        }
    }

    public function testAReadableRecordOfAnAllowedClassIsHandedToTheDrive(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $fixtureController = new AgentConversationClientContextFixtureController();
        $fixtureController->allowedSubjectEntityClassesOfConversationStart = [AgentSpace::class];
        $subject = AIConversationSubject::of('\\' . AgentSpace::class, self::AGENT_SPACE_ID);

        $driveArguments = $this->startAndCaptureDriveArguments($this->buildStartRequestDto($subject), $fixtureController);

        self::assertSame($subject, $driveArguments[$this->getDriveArgumentIndexOfTheSubject()]);
        self::assertSame(AgentSpace::class . '#' . self::AGENT_SPACE_ID, $subject->describe());
    }

    public function testAStartFromAnEntryPointHandsTheSubjectToTheEntryPointStart(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $fixtureController = new AgentConversationClientContextFixtureController();
        $fixtureController->allowedSubjectEntityClassesOfConversationStart = [AgentSpace::class];
        $subject = AIConversationSubject::of(AgentSpace::class, self::AGENT_SPACE_ID);
        $requestDto = $this->buildStartRequestDto($subject);
        $requestDto->entryPointKey = 'record.page.bar';
        $entryPointArguments = [];
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::never())->method('driveConversation');
        $aIConversationAgentService->expects(self::once())->method('startOrReopenConversationAtEntryPoint')->willReturnCallback(
            static function (mixed ...$arguments) use (&$entryPointArguments): AIConversationEntryPointStart {
                $entryPointArguments = $arguments;
                return AIConversationEntryPointStart::started(new AIConversation());
            }
        );

        $fixtureController->callBuildConversationStartedResponse($requestDto, null, $aIConversationAgentService);

        $subjectParameterPosition = null;
        foreach ((new ReflectionMethod(AIConversationAgentService::class, 'startOrReopenConversationAtEntryPoint'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'aIConversationSubject') {
                $subjectParameterPosition = $parameter->getPosition();
            }
        }
        self::assertNotNull($subjectParameterPosition);
        self::assertSame($subject, $entryPointArguments[$subjectParameterPosition]);
    }

    public function testTheListOfARecordsConversationsAnswers404ForAClassThatIsNotAllowedAndForAnUnreadableRecord(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $aIConversationsService = $this->createMock(AIConversationsService::class);
        $aIConversationsService->expects(self::never())->method('findAllVisibleToCustomerOfSubject');
        $fixtureController = new AgentConversationClientContextFixtureController();

        try {
            $fixtureController->callBuildConversationsOfSubjectListResponse($this->buildListRequestDto(AgentSpace::class, self::AGENT_SPACE_ID), $aIConversationsService);
            self::fail('a class the application has not listed must answer 404');
        } catch (NotFoundException) {
        }
        $fixtureController->allowedSubjectEntityClassesOfConversationStart = [AgentSpace::class];
        $this->expectException(NotFoundException::class);
        $fixtureController->callBuildConversationsOfSubjectListResponse($this->buildListRequestDto(AgentSpace::class, 999), $aIConversationsService);
    }

    public function testTheListOfARecordsConversationsReadsThemForTheCheckedRecord(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $conversationsOfTheRecord = new AIConversations();
        $aIConversationsService = $this->createMock(AIConversationsService::class);
        $aIConversationsService->expects(self::once())
            ->method('findAllVisibleToCustomerOfSubject')
            ->with(self::callback(static fn (AIConversationSubject $subject): bool => $subject->describe() === AgentSpace::class . '#' . self::AGENT_SPACE_ID))
            ->willReturn($conversationsOfTheRecord);
        $fixtureController = new AgentConversationClientContextFixtureController();
        $fixtureController->allowedSubjectEntityClassesOfConversationStart = [AgentSpace::class];

        $bindingsService = new AIConversationSubjectBindingsService();
        $bindingsService->setAIConversationSubjectDescribers([
            new AIConversationSubjectDescriberDouble([AgentSpace::class], AIConversationSubjectDescription::of('a team space', 'Sales', '/spaces/7')),
        ]);

        $responseDto = $fixtureController->callBuildConversationsOfSubjectListResponse(
            $this->buildListRequestDto('\\' . AgentSpace::class, self::AGENT_SPACE_ID),
            $aIConversationsService,
            $bindingsService
        );

        self::assertSame($conversationsOfTheRecord, $responseDto->aiConversations);
        // the record itself, as the describer names it now: what a client shows before a conversation exists
        self::assertSame(AgentSpace::class, $responseDto->subject?->subjectEntityClass);
        self::assertSame(self::AGENT_SPACE_ID, $responseDto->subject?->subjectEntityId);
        self::assertSame('Sales', $responseDto->subject?->name);
        self::assertSame('/spaces/7', $responseDto->subject?->link);
        self::assertNull($responseDto->subject?->purposeName);
    }

    public function testTheListNamesTheRecordWithoutNameAndLinkWhenNoDescriberAnswers(): void
    {
        $this->replaceService(AgentSpacesService::class, $this->buildSpacesServiceWithTheSpace());
        $aIConversationsService = $this->createMock(AIConversationsService::class);
        $aIConversationsService->method('findAllVisibleToCustomerOfSubject')->willReturn(new AIConversations());
        $fixtureController = new AgentConversationClientContextFixtureController();
        $fixtureController->allowedSubjectEntityClassesOfConversationStart = [AgentSpace::class];

        $responseDto = $fixtureController->callBuildConversationsOfSubjectListResponse(
            $this->buildListRequestDto(AgentSpace::class, self::AGENT_SPACE_ID),
            $aIConversationsService,
            new AIConversationSubjectBindingsService()
        );

        self::assertSame(AgentSpace::class, $responseDto->subject?->subjectEntityClass);
        self::assertNull($responseDto->subject?->name);
        self::assertNull($responseDto->subject?->link);
    }

    /** @return array<int, mixed> the positional arguments the start handed to driveConversation() */
    protected function startAndCaptureDriveArguments(AIConversationStartWithMessagePostRequestDto $requestDto, AgentConversationTraitsFixtureController $fixtureController): array
    {
        $driveArguments = [];
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::once())->method('driveConversation')->willReturnCallback(
            static function (mixed ...$arguments) use (&$driveArguments): AIConversation {
                $driveArguments = $arguments;
                return new AIConversation();
            }
        );
        $fixtureController->callBuildConversationStartedResponse($requestDto, null, $aIConversationAgentService);
        return $driveArguments;
    }

    /** The positional index of driveConversation()'s subject (a mock receives named arguments by position). */
    protected function getDriveArgumentIndexOfTheSubject(): int
    {
        foreach ((new ReflectionMethod(AIConversationAgentService::class, 'driveConversation'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'aIConversationSubject') {
                return $parameter->getPosition();
            }
        }
        self::fail('driveConversation() takes the subject of a new conversation');
    }

    protected function buildStartRequestDto(?AIConversationSubject $subject): AIConversationStartWithMessagePostRequestDto
    {
        /** @var AIConversationStartWithMessagePostRequestDto $requestDto */
        $requestDto = (new ReflectionClass(AIConversationStartWithMessagePostRequestDto::class))->newInstanceWithoutConstructor();
        $requestDto->aiConversationMessage = new AIConversationMessage();
        $requestDto->aiConversationMessage->message = 'How is this record doing?';
        $requestDto->subject = $subject;
        return $requestDto;
    }

    protected function buildListRequestDto(string $subjectEntityClass, int $subjectEntityId): AIConversationsOfSubjectGetRequestDto
    {
        /** @var AIConversationsOfSubjectGetRequestDto $requestDto */
        $requestDto = (new ReflectionClass(AIConversationsOfSubjectGetRequestDto::class))->newInstanceWithoutConstructor();
        $requestDto->subjectEntityClass = $subjectEntityClass;
        $requestDto->subjectEntityId = $subjectEntityId;
        return $requestDto;
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
}
