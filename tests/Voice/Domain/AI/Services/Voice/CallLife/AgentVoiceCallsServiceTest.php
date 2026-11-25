<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\SignalSources\PhoneConversationSignalSource;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallFollowUpsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The life of a call after it exists ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallsService}), staged over an
 * in-memory call store, the package's conversation and message services without a database, and the documented
 * override points of the service for everything outside the package.
 */
class AgentVoiceCallsServiceTest extends AgentVoiceIntegrationTestCase
{
    protected InMemoryAgentVoiceCallStore $callStore;

    protected StagedAgentVoiceCallsService $agentVoiceCallsService;

    protected FixedConversationsAIConversationsService $conversationsService;

    protected RecordingAIConversationMessagesService $messagesService;

    protected RecordingAIConversationRealtimeSignalService $realtimeSignalService;

    protected RecordingAgentVoiceCallFollowUpsService $followUpsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->callStore = new InMemoryAgentVoiceCallStore();
        $this->conversationsService = new FixedConversationsAIConversationsService();
        $this->messagesService = new RecordingAIConversationMessagesService();
        $this->realtimeSignalService = new RecordingAIConversationRealtimeSignalService();
        $this->followUpsService = new RecordingAgentVoiceCallFollowUpsService();
        $this->replaceService(AgentVoiceCallStoreService::class, new InMemoryStoreAgentVoiceCallStoreService($this->callStore));
        $this->replaceService(AIConversationsService::class, $this->conversationsService);
        $this->replaceService(AIConversationMessagesService::class, $this->messagesService);
        $this->replaceService(AIConversationRealtimeSignalService::class, $this->realtimeSignalService);
        $this->replaceService(AgentVoiceCallFollowUpsService::class, $this->followUpsService);
        StaticRegistry::$entityServices = [];
        $this->agentVoiceCallsService = new StagedAgentVoiceCallsService();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]));
        // a persona that names nobody: the texts say "the agent"
        $this->declareAgentPersonaConfiguration(['agentName' => '']);
        $this->conversationsService->conversationsById[30] = $this->buildConversation(30);
    }

    public function testTheUnansweredEndIsClaimedOnceWithOneMarkerAndOneFollowUp(): void
    {
        $agentVoiceCall = $this->buildRingingOutboundCall(7, 30);

        static::assertTrue($this->agentVoiceCallsService->persistUnansweredCallEndOnce($agentVoiceCall, AgentVoiceCall::END_REASON_EXPIRED));
        static::assertFalse($this->agentVoiceCallsService->persistUnansweredCallEndOnce($agentVoiceCall, AgentVoiceCall::END_REASON_EXPIRED));

        static::assertSame([[7, AgentVoiceCall::END_REASON_EXPIRED], [7, AgentVoiceCall::END_REASON_EXPIRED]], $this->callStore->getArgumentsOf('claimUnansweredCallEnd'));
        static::assertSame([[7, AgentVoiceCall::END_REASON_EXPIRED]], $this->callStore->getArgumentsOf('markCallEnded'));
        static::assertSame([['callId' => 7, 'endReason' => AgentVoiceCall::END_REASON_EXPIRED]], $this->followUpsService->followUpQuestions);
        static::assertCount(1, $this->messagesService->insertedRows);
        $endMarker = $this->messagesService->insertedRows[0]['message'];
        static::assertSame(30, $this->messagesService->insertedRows[0]['conversationId']);
        static::assertSame(AIConversationMessage::MESSAGE_SIGNAL, $endMarker->messageType);
        static::assertSame(AIConversationMessage::ROLE_SYSTEM, $endMarker->role);
        static::assertSame(PhoneConversationSignalSource::PHONE_CALL_ENDED->value, $endMarker->signalSource);
        static::assertSame('In-app call ended — the agent → in-app, reason EXPIRED (call #7).', $endMarker->message);
    }

    /**
     * The ring deadline, a decline and the account-wide expiry each hold their own object of the same call (another
     * process read the row): the claim decides by the call, so the first path ends it and the others write nothing.
     *
     * @param string[] $endReasonsInOrder
     */
    #[DataProvider('provideRacingEndPaths')]
    public function testTwoPathsReachingTheSameCallEndItOnce(array $endReasonsInOrder): void
    {
        $answers = [];
        foreach ($endReasonsInOrder as $endReason) {
            $answers[] = $this->agentVoiceCallsService->persistUnansweredCallEndOnce($this->buildRingingOutboundCall(7, 30), $endReason);
        }

        static::assertSame(array_merge([true], array_fill(0, count($endReasonsInOrder) - 1, false)), $answers);
        static::assertSame([[7, $endReasonsInOrder[0]]], $this->callStore->getArgumentsOf('markCallEnded'));
        static::assertSame([['callId' => 7, 'endReason' => $endReasonsInOrder[0]]], $this->followUpsService->followUpQuestions);
        static::assertCount(1, $this->messagesService->insertedRows);
        static::assertStringContainsString('reason ' . $endReasonsInOrder[0] . ' ', $this->messagesService->insertedRows[0]['message']->message);
    }

    /** @return array<string, array{string[]}> */
    public static function provideRacingEndPaths(): array
    {
        return [
            'the deadline, then a decline' => [[AgentVoiceCall::END_REASON_EXPIRED, AgentVoiceCall::END_REASON_DECLINED]],
            'a decline, then the deadline' => [[AgentVoiceCall::END_REASON_DECLINED, AgentVoiceCall::END_REASON_EXPIRED]],
            'the deadline, the account-wide expiry and a decline' => [[AgentVoiceCall::END_REASON_EXPIRED, AgentVoiceCall::END_REASON_EXPIRED, AgentVoiceCall::END_REASON_DECLINED]],
        ];
    }

    public function testTheMarkerIsRetriedOnceAndAFailureNeverBreaksTheEnd(): void
    {
        $this->messagesService->failingInsertCount = 1;
        static::assertTrue($this->agentVoiceCallsService->persistUnansweredCallEndOnce($this->buildRingingOutboundCall(7, 30), AgentVoiceCall::END_REASON_DECLINED));
        static::assertSame(2, $this->messagesService->insertAttempts);
        static::assertCount(1, $this->messagesService->insertedRows);
        static::assertSame([30, 30], $this->conversationsService->foundConversationIds);
    }

    public function testAMarkerThatFailsTwiceIsSwallowed(): void
    {
        $this->messagesService->failingInsertCount = 2;
        $agentVoiceCall = $this->buildRingingOutboundCall(7, 30);

        static::assertTrue($this->agentVoiceCallsService->persistUnansweredCallEndOnce($agentVoiceCall, AgentVoiceCall::END_REASON_DECLINED));
        static::assertSame(2, $this->messagesService->insertAttempts);
        static::assertSame([], $this->messagesService->insertedRows);
        static::assertSame(AgentVoiceCall::END_REASON_DECLINED, $agentVoiceCall->endReason);
        static::assertCount(1, $this->followUpsService->followUpQuestions);
    }

    public function testACallWithoutAConversationOrWithAGoneOneGetsNoMarker(): void
    {
        $this->agentVoiceCallsService->persistCallEnd($this->buildRingingOutboundCall(7, null), AgentVoiceCall::END_REASON_FAILED);
        $this->agentVoiceCallsService->persistCallEnd($this->buildRingingOutboundCall(8, 31), AgentVoiceCall::END_REASON_FAILED);

        static::assertSame([[7, AgentVoiceCall::END_REASON_FAILED], [8, AgentVoiceCall::END_REASON_FAILED]], $this->callStore->getArgumentsOf('markCallEnded'));
        static::assertSame([31], $this->conversationsService->foundConversationIds);
        static::assertSame([], $this->messagesService->insertedRows);
    }

    public function testTheAnsweredCallIsStampedAndMarkedStartedInItsConversation(): void
    {
        $agentVoiceCall = $this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND);

        $this->agentVoiceCallsService->persistCallAnswered($agentVoiceCall);

        static::assertSame(['markCallStarted'], $this->callStore->getRecordedMethodNames());
        static::assertNotNull($agentVoiceCall->startedAt);
        static::assertCount(1, $this->messagesService->insertedRows);
        $startMarker = $this->messagesService->insertedRows[0]['message'];
        static::assertSame(PhoneConversationSignalSource::PHONE_CALL_STARTED->value, $startMarker->signalSource);
        static::assertSame('In-app call started — in-app → the agent (call #7).', $startMarker->message);
        static::assertSame([['conversationId' => 30, 'runStatus' => AIConversationRealtimeSignalService::STATUS_IDLE]], $this->realtimeSignalService->publishedSignals);
    }

    public function testTheMarkerNamesThePersonasAgent(): void
    {
        $this->declareAgentPersonaConfiguration(['agentName' => 'Mira']);

        $this->agentVoiceCallsService->persistCallAnswered($this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND));

        static::assertSame('In-app call started — in-app → Mira (call #7).', $this->messagesService->insertedRows[0]['message']->message);
    }

    public function testEndingAnAnsweredCallNowEndsItAndHangsTheSessionUp(): void
    {
        $agentVoiceCall = $this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND);
        $agentVoiceCall->startedAt = DateTime::fromTimestamp(time() - 60);
        $agentVoiceCall->providerCallId = 'session-41';

        static::assertTrue($this->agentVoiceCallsService->endCallNow($agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED));

        static::assertSame(['markCallEnded'], $this->callStore->getRecordedMethodNames());
        static::assertSame(['furtherLegs:7', 'hangUp:session-41'], $this->agentVoiceCallsService->recordedReaches);
        static::assertSame([], $this->followUpsService->followUpQuestions);
        static::assertCount(1, $this->messagesService->insertedRows);
    }

    public function testEndingARingingCallTheAgentStartedWithdrawsTheRingOnce(): void
    {
        $agentVoiceCall = $this->buildRingingOutboundCall(7, 30);

        static::assertTrue($this->agentVoiceCallsService->endCallNow($agentVoiceCall, AgentVoiceCall::END_REASON_ENDED_BY_AGENT));
        static::assertFalse($this->agentVoiceCallsService->endCallNow($this->buildRingingOutboundCall(7, 30), AgentVoiceCall::END_REASON_ENDED_BY_AGENT));

        static::assertSame(['cancelRing:7'], $this->agentVoiceCallsService->recordedReaches);
        static::assertSame([[7, AgentVoiceCall::END_REASON_ENDED_BY_AGENT]], $this->callStore->getArgumentsOf('markCallEnded'));
        static::assertCount(1, $this->followUpsService->followUpQuestions);
    }

    public function testACallTheOwnerOpenedHasNoRingToWithdraw(): void
    {
        static::assertTrue($this->agentVoiceCallsService->endCallNow($this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND), AgentVoiceCall::END_REASON_COMPLETED));
        static::assertSame([], $this->agentVoiceCallsService->recordedReaches);
    }

    public function testAFailingRingCancelAndAFailingHangUpNeverBreakTheEnd(): void
    {
        $this->agentVoiceCallsService->ringCancelFailureMessage = 'push refused';
        $this->agentVoiceCallsService->hangUpFailureMessage = 'provider unreachable';
        $agentVoiceCall = $this->buildRingingOutboundCall(7, 30);
        $agentVoiceCall->providerCallId = 'session-41';

        static::assertTrue($this->agentVoiceCallsService->endCallNow($agentVoiceCall, AgentVoiceCall::END_REASON_CANCELED));
        static::assertSame(['cancelRing:7', 'hangUp:session-41'], $this->agentVoiceCallsService->recordedReaches);
        static::assertSame(AgentVoiceCall::END_REASON_CANCELED, $agentVoiceCall->endReason);
    }

    public function testAnEndedCallIsLeftAsItIsButItsSessionIsHungUpAgain(): void
    {
        $agentVoiceCall = $this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND);
        $agentVoiceCall->startedAt = DateTime::fromTimestamp(time() - 60);
        $agentVoiceCall->endedAt = DateTime::fromTimestamp(time() - 10);
        $agentVoiceCall->endReason = AgentVoiceCall::END_REASON_COMPLETED;
        $agentVoiceCall->providerCallId = 'session-41';

        static::assertFalse($this->agentVoiceCallsService->endCallNow($agentVoiceCall, AgentVoiceCall::END_REASON_ENDED_BY_AGENT));
        static::assertSame([], $this->callStore->recordedCalls);
        static::assertSame(['hangUp:session-41'], $this->agentVoiceCallsService->recordedReaches);
        static::assertSame(AgentVoiceCall::END_REASON_COMPLETED, $agentVoiceCall->endReason);
    }

    public function testTheGoodbyeRequestThrowsWithoutASharedCache(): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('shared_cache_unavailable');

        $this->agentVoiceCallsService->requestCallEndAfterGoodbye($this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND), AgentVoiceCall::END_REASON_ENDED_BY_AGENT);
    }

    public function testTheGoodbyeRequestIsStoredReadAndClearedUnderTheCallId(): void
    {
        $sharedCache = new InMemorySharedCache();
        $this->agentVoiceCallsService->sharedCache = $sharedCache;
        $agentVoiceCall = $this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND);

        static::assertNull($this->agentVoiceCallsService->findRequestedCallEndAfterGoodbye($agentVoiceCall));
        $this->agentVoiceCallsService->requestCallEndAfterGoodbye($agentVoiceCall, AgentVoiceCall::END_REASON_ENDED_BY_AGENT);

        static::assertSame(['voice-call-end-after-goodbye-7' => AgentVoiceCall::END_REASON_ENDED_BY_AGENT], $sharedCache->valuesByKey);
        static::assertSame(['voice-call-end-after-goodbye-7' => 900], $sharedCache->ttlSecondsByKey);
        static::assertSame(AgentVoiceCall::END_REASON_ENDED_BY_AGENT, $this->agentVoiceCallsService->findRequestedCallEndAfterGoodbye($agentVoiceCall));
        static::assertNull($this->agentVoiceCallsService->findRequestedCallEndAfterGoodbye($this->buildCall(8, 30, AgentVoiceCall::DIRECTION_INBOUND)));
        $this->agentVoiceCallsService->clearRequestedCallEndAfterGoodbye($agentVoiceCall);
        static::assertSame([], $sharedCache->valuesByKey);
    }

    public function testAnEmptyGoodbyeRequestIsNoRequest(): void
    {
        $sharedCache = new InMemorySharedCache();
        $sharedCache->valuesByKey['voice-call-end-after-goodbye-7'] = '';
        $this->agentVoiceCallsService->sharedCache = $sharedCache;

        static::assertNull($this->agentVoiceCallsService->findRequestedCallEndAfterGoodbye($this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND)));
    }

    public function testTheOwnersSpokenSentenceIsPersistedAndMarkedAsAVoiceTranscript(): void
    {
        $conversation = $this->conversationsService->conversationsById[30];

        $transcriptMessage = $this->agentVoiceCallsService->persistTranscriptMessage($conversation, AIConversationMessage::ROLE_USER, 'Hello there');

        static::assertSame([['conversationId' => 30, 'message' => $transcriptMessage]], $this->messagesService->insertedRows);
        static::assertSame('Hello there', $transcriptMessage->message);
        static::assertSame(AIConversationMessage::AUTHOR_USER, $transcriptMessage->authorType);
        static::assertSame([(int)$transcriptMessage->indexInConversation], $conversation->getOrCreateAgentWorkingState()->voiceTranscriptOwnerMessageIndexes);
        static::assertSame([['conversationId' => 30, 'sliceKeys' => ['voiceTranscriptOwnerMessageIndexes']]], $this->conversationsService->persistedWorkingStateSlices);
        static::assertCount(1, $this->realtimeSignalService->publishedSignals);
    }

    public function testTheAgentsSpokenSentenceIsNoOwnerTranscript(): void
    {
        $conversation = $this->conversationsService->conversationsById[30];

        $transcriptMessage = $this->agentVoiceCallsService->persistTranscriptMessage($conversation, AIConversationMessage::ROLE_ASSISTANT, 'Glad to help');

        static::assertSame(AIConversationMessage::AUTHOR_ASSISTANT, $transcriptMessage->authorType);
        static::assertSame([], $this->conversationsService->persistedWorkingStateSlices);
        static::assertCount(1, $this->messagesService->insertedRows);
    }

    public function testAFailingTranscriptMarkerNeverBlocksTheSentence(): void
    {
        $this->conversationsService->workingStateSliceFailure = new RuntimeException('working state locked');

        $transcriptMessage = $this->agentVoiceCallsService->persistTranscriptMessage($this->conversationsService->conversationsById[30], AIConversationMessage::ROLE_USER, 'Hello there');

        static::assertSame([['conversationId' => 30, 'message' => $transcriptMessage]], $this->messagesService->insertedRows);
        static::assertCount(1, $this->realtimeSignalService->publishedSignals);
    }

    public function testALostRunClaimIsPassedOnFromTheTranscriptMarker(): void
    {
        $this->conversationsService->workingStateSliceFailure = new AgentRunClaimLostException('another worker drives the conversation');

        try {
            $this->agentVoiceCallsService->persistTranscriptMessage($this->conversationsService->conversationsById[30], AIConversationMessage::ROLE_USER, 'Hello there');
            static::fail('the lost run claim was swallowed');
        } catch (AgentRunClaimLostException $claimLost) {
            static::assertSame('another worker drives the conversation', $claimLost->getMessage());
        }
        static::assertSame([], $this->realtimeSignalService->publishedSignals);
    }

    public function testTheGoodbyeReadsNeverThrow(): void
    {
        $sharedCache = new InMemorySharedCache();
        $sharedCache->failsEveryCall = true;
        $this->agentVoiceCallsService->sharedCache = $sharedCache;
        $agentVoiceCall = $this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND);

        static::assertNull($this->agentVoiceCallsService->findRequestedCallEndAfterGoodbye($agentVoiceCall));
        $this->agentVoiceCallsService->clearRequestedCallEndAfterGoodbye($agentVoiceCall);
        $agentVoiceCallWithoutId = $this->buildCall(7, 30, AgentVoiceCall::DIRECTION_INBOUND);
        $agentVoiceCallWithoutId->id = null;
        static::assertNull($this->agentVoiceCallsService->findRequestedCallEndAfterGoodbye($agentVoiceCallWithoutId));
    }

    #[DataProvider('provideStaleThresholds')]
    public function testTheStaleThresholdIsTwiceTheLongestCallAndNeverUnder120Minutes(int $maximumCallSeconds, int $expectedMinutes): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['limits' => ['maximumCallSeconds' => $maximumCallSeconds]]));

        static::assertSame($expectedMinutes, $this->agentVoiceCallsService->getRunningCallStaleAfterMinutes());
        $this->agentVoiceCallsService->closeStaleRunningCalls();
        static::assertSame([[$expectedMinutes]], $this->callStore->getArgumentsOf('findRunningCallsStartedBefore'));
    }

    /** @return array<string, array{int, int}> */
    public static function provideStaleThresholds(): array
    {
        return [
            'a short longest call keeps the floor' => [60, 120],
            'the shipped hour is exactly the floor' => [3600, 120],
            'one second more rounds up to 61 minutes' => [3601, 122],
            'two hours' => [7200, 240],
        ];
    }

    #[DataProvider('provideEnvironments')]
    public function testTheSweepClosesOnlyTheCallsOfThisEnvironment(bool $runsInProduction, array $expectedClosedCallIds): void
    {
        $this->agentVoiceCallsService->runsInProduction = $runsInProduction;
        $this->conversationsService->conversationIdsOfThisEnvironment = [30];
        $this->callStore->staleRunningCalls = [
            $this->buildStaleCall(1, 30, 'session-1'),
            $this->buildStaleCall(2, 31, 'session-2'),
            $this->buildStaleCall(3, null, 'session-3'),
        ];

        $closedCalls = $this->agentVoiceCallsService->closeStaleRunningCalls();

        static::assertSame($expectedClosedCallIds, array_map(static fn (AgentVoiceCall $agentVoiceCall): int => (int)$agentVoiceCall->id, $closedCalls->getElements()));
        static::assertSame([[30, 31]], $this->conversationsService->environmentQuestions);
        $expectedEnds = array_map(static fn (int $callId): array => [$callId, 'reaped_stale'], $expectedClosedCallIds);
        static::assertSame($expectedEnds, $this->callStore->getArgumentsOf('markCallEnded'));
        static::assertSame(array_map(static fn (int $callId): string => 'hangUp:session-' . $callId, $expectedClosedCallIds), $this->agentVoiceCallsService->recordedReaches);
    }

    /** @return array<string, array{bool, int[]}> */
    public static function provideEnvironments(): array
    {
        return [
            'a workspace closes only its own conversations\' calls' => [false, [1]],
            'production adopts a call without a conversation' => [true, [1, 3]],
        ];
    }

    public function testAFailingProviderHangUpDoesNotKeepAStaleCallOpen(): void
    {
        $this->conversationsService->conversationIdsOfThisEnvironment = [30];
        $this->agentVoiceCallsService->hangUpFailureMessage = 'provider unreachable';
        $staleCall = $this->buildStaleCall(1, 30, 'session-1');
        $staleCallWithoutSession = $this->buildStaleCall(2, 30, null);
        $this->callStore->staleRunningCalls = [$staleCall, $staleCallWithoutSession];

        static::assertSame([$staleCall, $staleCallWithoutSession], $this->agentVoiceCallsService->closeStaleRunningCalls()->getElements());
        static::assertSame(['hangUp:session-1'], $this->agentVoiceCallsService->recordedReaches);
        static::assertSame('reaped_stale', $staleCall->endReason);
        static::assertSame('reaped_stale', $staleCallWithoutSession->endReason);
        static::assertCount(2, $this->messagesService->insertedRows);
    }

    public function testTheWindowSessionCallIsInboundOnTheDefaultProviderAndItsConversation(): void
    {
        $account = $this->buildAccount(5);

        $agentVoiceCall = $this->agentVoiceCallsService->createCallOfWindowSession($account, null, $this->buildConversation(30));

        static::assertSame([['createCall', [$agentVoiceCall]]], array_map(static fn (array $recordedCall): array => [$recordedCall['method'], $recordedCall['arguments']], $this->callStore->recordedCalls));
        static::assertSame(AgentVoiceCall::DIRECTION_INBOUND, $agentVoiceCall->direction);
        static::assertSame(5, $agentVoiceCall->accountId);
        static::assertSame('openAiLive', $agentVoiceCall->voiceProvider);
        static::assertSame(30, $agentVoiceCall->aiConversationId);
        static::assertNull($agentVoiceCall->startedAt);
        static::assertNull($agentVoiceCall->ringDeadlineAt);
        static::assertSame([['callDirection' => AgentVoiceCall::DIRECTION_INBOUND, 'accountId' => 5, 'agentSpace' => null]], $this->agentVoiceCallsService->newCallValueQuestions);
        static::assertSame([], $this->agentVoiceCallsService->recordedReaches);
    }

    public function testWithoutAConfiguredProviderTheCallPinsNone(): void
    {
        $this->declareAgentVoiceConfiguration(['enabled' => true, 'provider' => ['default' => '', 'adapters' => []]]);

        static::assertNull($this->agentVoiceCallsService->createCallOfWindowSession($this->buildAccount(5), null, $this->buildConversation(30))->voiceProvider);
    }

    public function testAFailedConversationOfAWindowSessionLeavesTheCallWithoutOne(): void
    {
        $this->agentVoiceCallsService->conversationValuesFailureMessage = 'no model for calls';

        $agentVoiceCall = $this->agentVoiceCallsService->createCallOfWindowSession($this->buildAccount(5), null, null);

        static::assertSame(['conversationValues:5'], $this->agentVoiceCallsService->recordedReaches);
        static::assertNull($agentVoiceCall->aiConversationId);
        static::assertSame(['createCall'], $this->callStore->getRecordedMethodNames());
    }

    public function testTheOpenCallCheckExpiresTheAccountsOverdueRingsFirst(): void
    {
        $openCall = $this->buildRingingOutboundCall(7, 30);
        $this->callStore->openCallOfAccount = $openCall;
        $this->agentVoiceCallsService->accountsById[5] = $this->buildAccount(5);

        static::assertSame($openCall, $this->agentVoiceCallsService->findOpenAppCallForAccountId(5));
        static::assertSame(['findAccount:5', 'expireRings:5'], $this->agentVoiceCallsService->recordedReaches);
        static::assertSame([[5]], $this->callStore->getArgumentsOf('findOpenCallOfAccount'));
    }

    public function testAFailedExpiryNeverBlocksTheOpenCallCheck(): void
    {
        $this->agentVoiceCallsService->accountsById[5] = $this->buildAccount(5);
        $this->agentVoiceCallsService->ringExpiryFailureMessage = 'store down';

        static::assertNull($this->agentVoiceCallsService->findOpenAppCallForAccountId(5));
        static::assertSame([[5]], $this->callStore->getArgumentsOf('findOpenCallOfAccount'));
    }

    public function testAMissingAccountHasNoOpenCallAndNothingIsRead(): void
    {
        static::assertNull($this->agentVoiceCallsService->findOpenAppCallForAccountId(5));
        static::assertSame(0, $this->agentVoiceCallsService->findOpenCallsOfAccountId(5)->count());
        static::assertSame(0, $this->agentVoiceCallsService->findCallsOfAccountId(5)->count());
        static::assertNull($this->agentVoiceCallsService->findCallOfAccountIdById(7, 5));
        static::assertSame([], $this->callStore->recordedCalls);
    }

    public function testTheListOfOpenCallsIsAPureReadWithoutRingExpiry(): void
    {
        $this->agentVoiceCallsService->accountsById[5] = $this->buildAccount(5);
        $this->callStore->callsOfAccount = [$this->buildRingingOutboundCall(7, 30)];

        static::assertSame(1, $this->agentVoiceCallsService->findOpenCallsOfAccountId(5)->count());
        static::assertSame(1, $this->agentVoiceCallsService->findCallsOfAccountId(5)->count());
        static::assertSame([[5, true], [5, false]], $this->callStore->getArgumentsOf('findCallsOfAccount'));
        static::assertSame(['findAccount:5', 'findAccount:5'], $this->agentVoiceCallsService->recordedReaches);
    }

    protected function buildCall(int $callId, ?int $conversationId, string $direction): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCall::newInstance();
        $agentVoiceCall->id = $callId;
        $agentVoiceCall->accountId = 5;
        $agentVoiceCall->aiConversationId = $conversationId;
        $agentVoiceCall->direction = $direction;

        return $agentVoiceCall;
    }

    protected function buildRingingOutboundCall(int $callId, ?int $conversationId): AgentVoiceCall
    {
        $agentVoiceCall = $this->buildCall($callId, $conversationId, AgentVoiceCall::DIRECTION_OUTBOUND);
        $agentVoiceCall->ringDeadlineAt = DateTime::fromTimestamp(time() + 60);
        $agentVoiceCall->startedByAgentInConversationId = 30;

        return $agentVoiceCall;
    }

    protected function buildStaleCall(int $callId, ?int $conversationId, ?string $providerCallId): AgentVoiceCall
    {
        $agentVoiceCall = $this->buildCall($callId, $conversationId, AgentVoiceCall::DIRECTION_INBOUND);
        $agentVoiceCall->startedAt = DateTime::fromTimestamp(time() - 3 * 3600);
        $agentVoiceCall->providerCallId = $providerCallId;

        return $agentVoiceCall;
    }

    protected function buildConversation(int $conversationId): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = $conversationId;
        $conversation->accountId = 5;
        $conversation->messages = new AIConversationMessages();

        return $conversation;
    }

    protected function buildAccount(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;

        return $account;
    }
}
