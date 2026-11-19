<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AgentClientContext;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationInjectSignalPostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationPostMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationsGetRequestDto;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * The routes hand the client context of a start, a posted message and an injected signal to the services; a routed
 * method reads its request into the application's DTO class without a redeclared route; an injected signal finds its
 * conversation through its own hook; a start stores request snapshots when the hook says so.
 */
class AgentConversationClientContextAndHooksTest extends IntegrationTestCase
{
    /** driveConversation()'s positional index of `runSettings` and `clientContext` */
    protected const int DRIVE_ARGUMENT_RUN_SETTINGS = 15;
    protected const int DRIVE_ARGUMENT_CLIENT_CONTEXT = 24;

    public function testAStartHandsItsClientContextAndTheSnapshotSwitchToTheDrive(): void
    {
        $clientContext = $this->buildClientContext();
        $requestDto = $this->buildDto(AIConversationStartWithMessagePostRequestDto::class);
        $requestDto->clientContext = $clientContext;
        $requestDto->storeRequestSnapshot = true;
        $driveArguments = [];
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::once())->method('driveConversation')->willReturnCallback(
            static function (mixed ...$arguments) use (&$driveArguments): AIConversation {
                $driveArguments = $arguments;
                return new AIConversation();
            }
        );

        (new AgentConversationClientContextFixtureController())->callBuildConversationStartedResponse($requestDto, null, $aIConversationAgentService);

