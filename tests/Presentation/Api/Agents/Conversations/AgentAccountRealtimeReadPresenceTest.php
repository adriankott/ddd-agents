<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\ClientTools\AgentClientPresence;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessage;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageDelivery;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AgentAccountRealtimeMessagesGetRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AgentAccountRealtimeMessagesGetResponseDto;
use DDD\Tests\Agents\Domain\AI\Services\ClientTools\SharedCachePresenceServiceDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\DemoRealtimeMessageTypeProviderDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\RecordingTransportChannelServiceDouble;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\SharedCacheWithCountersDouble;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use ReflectionProperty;

/**
 * The account-level realtime read (`GET …/realtimeMessages`) states the presence of a client that states its
 * `clientId`, so a client that only polls counts as listening for the account audience
 * ({@see AgentRealtimeChannelService::isClientListening()}): one entry under its own key, throttled per account, the
 * response unchanged; a value that is no client id is ignored (never a 400); a bound transport decides instead; the
 * statements of a client that shows a conversation stay byte-identical; a missing or failing shared cache never fails
 * the read; under the default kernel (voice off) the read touches only that key. The two hooks of the controller traits
 * (`stampUIClientOfAccount()`, `getAgentClientPresenceService()`) are override points.
 *
 * The read runs through the chat traits' controller, the realtime port is a fake one over an in-memory shared cache
 * that the container serves too (the presence service reads its cache through it). The code reads the clock itself:
 * ages are staged through the stored `lastSeenAt`, with a second of margin below the throttle (10 s) and inside the
 * fresh window (25 s), because a second can turn between staging and the read; 26 s is exact.
 */
class AgentAccountRealtimeReadPresenceTest extends IntegrationTestCase
{
    public const int ACCOUNT_ID = 3;

    public const int OTHER_ACCOUNT_ID = 4;

    public const int CONVERSATION_ID = 70;

    public const string POLLING_CLIENT_ID = 'poll-client-0001';

    public const string OTHER_POLLING_CLIENT_ID = 'poll-client-0002';

    public const string CONVERSATION_CLIENT_ID = 'conversation-client-0001';

    public const string STATEMENT_KEY = 'agent-client-presence-without-conversation-3';

    /** @var string The text of the warning the stamps log when a statement fails */
    public const string STATEMENT_SKIPPED_WARNING = '[AgentUI] client statement skipped';

    protected ?Account $accountBefore = null;

    /** @var string[] The warnings the realtime port reported once per process before the test */
    protected array $reportedWarningsBefore = [];

    protected SharedCacheWithCountersDouble $sharedCache;

    protected RecordingTransportChannelServiceDouble $channel;

    protected AgentAccountRealtimeReadFixtureController $fixtureController;

