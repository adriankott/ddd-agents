<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolAnswer;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\ClientTools\AgentScreenPicture;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\ToolPictureFixtures;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\SharedCacheWithoutAtomicCounterDouble;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `Agent.UI` without tables: the request goes to the client a fresh presence names, the client receives it from the
 * mailbox with its run-state read and answers once into the shared cache (the first answer wins), the waiting tool
 * reads it. No presence = no client, a hidden client = background, a switched-off type = not enabled. A picture is
 * checked as untrusted bytes.
 */
class AgentClientToolRequestsWithoutTablesTest extends UnitTestCase
{
    protected const int CONVERSATION_ID = 70;

    protected const int ACCOUNT_ID = 3;

    protected const string CLIENT_ID = 'client-aaaa-1111';

    public function testTheClientReceivesTheRequestFromTheMailboxAndItsAnswerReachesTheTool(): void
    {
        $requestsService = new SimulatedClientRequestsServiceDouble();
        $requestsService->presenceService->recordClientStatement(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID, null);
        $requestsService->simulatedClient = static function (SimulatedClientRequestsServiceDouble $requestsService): void {
            $delivery = $requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID);
            if ($delivery === null) {
                return;
            }
            $requestsService->answerRequest(self::CONVERSATION_ID, $delivery->id, self::CLIENT_ID, 'OK', '# Orders', '{"visible":true,"nested":{"x":1}}');
        };