        self::assertSame($clientContext, $driveArguments[self::DRIVE_ARGUMENT_CLIENT_CONTEXT]);
        self::assertInstanceOf(RunSettings::class, $driveArguments[self::DRIVE_ARGUMENT_RUN_SETTINGS]);
        self::assertTrue($driveArguments[self::DRIVE_ARGUMENT_RUN_SETTINGS]->storeRequestSnapshot);
    }

    public function testTheSnapshotSwitchOfAStartIsHonouredOnlyInAnAdminSession(): void
    {
        $requestDto = $this->buildDto(AIConversationStartWithMessagePostRequestDto::class);
        $requestDto->storeRequestSnapshot = true;
        $fixtureController = new AgentConversationClientContextFixtureController();

        self::assertTrue($fixtureController->callIsRequestSnapshotStoredForStartRequest($requestDto));
        $fixtureController->adminSession = false;
        self::assertFalse($fixtureController->callIsRequestSnapshotStoredForStartRequest($requestDto), 'a customer\'s value is ignored');
        $requestDto->storeRequestSnapshot = null;
        $fixtureController->adminSession = true;
        self::assertFalse($fixtureController->callIsRequestSnapshotStoredForStartRequest($requestDto));
    }

    public function testAPostedMessageHandsItsClientContextToTheDrive(): void
    {
        $clientContext = $this->buildClientContext();
        $requestDto = $this->buildDto(AIConversationPostMessagePostRequestDto::class);
        $requestDto->aiConversationId = 5;
        $requestDto->aiConversationMessage = new AIConversationMessage();
        $requestDto->aiConversationMessage->message = 'Which ticket is this?';
        $requestDto->clientContext = $clientContext;
        $driveArguments = [];
        $aIConversationAgentService = $this->createMock(AIConversationAgentService::class);
        $aIConversationAgentService->expects(self::once())->method('driveConversation')->willReturnCallback(
            static function (mixed ...$arguments) use (&$driveArguments): AIConversation {
                $driveArguments = $arguments;
                return new AIConversation();
            }
        );

        (new AgentConversationClientContextFixtureController())->callBuildMessagePostedResponse($requestDto, $aIConversationAgentService);

        self::assertSame($clientContext, $driveArguments[self::DRIVE_ARGUMENT_CLIENT_CONTEXT]);
        self::assertSame(5, $driveArguments[1]);
    }

    public function testAnInjectedSignalFindsItsConversationThroughItsHookAndCarriesTheClientContext(): void
    {
        $clientContext = $this->buildClientContext();
        $conversation = new AIConversation();
        $conversation->id = 8;
        $requestDto = $this->buildDto(AIConversationInjectSignalPostRequestDto::class);
        $requestDto->aiConversationId = 8;
        $requestDto->signalText = 'Opened the ticket list';
        $requestDto->signalSource = AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK;
        $requestDto->clientContext = $clientContext;
        $signalArguments = [];
        $aIConversationTurnSignalsService = $this->createMock(AIConversationTurnSignalsService::class);
        $aIConversationTurnSignalsService->expects(self::once())->method('injectSignal')->willReturnCallback(
            static function (mixed ...$arguments) use (&$signalArguments): AIConversationMessage {
                $signalArguments = $arguments;
                return new AIConversationMessage();
            }
        );
        $aIConversationsService = $this->createMock(AIConversationsService::class);
        $aIConversationsService->expects(self::never())->method('findVisibleToCustomer');
        $fixtureController = new AgentConversationClientContextFixtureController();
        $fixtureController->conversationOfInjectedSignal = $conversation;

        $responseDto = $fixtureController->callBuildSignalInjectedResponse($requestDto, $aIConversationTurnSignalsService, $aIConversationsService);

        self::assertSame($conversation, $responseDto->aiConversation);
        self::assertSame([8], $fixtureController->conversationIdsAskedForInjectedSignals);
        self::assertSame($conversation, $signalArguments[0]);
        self::assertSame($clientContext, $signalArguments[5]);
    }

    public function testARoutedMethodReadsItsRequestIntoTheApplicationsDtoClass(): void
    {
        $request = Request::create('/conversations/5/postMessage', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string)json_encode([
            'aiConversationMessage' => ['message' => 'hello'],
            'pageTitle' => 'Ticket 42',
            'clientContext' => ['routePath' => '/tickets/42', 'routeParameters' => [['name' => 'ticketId', 'value' => '42']], 'tab' => 'details'],
        ]));
        $request->attributes->set('aiConversationId', '5');
        $requestStack = DDDService::instance()->getRequestService()->getRequestStack();
        $requestStack->push($request);
        try {
            $moduleRequestDto = $this->buildDto(AIConversationPostMessagePostRequestDto::class);
            $fixtureController = new AgentConversationClientContextFixtureController();
            self::assertSame($moduleRequestDto, $fixtureController->callResolveRequestDtoOfRoutedMethod('postMessage', $moduleRequestDto), 'no class of its own: the request as resolved');

            $fixtureController->requestDtoClassesByRoutedMethod['postMessage'] = ApplicationPostMessageRequestDtoDouble::class;
            $applicationRequestDto = $fixtureController->callResolveRequestDtoOfRoutedMethod('postMessage', $moduleRequestDto);

            self::assertInstanceOf(ApplicationPostMessageRequestDtoDouble::class, $applicationRequestDto);
            self::assertSame('Ticket 42', $applicationRequestDto->pageTitle);
            self::assertSame(5, $applicationRequestDto->aiConversationId);
            self::assertSame('hello', $applicationRequestDto->aiConversationMessage->message);
            self::assertSame('ticketId=42', $applicationRequestDto->clientContext?->routeParameters?->toQueryText());

            $fixtureController->requestDtoClassesByRoutedMethod['postMessage'] = AIConversationsGetRequestDto::class;
            $this->expectException(InternalErrorException::class);
            $fixtureController->callResolveRequestDtoOfRoutedMethod('postMessage', $moduleRequestDto);
        } finally {
            $requestStack->pop();
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $dtoClassName
     * @return T
     */
    protected function buildDto(string $dtoClassName): object
    {
        return (new ReflectionClass($dtoClassName))->newInstanceWithoutConstructor();
    }

    protected function buildClientContext(): AgentClientContext
    {
        $agentClientContext = new AgentClientContext();
        $agentClientContext->routePath = '/tickets/42';
        $agentClientContext->recordKind = 'ticket';
        $agentClientContext->recordId = '42';
        return $agentClientContext;
    }
}
