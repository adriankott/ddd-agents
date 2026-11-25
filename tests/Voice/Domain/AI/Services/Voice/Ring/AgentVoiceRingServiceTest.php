<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Conversations\AIConversationTurnStartListenersService;
use DDD\Domain\AI\Services\Voice\AgentVoiceRingService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\GoneException;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * The ring of a call on the owner's open clients (`agent.voice.ring` on the realtime port of the package,
 * contract section 6): the message and its payload field by field, the answer token, the one answer that wins, the
 * client that is remembered for the session open, the decline, the expiry of an overdue ring and the account-wide
 * expiry, and the turn start of a conversation.
 */
class AgentVoiceRingServiceTest extends AgentVoiceRingStagingTestCase
{
    public const string ANSWERING_CLIENT_ID = 'client-aaaa-0001';
    public const string OTHER_CLIENT_ID = 'client-bbbb-0002';

    public function testTheRingIsOneMessageOfTheRingTypeToTheAccountKeyedByTheCallIdAndExpiringAtTheDeadline(): void
    {
        $nowUnixTime = time();
        $agentVoiceCall = $this->storeRingingCall(501, $nowUnixTime, 90);

        static::assertTrue($this->getRingService()->publishRing($agentVoiceCall));

        static::assertCount(1, $this->realtimeChannel->publishedMessages);
        $ringMessage = $this->realtimeChannel->publishedMessages[0];
        static::assertSame('agent.voice.ring', $ringMessage->messageType);
        static::assertSame('501', $ringMessage->messageKey);
        static::assertSame($nowUnixTime + 90, $ringMessage->expiresAt->getTimestamp());
        static::assertSame(static::OWNER_ACCOUNT_ID, $ringMessage->audience->accountId);
        static::assertNull($ringMessage->audience->conversationId);
        static::assertSame('account-41', $ringMessage->audience->getMailboxName());
        static::assertNull($ringMessage->targetClientId);
        static::assertSame([501], array_keys($this->findRingPayloadsInMailbox()), 'the mailbox keeps it under the key 501');
    }

    public function testThePayloadCarriesTheFieldsOfTheContractAndNothingElse(): void
    {
        $nowUnixTime = time();
        $agentVoiceCall = $this->storeRingingCall(501, $nowUnixTime, 90);

        $this->getRingService()->publishRing($agentVoiceCall);

        $payloadJson = $this->realtimeChannel->publishedMessages[0]->payloadJson;
        $ringPayload = json_decode($payloadJson, true);
        static::assertSame(
            ['callId', 'conversationId', 'answerToken', 'ringExpiresAt', 'ringExpiresAtUnixMs', 'callerLabelKey', 'callerContext'],
            array_keys($ringPayload)
        );
        static::assertSame(501, $ringPayload['callId']);
        static::assertSame(300, $ringPayload['conversationId']);
        static::assertSame($this->getRingService()->mintAnswerToken($agentVoiceCall), $ringPayload['answerToken']);
        static::assertSame(gmdate('Y-m-d\TH:i:s', $nowUnixTime + 90) . '.000Z', $ringPayload['ringExpiresAt']);
        static::assertSame(($nowUnixTime + 90) * 1000, $ringPayload['ringExpiresAtUnixMs']);
        static::assertSame('%agentName% wants to talk', $ringPayload['callerLabelKey']);
        static::assertStringContainsString('"callerContext":{}', $payloadJson, 'an empty JSON object, not a list');
    }

    public function testACallWithoutAConversationRingsWithAConversationIdOfNull(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90, null);

        $this->getRingService()->publishRing($agentVoiceCall);