        $answer = $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, 'call-1', AgentClientToolRequest::TYPE_READ_SCREEN, [], 3);

        self::assertNull($requestsService->clientFailure);
        self::assertSame(AgentClientToolAnswer::KIND_ANSWERED, $answer->kind);
        self::assertSame('# Orders', $answer->text);
        self::assertSame('{"visible":true}', $answer->detailsJson, 'only flat details');
        self::assertGreaterThanOrEqual(AgentClientToolRequest::MIN_REQUEST_ID, $answer->requestId);
        self::assertNull($requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID), 'the request left the mailbox');
        foreach (array_keys($requestsService->sharedCache->valuesByKey) as $cacheKey) {
            self::assertStringStartsNotWith(AgentClientToolRequestsService::ANSWER_KEY_PREFIX . $answer->requestId, $cacheKey, 'the answer is forgotten once read');
        }
    }

    /**
     * The delivery to a real client goes through {@see AgentClientToolRequestsService::findDeliveryForClient()}, over a
     * cache that answers a miss with `false` (Core's semantics): an unanswered request is delivered, an answered one
     * is not.
     */
    public function testAnUnansweredRequestIsDeliveredAndAnAnsweredOneIsNotOverACacheThatAnswersAMissWithFalse(): void
    {
        $requestsService = new SimulatedClientRequestsServiceDouble();
        self::assertFalse($requestsService->sharedCache->get('a-key-never-written'), 'the cache answers a miss with false, like Core');
        $requestsService->presenceService->recordClientStatement(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID, null);
        $deliveriesSeen = [];
        $requestsService->simulatedClient = static function (SimulatedClientRequestsServiceDouble $requestsService) use (&$deliveriesSeen): void {
            $delivery = $requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID);
            $deliveriesSeen[] = $delivery === null ? 'none' : 'delivered';
            if ($delivery === null) {
                return;
            }
            $requestsService->answerRequest(self::CONVERSATION_ID, $delivery->id, self::CLIENT_ID, 'OK', 'answered', null);
            $deliveriesSeen[] = $requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID) === null ? 'none' : 'delivered';
        };

        $answer = $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, AgentClientToolRequest::TYPE_READ_SCREEN, [], 3);

        self::assertNull($requestsService->clientFailure);
        self::assertSame(['delivered', 'none'], $deliveriesSeen, 'the unanswered request is delivered, the answered one no more');
        self::assertSame('answered', $answer->text);
    }

    public function testWithoutAnAtomicCounterTheFirstAnswerStillWinsOverACacheThatAnswersAMissWithFalse(): void
    {
        $requestsService = new SimulatedClientRequestsServiceDouble(new SharedCacheWithoutAtomicCounterDouble());
        $requestsService->presenceService->recordClientStatement(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID, null);
        $secondAnswerFailures = [];
        $requestsService->simulatedClient = static function (SimulatedClientRequestsServiceDouble $requestsService) use (&$secondAnswerFailures): void {
            $delivery = $requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID);
            if ($delivery === null) {
                return;
            }
            $requestsService->answerRequest(self::CONVERSATION_ID, $delivery->id, self::CLIENT_ID, 'OK', 'first', null);
            try {
                $requestsService->answerRequest(self::CONVERSATION_ID, $delivery->id, self::CLIENT_ID, 'OK', 'second', null);
            } catch (ConflictException) {
                $secondAnswerFailures[] = 'conflict';
            }
        };

        $answer = $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, AgentClientToolRequest::TYPE_LIST_TARGETS, [], 3);

        self::assertNull($requestsService->clientFailure);
        self::assertSame('first', $answer->text, 'the first answer is let in (a miss is false, not null)');
        self::assertSame(['conflict'], $secondAnswerFailures);
    }

    public function testTheFirstAnswerWinsAndAWrongClientOrConversationIsNotFound(): void
    {
        $requestsService = new SimulatedClientRequestsServiceDouble();
        $requestsService->presenceService->recordClientStatement(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID, null);
        $secondAnswerFailures = [];
        $requestsService->simulatedClient = static function (SimulatedClientRequestsServiceDouble $requestsService) use (&$secondAnswerFailures): void {
            $delivery = $requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID);
            if ($delivery === null) {
                return;
            }
            foreach ([[self::CONVERSATION_ID, 'client-bbbb-2222'], [self::CONVERSATION_ID + 1, self::CLIENT_ID]] as [$conversationId, $clientId]) {
                try {
                    $requestsService->answerRequest($conversationId, $delivery->id, $clientId, 'OK', 'not mine', null);
                } catch (NotFoundException $notFound) {
                    $secondAnswerFailures[] = 'not found';
                }
            }
            $requestsService->answerRequest(self::CONVERSATION_ID, $delivery->id, self::CLIENT_ID, 'OK', 'first', null);
            try {
                $requestsService->answerRequest(self::CONVERSATION_ID, $delivery->id, self::CLIENT_ID, 'OK', 'second', null);
            } catch (ConflictException) {
                $secondAnswerFailures[] = 'conflict';
            }
        };

        $answer = $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, AgentClientToolRequest::TYPE_LIST_TARGETS, [], 3);

        self::assertSame('first', $answer->text);
        self::assertSame(['not found', 'not found', 'conflict'], $secondAnswerFailures);
    }

    public function testWithoutAFreshPresenceNoClientAndAHiddenClientIsInTheBackground(): void
    {
        $requestsService = new SimulatedClientRequestsServiceDouble();
        self::assertSame(AgentClientToolAnswer::KIND_NO_CLIENT, $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, AgentClientToolRequest::TYPE_READ_SCREEN, [], 3)->kind);
        self::assertSame(0, $requestsService->pauses, 'no client: no wait');

        $hiddenState = new AgentUIState();
        $hiddenState->visible = false;
        $hiddenState->routePath = '/orders';
        $requestsService->presenceService->recordClientStatement(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID, $hiddenState);
        self::assertSame(AgentClientToolAnswer::KIND_BACKGROUND, $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, AgentClientToolRequest::TYPE_READ_SCREEN, [], 3)->kind);
    }

    public function testASwitchedOffTypeIsNotEnabledAndAClientThatDoesNotAnswerTimesOut(): void
    {
        $requestsService = new SimulatedClientRequestsServiceDouble();
        $requestsService->presenceService->recordClientStatement(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID, null);

        self::assertSame(AgentClientToolAnswer::KIND_NOT_ENABLED, $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, AgentClientToolRequest::TYPE_SET_WINDOW_MODE, ['mode' => 'aside'], 3)->kind);
        self::assertSame(AgentClientToolAnswer::KIND_NOT_ENABLED, $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, 'UNKNOWN_TYPE', [], 3)->kind);

        $noAnswer = $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, AgentClientToolRequest::TYPE_HIGHLIGHT, ['references' => ['f.a']], 1);
        self::assertSame(AgentClientToolAnswer::KIND_NO_ANSWER, $noAnswer->kind);
        self::assertGreaterThan(0, $requestsService->pauses);
        self::assertNull($requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID), 'cleared after the wait');
    }

    public function testAnAccountWithoutTheRightToUseTheUIMayNotAnswer(): void
    {
        $requestsService = new SimulatedClientRequestsServiceDouble();
        $requestsService->presenceService->recordClientStatement(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID, null);
        $requestsService->mayUseUI = false;
        $requestsService->simulatedClient = static function (SimulatedClientRequestsServiceDouble $requestsService): void {
            $delivery = $requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID);
            if ($delivery !== null) {
                $requestsService->answerRequest(self::CONVERSATION_ID, $delivery->id, self::CLIENT_ID, 'OK', 'x', null);
            }
        };

        $answer = $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, AgentClientToolRequest::TYPE_READ_SCREEN, [], 1);

        self::assertInstanceOf(ForbiddenException::class, $requestsService->clientFailure);
        self::assertSame(AgentClientToolAnswer::KIND_NO_ANSWER, $answer->kind);
    }

    public function testAPictureAnswerIsCheckedAndReachesTheTool(): void
    {
        $requestsService = new SimulatedClientRequestsServiceDouble();
        $requestsService->presenceService->recordClientStatement(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID, null);
        $pictureBase64 = ToolPictureFixtures::buildPngBase64(120, 80);
        $requestsService->simulatedClient = static function (SimulatedClientRequestsServiceDouble $requestsService) use ($pictureBase64): void {
            $delivery = $requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID);
            if ($delivery !== null) {
                $requestsService->answerRequest(self::CONVERSATION_ID, $delivery->id, self::CLIENT_ID, 'OK', null, null, $pictureBase64);
            }
        };

        $answer = $requestsService->requestAndWait(self::CONVERSATION_ID, self::ACCOUNT_ID, null, AgentClientToolRequest::TYPE_READ_SCREEN, ['format' => 'picture'], 3);

        self::assertInstanceOf(AgentScreenPicture::class, $answer->picture);
        self::assertSame('image/png', $answer->picture->mimeType);
        self::assertSame([120, 80], [$answer->picture->width, $answer->picture->height]);
    }

    public function testAPictureThatIsNoRasterImageOrTooLargeIsRefused(): void
    {
        foreach ([
            'not base64' => '%%%',
            'PostScript' => base64_encode("%!PS-Adobe-3.0\nshowpage"),
            'SVG' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            'too many bytes' => str_repeat('A', AgentClientToolRequestsService::MAX_PICTURE_BASE64_CHARS + 4),
            'too many pixels' => ToolPictureFixtures::buildPngBase64(2100, 2000),
        ] as $case => $pictureBase64) {
            try {
                AgentClientToolRequestsService::checkScreenPicture($pictureBase64);
                self::fail('refused: ' . $case);
            } catch (BadRequestException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('image/jpeg', AgentClientToolRequestsService::checkScreenPicture(ToolPictureFixtures::buildJpegBase64())->mimeType);
    }
}