    protected RecordingWarningsLoggerDouble $logger;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootTestKernel();
        $this->sharedCache = new SharedCacheWithCountersDouble();
        $this->channel = new RecordingTransportChannelServiceDouble($this->sharedCache);
        $this->channel->hasTransport = false;
        $this->replaceService(AgentRealtimeChannelService::class, $this->channel);
        $this->fixtureController = new AgentAccountRealtimeReadFixtureController($this->channel);
        $this->logger = new RecordingWarningsLoggerDouble();
        static::getContainer()->set('logger', $this->logger);
        $this->reportedWarningsBefore = (array)static::getReportedWarningsProperty()->getValue();
        $this->accountBefore = AuthService::instance()->getAccount();
        $signedInAccount = new Account();
        $signedInAccount->id = static::ACCOUNT_ID;
        AuthService::instance()->setAccount($signedInAccount);
    }

    protected function onTearDown(): void
    {
        static::getReportedWarningsProperty()->setValue(null, $this->reportedWarningsBefore);
        AuthService::instance()->setAccount($this->accountBefore);
    }

    protected function assertPostConditions(): void
    {
        static::assertSame([], $this->logger->findDatabaseMessages(), 'no code path of the test reached the database');
        parent::assertPostConditions();
    }

    public function testAReadWithAClientIdStatesThePresenceOnceAndAnswersAsWithoutOne(): void
    {
        $this->sendAccountMessage('to-every-client');
        $responseWithoutId = $this->read(null);
        self::assertFalse($this->isAccountListening());
        $writesBefore = $this->sharedCache->batchWrites;
        $timeBefore = time();

        $responseWithId = $this->read(static::POLLING_CLIENT_ID);

        self::assertSame(static::describeResponse($responseWithoutId), static::describeResponse($responseWithId));
        self::assertSame(['to-every-client'], static::describeResponse($responseWithId)['messageKeys']);
        self::assertSame($writesBefore + 1, $this->sharedCache->batchWrites, 'one write');
        $statementEntry = $this->sharedCache->valuesByKey[static::STATEMENT_KEY];
        self::assertSame(0, $statementEntry['conversationId']);
        self::assertSame(static::ACCOUNT_ID, $statementEntry['accountId']);
        self::assertSame(static::POLLING_CLIENT_ID, $statementEntry['clientId']);
        self::assertGreaterThanOrEqual($timeBefore, $statementEntry['lastSeenAt']);
        self::assertLessThanOrEqual(time(), $statementEntry['lastSeenAt']);
        self::assertNull($statementEntry['visible']);
        self::assertNull($statementEntry['route']);
        self::assertNull($statementEntry['lastOwnerSendAt']);
        self::assertNull($statementEntry['uiStateJson']);
        self::assertSame(AgentClientPresenceService::PRESENCE_TTL_SECONDS, $this->sharedCache->ttlSecondsByKey[static::STATEMENT_KEY]);
        self::assertTrue($this->isAccountListening());
    }

    /** @return array<string, array{string|null}> */
    public static function provideReadsThatStateNothing(): array
    {
        return [
            'no client id' => [null],
            'an empty client id' => [''],
            'a client id under 8 characters' => ['short'],
            'a client id with a space' => ['has a space in it'],
            'a client id of 65 characters' => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('provideReadsThatStateNothing')]
    public function testAReadWithoutAValidClientIdStatesNothingAndIsNotRefused(?string $statedClientId): void
    {
        $this->sendAccountMessage('to-every-client');
        $writesBefore = $this->sharedCache->batchWrites;

        $responseDto = $this->read($statedClientId);

        self::assertSame(['messageKeys' => ['to-every-client'], 'credential' => null], static::describeResponse($responseDto));
        self::assertArrayNotHasKey(static::STATEMENT_KEY, $this->sharedCache->valuesByKey);
        self::assertSame($writesBefore, $this->sharedCache->batchWrites);
        self::assertFalse($this->isAccountListening());
        self::assertSame([], $this->logger->findMessages(LogLevel::WARNING, static::STATEMENT_SKIPPED_WARNING));
    }

    public function testUnderTheThrottleAReadOfAnyClientWritesNothingAndCostsOneCacheReadMore(): void
    {
        $this->read(static::POLLING_CLIENT_ID);
        $this->stageStatementAge(static::STATEMENT_KEY, 8);
        $storedEntry = $this->sharedCache->valuesByKey[static::STATEMENT_KEY];
        $writesBefore = $this->sharedCache->batchWrites;

        $readsBeforeWithoutId = $this->sharedCache->batchReads;
        $this->read(null);
        $readsOfAReadWithoutId = $this->sharedCache->batchReads - $readsBeforeWithoutId;
        $readsBeforeWithId = $this->sharedCache->batchReads;
        $this->read(static::POLLING_CLIENT_ID);
        $readsOfAReadWithId = $this->sharedCache->batchReads - $readsBeforeWithId;
        $this->read(static::OTHER_POLLING_CLIENT_ID);

        self::assertSame($writesBefore, $this->sharedCache->batchWrites, 'no write inside the throttle, whichever client reads');
        self::assertSame($storedEntry, $this->sharedCache->valuesByKey[static::STATEMENT_KEY]);
        self::assertSame($readsOfAReadWithoutId + 1, $readsOfAReadWithId, 'one cache read more per read with an id');
    }

    public function testAtTheThrottleTheReadWritesOnceAndTheReadingClientsIdReplacesTheStoredOne(): void
    {
        $this->read(static::POLLING_CLIENT_ID);
        $this->stageStatementAge(static::STATEMENT_KEY, AgentClientPresenceService::STATEMENT_THROTTLE_SECONDS);
        $writesBefore = $this->sharedCache->batchWrites;

        $this->read(static::OTHER_POLLING_CLIENT_ID);

        self::assertSame($writesBefore + 1, $this->sharedCache->batchWrites);
        self::assertSame(static::OTHER_POLLING_CLIENT_ID, $this->sharedCache->valuesByKey[static::STATEMENT_KEY]['clientId']);
    }

    public function testTheAccountListensWhileTheStatementIsFreshAndNotAfterTwentySixSeconds(): void
    {
        $this->read(static::POLLING_CLIENT_ID);
        $this->stageStatementAge(static::STATEMENT_KEY, AgentClientPresence::FRESH_FOR_SECONDS - 1);
        self::assertTrue($this->isAccountListening());

        $this->stageStatementAge(static::STATEMENT_KEY, 2);
        self::assertFalse($this->isAccountListening());
        self::assertNull($this->getPresenceService()->findNewestPresenceOfAccount(static::ACCOUNT_ID));
    }

    public function testABoundTransportDecidesInsteadOfTheStatement(): void
    {
        $accountAudience = AgentRealtimeAudience::forAccount(static::ACCOUNT_ID);
        $this->read(static::POLLING_CLIENT_ID);
        $this->channel->hasTransport = true;

        self::assertFalse($this->channel->isClientListening($accountAudience), 'the transport says nobody listens: the fresh statement is not asked');

        $this->channel->listeningByMailboxName[$accountAudience->getMailboxName()] = true;
        unset($this->sharedCache->valuesByKey[static::STATEMENT_KEY]);
        self::assertTrue($this->channel->isClientListening($accountAudience), 'the transport says a client listens: no statement needed');
    }

    public function testUnderTheDefaultKernelVoiceIsOffAndTheReadTouchesOnlyTheStatementKey(): void
    {
        self::assertFalse(AgentVoiceConfiguration::current()->enabled);
        $agentVoiceCallStoreService = $this->createMock(AgentVoiceCallStoreService::class);
        $agentVoiceCallStoreService->expects(self::never())->method(self::anything());
        $this->replaceService(AgentVoiceCallStoreService::class, $agentVoiceCallStoreService);
        $agentVoiceCallsService = $this->createMock(AgentVoiceCallsService::class);
        $agentVoiceCallsService->expects(self::never())->method(self::anything());
        $this->replaceService(AgentVoiceCallsService::class, $agentVoiceCallsService);

        $this->read(static::POLLING_CLIENT_ID);

        self::assertSame([static::STATEMENT_KEY], array_keys($this->sharedCache->valuesByKey));
        self::assertSame(1, $this->sharedCache->batchWrites);
        self::assertSame([], $this->logger->findMessages(LogLevel::WARNING, static::STATEMENT_SKIPPED_WARNING));
    }

    public function testTheStatementsOfAClientThatShowsAConversationStayAsTheyAre(): void
    {
        $agentUIState = new AgentUIState();
        $agentUIState->routePath = '/orders/42';
        $agentUIState->visible = true;
        $this->getPresenceService()->recordClientStatement(static::CONVERSATION_ID, static::ACCOUNT_ID, static::CONVERSATION_CLIENT_ID, $agentUIState, true);
        $conversationKey = AgentClientPresenceService::CONVERSATION_KEY_PREFIX . static::CONVERSATION_ID;
        $accountKey = AgentClientPresenceService::ACCOUNT_KEY_PREFIX . static::ACCOUNT_ID;
        $serializedBefore = [serialize($this->sharedCache->valuesByKey[$conversationKey]), serialize($this->sharedCache->valuesByKey[$accountKey])];

        $this->read(static::POLLING_CLIENT_ID);
        $this->stageStatementAge(static::STATEMENT_KEY, AgentClientPresenceService::STATEMENT_THROTTLE_SECONDS);
        $this->read(static::CONVERSATION_CLIENT_ID);

        self::assertSame($serializedBefore, [serialize($this->sharedCache->valuesByKey[$conversationKey]), serialize($this->sharedCache->valuesByKey[$accountKey])]);
        self::assertSame(static::CONVERSATION_CLIENT_ID, $this->sharedCache->valuesByKey[static::STATEMENT_KEY]['clientId'], 'the statement without a conversation is its own entry');
        self::assertSame('/orders/42', $this->getPresenceService()->findNewestPresenceOfAccount(static::ACCOUNT_ID)?->route, 'the conversation statement is answered first');
    }

    public function testOnlyTheAccountAudienceOfTheReadingAccountListensBecauseOfTheStatement(): void
    {
        $this->read(static::POLLING_CLIENT_ID);

        self::assertTrue($this->isAccountListening());
        self::assertFalse($this->channel->isClientListening(AgentRealtimeAudience::forConversation(static::ACCOUNT_ID, static::CONVERSATION_ID)), 'a conversation audience needs a client of the conversation');
        self::assertFalse($this->channel->isClientListening(AgentRealtimeAudience::forAccount(static::OTHER_ACCOUNT_ID)), 'another account does not listen');
        self::assertNull($this->getPresenceService()->findTargetPresence(static::CONVERSATION_ID));
    }

    public function testAFailingSharedCacheNeitherFailsNorChangesTheReadAndLogsOneWarning(): void
    {
        $this->sharedCache->failsEveryCall = true;

        $responseDto = $this->read(static::POLLING_CLIENT_ID);

        self::assertSame(['messageKeys' => [], 'credential' => null], static::describeResponse($responseDto));
        self::assertCount(1, $this->logger->findMessages(LogLevel::WARNING, static::STATEMENT_SKIPPED_WARNING));
        $this->sharedCache->failsEveryCall = false;
        self::assertSame([], $this->sharedCache->valuesByKey);
        self::assertFalse($this->isAccountListening());
    }

    public function testWithoutASharedCacheTheReadsAnswerAsBeforeAndThePortWarnsOnce(): void
    {
        static::getReportedWarningsProperty()->setValue(null, []);
        $this->channel->sharedCacheDouble = null;

        $firstResponse = $this->read(static::POLLING_CLIENT_ID);
        $secondResponse = $this->read(static::POLLING_CLIENT_ID);

        self::assertSame(['messageKeys' => [], 'credential' => null], static::describeResponse($firstResponse));
        self::assertSame(static::describeResponse($firstResponse), static::describeResponse($secondResponse));
        self::assertSame([], $this->logger->findMessages(LogLevel::WARNING, static::STATEMENT_SKIPPED_WARNING), 'nothing is stated, nothing fails');
        self::assertCount(1, $this->logger->findMessages(LogLevel::WARNING, 'no shared cache'));
        self::assertSame([], $this->sharedCache->valuesByKey);
        self::assertFalse($this->isAccountListening());
    }

    public function testAnOverrideOfTheAccountStampThatDoesNothingStatesNothing(): void
    {
        $fixtureController = new AgentAccountRealtimeReadWithoutStampFixtureController($this->channel);
        $requestDto = new AgentAccountRealtimeMessagesGetRequestDto();
        $requestDto->clientId = static::POLLING_CLIENT_ID;

        $fixtureController->callBuildAccountRealtimeMessagesResponse($requestDto);

        self::assertSame([], $this->sharedCache->valuesByKey);
        self::assertSame(0, $this->sharedCache->batchWrites);
        self::assertFalse($this->isAccountListening());
    }

    public function testAnOverriddenPresenceServiceServesBothStamps(): void
    {
        $ownSharedCache = new SharedCacheWithCountersDouble();
        $fixtureController = new AgentRealtimeReadOwnPresenceServiceFixtureController($this->channel, new SharedCachePresenceServiceDouble($ownSharedCache));
        $requestDto = new AgentAccountRealtimeMessagesGetRequestDto();
        $requestDto->clientId = static::POLLING_CLIENT_ID;
        $conversation = new AIConversation();
        $conversation->id = static::CONVERSATION_ID;
        $conversation->accountId = static::ACCOUNT_ID;

        $fixtureController->callBuildAccountRealtimeMessagesResponse($requestDto);
        $fixtureController->callStampUIClientOfConversation($conversation, static::CONVERSATION_CLIENT_ID, null, false);

        self::assertEqualsCanonicalizing(
            [
                static::STATEMENT_KEY,
                AgentClientPresenceService::CONVERSATION_KEY_PREFIX . static::CONVERSATION_ID,
                AgentClientPresenceService::ACCOUNT_KEY_PREFIX . static::ACCOUNT_ID,
            ],
            array_keys($ownSharedCache->valuesByKey)
        );
        self::assertSame([], $this->sharedCache->valuesByKey, 'the container\'s presence service stated nothing');
    }

    protected function read(?string $clientId): AgentAccountRealtimeMessagesGetResponseDto
    {
        $requestDto = new AgentAccountRealtimeMessagesGetRequestDto();
        $requestDto->clientId = $clientId;
        return $this->fixtureController->callBuildAccountRealtimeMessagesResponse($requestDto);
    }

    protected function sendAccountMessage(string $messageKey): void
    {
        $this->channel->sendMessage(AgentRealtimeMessage::create(
            DemoRealtimeMessageTypeProviderDouble::MESSAGE_TYPE_RING,
            $messageKey,
            '{}',
            (new DateTime())->modify('+30 seconds'),
            AgentRealtimeAudience::forAccount(static::ACCOUNT_ID)
        ));
    }

    protected function isAccountListening(): bool
    {
        return $this->channel->isClientListening(AgentRealtimeAudience::forAccount(static::ACCOUNT_ID));
    }

    /** Moves the stored statement under `$cacheKey` `$bySeconds` into the past. */
    protected function stageStatementAge(string $cacheKey, int $bySeconds): void
    {
        $this->sharedCache->valuesByKey[$cacheKey]['lastSeenAt'] -= $bySeconds;
    }

    /** @return array{messageKeys: string[], credential: string|null} what the client reads from the response */
    protected static function describeResponse(AgentAccountRealtimeMessagesGetResponseDto $responseDto): array
    {
        return [
            'messageKeys' => array_map(
                static fn (AgentRealtimeMessageDelivery $agentRealtimeMessageDelivery): string => $agentRealtimeMessageDelivery->messageKey,
                $responseDto->realtimeMessages->getElements()
            ),
            'credential' => $responseDto->realtimeListenerCredential?->credential,
        ];
    }

    protected function getPresenceService(): AgentClientPresenceService
    {
        /** @var AgentClientPresenceService $agentClientPresenceService */
        $agentClientPresenceService = DDDService::instance()->getService(AgentClientPresenceService::class);
        return $agentClientPresenceService;
    }

    protected static function getReportedWarningsProperty(): ReflectionProperty
    {
        return new ReflectionProperty(AgentRealtimeChannelService::class, 'reportedWarnings');
    }
}