        static::assertNull(json_decode($this->realtimeChannel->publishedMessages[0]->payloadJson, true)['conversationId']);
    }

    public function testTheCallerLabelIsTheTextKeyNeverARenderedSentence(): void
    {
        $this->declareAgentPersonaConfiguration(['agentName' => 'Nova']);
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);

        $this->getRingService()->publishRing($agentVoiceCall);

        $payloadJson = $this->realtimeChannel->publishedMessages[0]->payloadJson;
        static::assertSame(AgentVoiceRingService::CALLER_LABEL_KEY_OF_AGENT_STARTED_CALL, json_decode($payloadJson, true)['callerLabelKey']);
        static::assertSame('%agentName% wants to talk', AgentVoiceRingService::CALLER_LABEL_KEY_OF_AGENT_STARTED_CALL);
        static::assertStringNotContainsString('Nova', $payloadJson);
    }

    public function testACallWithoutAnAccountRingsNobody(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90, 300, 0);

        static::assertFalse($this->getRingService()->publishRing($agentVoiceCall));
        static::assertSame([], $this->realtimeChannel->publishedMessages);
        static::assertSame([], $this->findRingPayloadsInMailbox(0));
    }

    public function testAFailingSendAnswersNotDeliveredAndNeverThrows(): void
    {
        $this->realtimeChannel->failingOperations = [VoiceRingRealtimeChannelServiceDouble::OPERATION_SEND];
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);

        static::assertFalse($this->getRingService()->publishRing($agentVoiceCall));
        static::assertSame(['AgentVoiceRingService: ring message of call 501 was not sent: send failed'], $this->logger->findMessagesContaining('AgentVoiceRingService'));
    }

    /** @param bool $hasTransport @param bool $hasSharedCache */
    #[DataProvider('provideReachabilityOfASentRing')]
    public function testTheRingIsDeliveredWhenTheTransportOrTheMailboxTakesIt(bool $hasTransport, bool $hasSharedCache, bool $expectedDelivered): void
    {
        $this->realtimeChannel->hasTransport = $hasTransport;
        $this->realtimeChannel->hasSharedCache = $hasSharedCache;
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);

        static::assertSame($expectedDelivered, $this->getRingService()->publishRing($agentVoiceCall));
    }

    /** @return array<string, array{bool, bool, bool}> */
    public static function provideReachabilityOfASentRing(): array
    {
        return [
            'transport and mailbox' => [true, true, true],
            'transport only' => [true, false, true],
            'mailbox only' => [false, true, true],
            'neither' => [false, false, false],
        ];
    }

    public function testAClearRemovesTheMessageFromTheMailboxAndTheTransport(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $this->getRingService()->publishRing($agentVoiceCall);

        $this->getRingService()->clearRing($agentVoiceCall);

        static::assertSame([], $this->findRingPayloadsInMailbox());
        static::assertSame(['agent.voice.ring|501'], $this->realtimeChannel->clearedMessages);
        static::assertSame([['audience' => 'account-41', 'messageType' => 'agent.voice.ring', 'messageKey' => '501']], $this->realtimeChannel->askedClears);
    }

    public function testACallWithoutIdOrAccountClearsNothingAndAFailingClearNeverThrows(): void
    {
        $callWithoutId = new AgentVoiceCall();
        $callWithoutId->accountId = static::OWNER_ACCOUNT_ID;
        $this->getRingService()->clearRing($callWithoutId);
        $this->getRingService()->clearRing($this->storeRingingCall(502, time(), 90, 300, 0));
        static::assertSame([], $this->realtimeChannel->askedClears);

        $this->realtimeChannel->failingOperations = [VoiceRingRealtimeChannelServiceDouble::OPERATION_CLEAR];
        $this->getRingService()->clearRing($this->storeRingingCall(503, time(), 90));
        static::assertCount(1, $this->realtimeChannel->askedClears);
    }

    public function testTheListenerReadAsksTheAccountAudienceAndFailsClosed(): void
    {
        $this->realtimeChannel->listeningByMailboxName['account-41'] = true;
        static::assertTrue($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
        static::assertSame('account-41', $this->realtimeChannel->listenerReadAudiences[0]->getMailboxName());
        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(static::OTHER_ACCOUNT_ID));

        $this->realtimeChannel->failingOperations = [VoiceRingRealtimeChannelServiceDouble::OPERATION_LISTENER_READ];
        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(static::OWNER_ACCOUNT_ID));
        static::assertSame(['AgentVoiceRingService: listener read failed for account 41: listener read failed'], $this->logger->findMessagesContaining('AgentVoiceRingService'));

        $listenerReadsBefore = count($this->realtimeChannel->listenerReadAudiences);
        static::assertFalse($this->getRingService()->isAnyClientOfAccountListening(null));
        static::assertCount($listenerReadsBefore, $this->realtimeChannel->listenerReadAudiences, 'no account: no read');
    }

    public function testTheRingTargetIsTheConversationsAccountElseTheRequestedOne(): void
    {
        $ringService = $this->getRingService();
        static::assertSame(43, $ringService->resolveRingTargetAccountId(static::buildConversation(300, 43), static::OWNER_ACCOUNT_ID));
        static::assertSame(static::OWNER_ACCOUNT_ID, $ringService->resolveRingTargetAccountId(static::buildConversation(300, null), static::OWNER_ACCOUNT_ID));
        static::assertSame(static::OWNER_ACCOUNT_ID, $ringService->resolveRingTargetAccountId(null, static::OWNER_ACCOUNT_ID));
        static::assertNull($ringService->resolveRingTargetAccountId(null, 0));
        static::assertNull($ringService->resolveRingTargetAccountId(null, null));
    }

    public function testAMintedTokenRedeemsForItsCallAndNamesTheDeadline(): void
    {
        $nowUnixTime = time();
        $agentVoiceCall = $this->storeRingingCall(501, $nowUnixTime, 90);

        $answerToken = $this->getRingService()->mintAnswerToken($agentVoiceCall);

        static::assertMatchesRegularExpression('/^' . ($nowUnixTime + 90) . '\.[0-9a-f]{64}$/', $answerToken);
        $this->getRingService()->requireRedeemableAnswerToken($agentVoiceCall, $answerToken);
        $this->getRingService()->requireRedeemableAnswerToken($agentVoiceCall, '  ' . $answerToken . ' ');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('provideTamperedTokens')]
    public function testATamperedTokenIsRefused(string $tokenChange): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $answerToken = $this->getRingService()->mintAnswerToken($agentVoiceCall);
        [$expiresUnixTime, $hmac] = explode('.', $answerToken);
        $tamperedToken = match ($tokenChange) {
            'no dot' => $expiresUnixTime . $hmac,
            'three segments' => '7.' . $answerToken,
            'a genuine token with a trailing segment' => $answerToken . '.' . $hmac,
            'an expiry that is no number' => 'soon.' . $hmac,
            'a changed signature' => $expiresUnixTime . '.' . strrev($hmac),
            'a later expiry with the old signature' => ((int)$expiresUnixTime + 3600) . '.' . $hmac,
            'an empty token' => '',
        };

        $this->expectExceptionWithWholeMessage(BadRequestException::class, 'invalid_answer_token');

        $this->getRingService()->requireRedeemableAnswerToken($agentVoiceCall, $tamperedToken);
    }

    /** @return array<string, array{string}> */
    public static function provideTamperedTokens(): array
    {
        return [
            'no dot' => ['no dot'],
            'three segments' => ['three segments'],
            'a genuine token with a trailing segment' => ['a genuine token with a trailing segment'],
            'an expiry that is no number' => ['an expiry that is no number'],
            'a changed signature' => ['a changed signature'],
            'a later expiry with the old signature' => ['a later expiry with the old signature'],
            'an empty token' => ['an empty token'],
        ];
    }

    public function testATokenOfAnotherCallIsRefused(): void
    {
        $nowUnixTime = time();
        $firstCall = $this->storeRingingCall(501, $nowUnixTime, 90);
        $secondCall = $this->storeRingingCall(502, $nowUnixTime, 90);

        $this->expectExceptionWithWholeMessage(BadRequestException::class, 'invalid_answer_token');

        $this->getRingService()->requireRedeemableAnswerToken($secondCall, $this->getRingService()->mintAnswerToken($firstCall));
    }

    public function testATokenPastTheDeadlineIsGone(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), -1);

        $this->expectExceptionWithWholeMessage(GoneException::class, 'ring_expired');

        $this->getRingService()->requireRedeemableAnswerToken($agentVoiceCall, $this->getRingService()->mintAnswerToken($agentVoiceCall));
    }

    public function testTheWinningAnswerClaimsTheCallClearsTheRingAndIsTraced(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $this->getRingService()->publishRing($agentVoiceCall);

        $this->getRingService()->answerRing($agentVoiceCall, $this->getRingService()->mintAnswerToken($agentVoiceCall), static::ANSWERING_CLIENT_ID);

        static::assertNotNull($agentVoiceCall->startedAt);
        static::assertNotNull($this->callStore->storedCallsById[501]->startedAt);
        static::assertSame([], $this->findRingPayloadsInMailbox());
        static::assertSame(['call.answer_claimed'], $this->traceEventsService->getRecordedEventTypes());
        $traceEvent = $this->traceEventsService->recordedWrites[0][0];
        static::assertSame('call:501:call.answer_claimed:' . static::ANSWERING_CLIENT_ID, $traceEvent->eventKey);
        static::assertSame(501, $traceEvent->agentVoiceCallId);
        static::assertSame(300, $traceEvent->aiConversationId);
        static::assertStringStartsWith('answer claimed by client ' . static::ANSWERING_CLIENT_ID . ' after ', (string)$traceEvent->summary);
    }

    public function testOfTwoAnswersExactlyOneWinsAndTheOtherIsAlreadyAnswered(): void
    {
        $this->storeRingingCall(501, time(), 90);
        $callOfTheFirstClient = $this->callStore->loadCopyOfCall(501);
        $callOfTheSecondClient = $this->callStore->loadCopyOfCall(501);
        $answerToken = $this->getRingService()->mintAnswerToken($callOfTheFirstClient);

        $this->getRingService()->answerRing($callOfTheFirstClient, $answerToken, static::ANSWERING_CLIENT_ID);
        try {
            $this->getRingService()->answerRing($callOfTheSecondClient, $answerToken, static::OTHER_CLIENT_ID);
            static::fail('the second answer must lose');
        } catch (ConflictException $conflictException) {
            static::assertSame('already_answered', $conflictException->getMessage());
        }

        static::assertSame(['claimRingingCallAnswer', 'claimRingingCallAnswer'], $this->callStore->getRecordedMethodNames());
        static::assertSame(static::ANSWERING_CLIENT_ID, $this->getRingService()->findAnsweringClientIdOfCall($callOfTheFirstClient));
        static::assertSame(['call.answer_claimed'], $this->traceEventsService->getRecordedEventTypes(), 'the loser writes no trace');
    }

    public function testAnAnswerAfterTheDeadlineIsGone(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), -1);

        $this->expectExceptionWithWholeMessage(GoneException::class, 'ring_expired');

        $this->getRingService()->answerRing($agentVoiceCall, $this->getRingService()->mintAnswerToken($agentVoiceCall), static::ANSWERING_CLIENT_ID);
    }

    public function testAClaimLostBecauseTheDeadlinePassedAfterTheTokenCheckIsGone(): void
    {
        $nowUnixTime = time();
        $agentVoiceCall = $this->storeRingingCall(501, $nowUnixTime, 60);
        $answerToken = $this->getRingService()->mintAnswerToken($agentVoiceCall);
        $agentVoiceCall->ringDeadlineAt = DateTime::fromTimestamp($nowUnixTime - 1);

        try {
            $this->getRingService()->answerRing($agentVoiceCall, $answerToken, static::ANSWERING_CLIENT_ID);
            static::fail('the claim must be lost');
        } catch (GoneException $goneException) {
            static::assertSame('ring_expired', $goneException->getMessage());
        }
        static::assertSame(['claimRingingCallAnswer'], $this->callStore->getRecordedMethodNames());
        static::assertNull($agentVoiceCall->startedAt);
    }

    public function testAnAnswerOfADeclinedCallIsAlreadyAnswered(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $answerToken = $this->getRingService()->mintAnswerToken($agentVoiceCall);
        $this->getRingService()->declineRing($agentVoiceCall, $answerToken);

        $this->expectExceptionWithWholeMessage(ConflictException::class, 'already_answered');

        $this->getRingService()->answerRing($this->callStore->loadCopyOfCall(501), $answerToken, static::ANSWERING_CLIENT_ID);
    }

    public function testATamperedTokenNeverReachesTheClaim(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);

        try {
            $this->getRingService()->answerRing($agentVoiceCall, '1.abc', static::ANSWERING_CLIENT_ID);
            static::fail('a forged token must be refused');
        } catch (BadRequestException $badRequestException) {
            static::assertSame('invalid_answer_token', $badRequestException->getMessage());
        }
        static::assertSame([], $this->callStore->getRecordedMethodNames());
    }

    public function testATraceThatCannotBeWrittenNeverFailsTheAnswer(): void
    {
        $this->traceEventsService->writeFailureMessage = 'trace table gone';
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);

        $this->getRingService()->answerRing($agentVoiceCall, $this->getRingService()->mintAnswerToken($agentVoiceCall), static::ANSWERING_CLIENT_ID);

        static::assertNotNull($agentVoiceCall->startedAt);
    }

    public function testTheAnsweringClientIsRememberedInTheSharedCacheFor300Seconds(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);

        $this->getRingService()->answerRing($agentVoiceCall, $this->getRingService()->mintAnswerToken($agentVoiceCall), static::ANSWERING_CLIENT_ID);

        static::assertSame(static::ANSWERING_CLIENT_ID, $this->sharedCache->valuesByKey['agent-voice-answering-client-501']);
        static::assertSame(300, $this->sharedCache->ttlSecondsByKey['agent-voice-answering-client-501']);
        static::assertSame(static::ANSWERING_CLIENT_ID, $this->getRingService()->findAnsweringClientIdOfCall($agentVoiceCall));
    }

    public function testOnlyTheAnsweringClientMayOpenWhileTheEntryIsThere(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $ringService = $this->getRingService();
        static::assertFalse($ringService->isCallAnsweredByClient($agentVoiceCall, static::ANSWERING_CLIENT_ID), 'not answered yet');
        static::assertFalse($ringService->isCallAnsweredByClient($agentVoiceCall, null), 'not answered yet');

        $ringService->answerRing($agentVoiceCall, $ringService->mintAnswerToken($agentVoiceCall), static::ANSWERING_CLIENT_ID);

        static::assertTrue($ringService->isCallAnsweredByClient($agentVoiceCall, static::ANSWERING_CLIENT_ID));
        static::assertFalse($ringService->isCallAnsweredByClient($agentVoiceCall, static::OTHER_CLIENT_ID));
        static::assertTrue($ringService->isCallAnsweredByClient($agentVoiceCall, null), 'an open without a client id is not told apart');
    }

    public function testOnceTheEntryIsGoneEveryClientOfTheCallMayOpen(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $ringService = $this->getRingService();
        $ringService->answerRing($agentVoiceCall, $ringService->mintAnswerToken($agentVoiceCall), static::ANSWERING_CLIENT_ID);

        $this->sharedCache->delete('agent-voice-answering-client-501');

        static::assertNull($ringService->findAnsweringClientIdOfCall($agentVoiceCall));
        static::assertTrue($ringService->isCallAnsweredByClient($agentVoiceCall, static::OTHER_CLIENT_ID));
        static::assertTrue($ringService->isCallAnsweredByClient($agentVoiceCall, static::ANSWERING_CLIENT_ID));
    }

    #[DataProvider('provideAnswersWithoutARememberedClient')]
    public function testAnAnswerThatLeavesNoEntryLetsEveryClientOpen(?string $answeringClientId, bool $hasSharedCache, bool $cacheFails): void
    {
        $this->realtimeChannel->hasSharedCache = $hasSharedCache;
        $this->sharedCache->failsEveryCall = $cacheFails;
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $ringService = $this->getRingService();

        $ringService->answerRing($agentVoiceCall, $ringService->mintAnswerToken($agentVoiceCall), $answeringClientId);

        static::assertArrayNotHasKey('agent-voice-answering-client-501', $this->sharedCache->valuesByKey);
        static::assertNull($ringService->findAnsweringClientIdOfCall($agentVoiceCall));
        static::assertTrue($ringService->isCallAnsweredByClient($agentVoiceCall, static::OTHER_CLIENT_ID));
        $expectedWarnings = $cacheFails
            ? [
                'AgentVoiceRingService: answering client of call 501 not remembered: cache unavailable',
                'AgentVoiceRingService: answering client of call 501 not read: cache unavailable',
                'AgentVoiceRingService: answering client of call 501 not read: cache unavailable',
            ]
            : [];
        static::assertSame($expectedWarnings, $this->logger->findMessagesContaining('answering client'));
    }

    public function testAFailingCacheOfAnyKindNeverFailsTheAnswerOrTheOpen(): void
    {
        $this->sharedCache->failsWithRuntimeException = true;
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $ringService = $this->getRingService();

        $ringService->answerRing($agentVoiceCall, $ringService->mintAnswerToken($agentVoiceCall), static::ANSWERING_CLIENT_ID);

        static::assertNotNull($agentVoiceCall->startedAt);
        static::assertTrue($ringService->isCallAnsweredByClient($agentVoiceCall, static::OTHER_CLIENT_ID));
        static::assertSame(
            [
                'AgentVoiceRingService: answering client of call 501 not remembered: cache failed',
                'AgentVoiceRingService: answering client of call 501 not read: cache failed',
            ],
            $this->logger->findMessagesContaining('answering client')
        );
    }

    /** @return array<string, array{?string, bool, bool}> */
    public static function provideAnswersWithoutARememberedClient(): array
    {
        return [
            'the answer stated no client id' => [null, true, false],
            'the client id has not the shape of one' => ['short', true, false],
            'no shared cache' => [AgentVoiceRingServiceTest::ANSWERING_CLIENT_ID, false, false],
            'the shared cache fails' => [AgentVoiceRingServiceTest::ANSWERING_CLIENT_ID, true, true],
        ];
    }

    public function testADeclineEndsTheCallDeclinedOnceAndStopsTheRing(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $answerToken = $this->getRingService()->mintAnswerToken($agentVoiceCall);
        $this->getRingService()->publishRing($agentVoiceCall);
        $callOfASecondClient = $this->callStore->loadCopyOfCall(501);

        $this->getRingService()->declineRing($agentVoiceCall, $answerToken);
        $this->getRingService()->declineRing($callOfASecondClient, $answerToken);
        $this->getRingService()->declineRing($agentVoiceCall, $answerToken);

        static::assertSame(AgentVoiceCall::END_REASON_DECLINED, $this->callStore->storedCallsById[501]->endReason);
        static::assertSame([['callId' => 501, 'callEnded' => true, 'endReason' => 'DECLINED', 'rightsApplied' => true]], $this->callsService->recordedLifecycleMarkers);
        static::assertSame([501], $this->callsService->recordedFollowUpCallIds, 'the follow-ups service decides that a decline gets no turn');
        static::assertSame([], $this->findRingPayloadsInMailbox());
        static::assertSame(['claimUnansweredCallEnd', 'markCallEnded', 'claimUnansweredCallEnd'], $this->callStore->getRecordedMethodNames());
    }

    public function testADeclineOfAnAnsweredCallChangesNothing(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);
        $answerToken = $this->getRingService()->mintAnswerToken($agentVoiceCall);
        $this->getRingService()->answerRing($agentVoiceCall, $answerToken, static::ANSWERING_CLIENT_ID);
        $askedClearsAfterAnswer = count($this->realtimeChannel->askedClears);

        $this->getRingService()->declineRing($agentVoiceCall, $answerToken);

        static::assertNull($agentVoiceCall->endedAt);
        static::assertSame([], $this->callsService->recordedLifecycleMarkers);
        static::assertCount($askedClearsAfterAnswer, $this->realtimeChannel->askedClears);
    }

    public function testADeclineWithAForgedTokenWritesNothing(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 90);

        try {
            $this->getRingService()->declineRing($agentVoiceCall, '1.abc');
            static::fail('a forged token must be refused');
        } catch (BadRequestException $badRequestException) {
            static::assertSame('invalid_answer_token', $badRequestException->getMessage());
        }
        static::assertSame([], $this->callStore->getRecordedMethodNames());
        static::assertSame([], $this->realtimeChannel->askedClears);
    }

    public function testADeclineAfterTheDeadlineIsGone(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), -1);

        $this->expectExceptionWithWholeMessage(GoneException::class, 'ring_expired');

        $this->getRingService()->declineRing($agentVoiceCall, $this->getRingService()->mintAnswerToken($agentVoiceCall));
    }

    public function testAnOverdueRingEndsExpiredOnceAndItsMessageIsCleared(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), -5);

        static::assertTrue($this->getRingService()->expireOverdueRingOfCall($agentVoiceCall));
        static::assertFalse($this->getRingService()->expireOverdueRingOfCall($agentVoiceCall), 'ended: no longer overdue');

        static::assertSame(AgentVoiceCall::END_REASON_EXPIRED, $agentVoiceCall->endReason);
        static::assertSame([['callId' => 501, 'callEnded' => true, 'endReason' => 'EXPIRED', 'rightsApplied' => true]], $this->callsService->recordedLifecycleMarkers);
        static::assertSame([501], $this->callsService->recordedFollowUpCallIds);
        static::assertSame([['audience' => 'account-41', 'messageType' => 'agent.voice.ring', 'messageKey' => '501']], $this->realtimeChannel->askedClears);
    }

    public function testTwoLooksAtTheSameOverdueRingWriteTheEndOnce(): void
    {
        $this->storeRingingCall(501, time(), -5);
        $callOfTheDeadlineMessage = $this->callStore->loadCopyOfCall(501);
        $callOfTheTurnStart = $this->callStore->loadCopyOfCall(501);

        static::assertTrue($this->getRingService()->expireOverdueRingOfCall($callOfTheDeadlineMessage));
        static::assertTrue($this->getRingService()->expireOverdueRingOfCall($callOfTheTurnStart), 'overdue in its own object: counted, not written');

        static::assertCount(1, $this->callsService->recordedLifecycleMarkers);
        static::assertSame([501], $this->callsService->recordedFollowUpCallIds);
        static::assertCount(2, $this->realtimeChannel->askedClears, 'the message is removed either way');
    }

    /** @param int|null $startedOffsetSeconds @param int|null $endedOffsetSeconds */
    #[DataProvider('provideRingsThatAreNotOverdue')]
    public function testARingThatIsNotOverdueIsLeftAlone(int $ringDeadlineOffsetSeconds, ?int $startedOffsetSeconds, ?int $endedOffsetSeconds): void
    {
        $nowUnixTime = time();
        $agentVoiceCall = $this->storeRingingCall(501, $nowUnixTime, $ringDeadlineOffsetSeconds);
        $agentVoiceCall->startedAt = $startedOffsetSeconds !== null ? DateTime::fromTimestamp($nowUnixTime + $startedOffsetSeconds) : null;
        $agentVoiceCall->endedAt = $endedOffsetSeconds !== null ? DateTime::fromTimestamp($nowUnixTime + $endedOffsetSeconds) : null;

        static::assertFalse($this->getRingService()->expireOverdueRingOfCall($agentVoiceCall));

        static::assertSame([], $this->callStore->getRecordedMethodNames());
        static::assertSame([], $this->realtimeChannel->askedClears);
    }

    /** @return array<string, array{int, ?int, ?int}> */
    public static function provideRingsThatAreNotOverdue(): array
    {
        return [
            'still inside the ring window' => [60, null, null],
            'answered, then the deadline passed' => [-5, -30, null],
            'ended before the deadline' => [-5, null, -30],
        ];
    }

    public function testARingIsOverdueAtTheSecondOfItsDeadline(): void
    {
        $agentVoiceCall = $this->storeRingingCall(501, time(), 0);

        static::assertTrue($this->getRingService()->expireOverdueRingOfCall($agentVoiceCall));
    }

    public function testACallWithoutIdOrWithoutDeadlineIsNeverOverdue(): void
    {
        $callWithoutDeadline = $this->storeRingingCall(501, time(), -5);
        $callWithoutDeadline->ringDeadlineAt = null;
        $callWithoutId = new AgentVoiceCall();
        $callWithoutId->ringDeadlineAt = DateTime::fromTimestamp(time() - 5);

        static::assertFalse($this->getRingService()->expireOverdueRingOfCall($callWithoutDeadline));
        static::assertFalse($this->getRingService()->expireOverdueRingOfCall($callWithoutId));
    }

    public function testTheAccountWideExpiryEndsEveryOverdueRingOfTheAccountAndCountsThem(): void
    {
        $nowUnixTime = time();
        $this->storeRingingCall(501, $nowUnixTime, -60);
        $this->storeRingingCall(502, $nowUnixTime, -1);
        $this->storeRingingCall(503, $nowUnixTime, 60);
        $this->storeRingingCall(504, $nowUnixTime, -60, 301, static::OTHER_ACCOUNT_ID);
        $answeredCall = $this->storeRingingCall(505, $nowUnixTime, -60);
        $answeredCall->startedAt = DateTime::fromTimestamp($nowUnixTime - 70);

        $endedRingCount = $this->getRingService()->expireOverdueRingsOfAccount(static::buildAccount(static::OWNER_ACCOUNT_ID));

        static::assertSame(2, $endedRingCount);
        static::assertSame([502, 501], array_column($this->callsService->recordedLifecycleMarkers, 'callId'), 'newest first, as the store answers');
        static::assertSame(['EXPIRED', 'EXPIRED'], array_column($this->callsService->recordedLifecycleMarkers, 'endReason'));
        static::assertNull($this->callStore->storedCallsById[503]->endedAt);
        static::assertNull($this->callStore->storedCallsById[504]->endedAt);
        static::assertNull($this->callStore->storedCallsById[505]->endedAt);
        static::assertSame(['findUnansweredCallsOfAccountPastRingDeadline', 'claimUnansweredCallEnd', 'markCallEnded', 'claimUnansweredCallEnd', 'markCallEnded'], $this->callStore->getRecordedMethodNames());
        static::assertSame(41, $this->callStore->recordedCalls[0]['subject']);
        static::assertFalse($this->callStore->recordedCalls[0]['rightsApplied'], 'the read runs with the rights lifted');
        static::assertSame([false, false], array_column($this->callsService->recordedLifecycleMarkers, 'rightsApplied'), 'the marker too');
        static::assertEqualsWithDelta($nowUnixTime, $this->callStore->pastDeadlineReadTimes[0], 2);
        static::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights are back');
        static::assertSame(0, $this->getRingService()->expireOverdueRingsOfAccount(static::buildAccount(static::OWNER_ACCOUNT_ID)));
    }

    public function testAnAccountWithoutIdExpiresNothing(): void
    {
        static::assertSame(0, $this->getRingService()->expireOverdueRingsOfAccount(Account::newInstance()));
        static::assertSame([], $this->callStore->getRecordedMethodNames());
    }

    public function testAFailingReadOfTheAccountWideExpiryThrowsWithTheRightsRestored(): void
    {
        $this->callStore->failingMethodNames = ['findUnansweredCallsOfAccountPastRingDeadline'];

        try {
            $this->getRingService()->expireOverdueRingsOfAccount(static::buildAccount(static::OWNER_ACCOUNT_ID));
            static::fail('the expiry may throw; its callers catch');
        } catch (RuntimeException $runtimeException) {
            static::assertSame('findUnansweredCallsOfAccountPastRingDeadline failed', $runtimeException->getMessage());
        }
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testTheTurnStartOfAConversationExpiresItsOverdueRings(): void
    {
        $this->storeRingingCall(501, time(), -5);
        $this->storeRingingCall(502, time(), -5, 301);

        static::assertTrue($this->getRingService()->beforeTurnStarts(static::buildConversation(300)));
        static::assertFalse($this->getRingService()->beforeTurnStarts(static::buildConversation(300)), 'nothing left to end');

        static::assertSame([501], array_column($this->callsService->recordedLifecycleMarkers, 'callId'));
        static::assertNull($this->callStore->storedCallsById[502]->endedAt);
        static::assertSame('findCallsOfConversation', $this->callStore->recordedCalls[0]['method']);
        static::assertSame(300, $this->callStore->recordedCalls[0]['subject']);
        static::assertFalse($this->callStore->recordedCalls[0]['rightsApplied'], 'the read runs with the rights lifted');
        static::assertSame([false], array_column($this->callsService->recordedLifecycleMarkers, 'rightsApplied'), 'the marker too');
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testTheTurnStartOfADelegationExpiresNothing(): void
    {
        $this->storeRingingCall(501, time(), -5);
        $delegationConversation = static::buildConversation(300);
        $delegationConversation->delegatedFromMessageId = 77;

        static::assertFalse($this->getRingService()->beforeTurnStarts($delegationConversation));

        static::assertSame([], $this->callStore->getRecordedMethodNames());
        static::assertNull($this->callStore->storedCallsById[501]->endedAt);
    }

    public function testTheTurnStartDoesNothingWhileDisabledOrForATransientConversation(): void
    {
        $this->storeRingingCall(501, time(), -5);
        $transientConversation = static::buildConversation(300);
        unset($transientConversation->id);

        static::assertFalse($this->getRingService()->beforeTurnStarts($transientConversation));
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));
        static::assertFalse($this->getRingService()->beforeTurnStarts(static::buildConversation(300)));

        static::assertSame([], $this->callStore->getRecordedMethodNames());
    }

    public function testTheRingServiceIsATurnStartListenerOfTheKernel(): void
    {
        /** @var AIConversationTurnStartListenersService $aIConversationTurnStartListenersService */
        $aIConversationTurnStartListenersService = DDDService::instance()->getService(AIConversationTurnStartListenersService::class);
        $listenerClassNames = [];
        foreach (static::getPropertyValueOf($aIConversationTurnStartListenersService, 'listeners') as $turnStartListener) {
            $listenerClassNames[] = $turnStartListener::class;
        }

        static::assertContains(AgentVoiceRingService::class, $listenerClassNames);
    }

    protected static function getPropertyValueOf(object $object, string $propertyName): mixed
    {
        $reflectionProperty = new ReflectionProperty($object, $propertyName);
        return $reflectionProperty->getValue($object);
    }

    /** @param class-string<Throwable> $exceptionClassName */
    protected function expectExceptionWithWholeMessage(string $exceptionClassName, string $exceptionMessage): void
    {
        $this->expectException($exceptionClassName);
        $this->expectExceptionMessageMatches('/^' . preg_quote($exceptionMessage, '/') . '$/');
    }
}
