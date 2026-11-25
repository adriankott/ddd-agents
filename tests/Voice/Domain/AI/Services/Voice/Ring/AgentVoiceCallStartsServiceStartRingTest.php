<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Exceptions\Evals\EvalOutboundRecipientBlockedException;
use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceMessengerTransports;
use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceRingDeadlineMessage;
use DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * {@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService::startRing()}: the admission first, then the lazy
 * expiry of the account's overdue rings (whose failure never stops the ring), the eval guard, the reachable ring
 * targets; then ONE outbound call, rung on the open clients, with the delayed deadline look; a ring that reached no
 * target ends the call FAILED at once.
 */
class AgentVoiceCallStartsServiceStartRingTest extends AgentVoiceCallStartsStagingTestCase
{
    #[DataProvider('provideAdmissionCodes')]
    public function testARefusedAdmissionThrowsItsCodeBeforeAnythingElse(string $admissionCode): void
    {
        $this->admissionService->refusalCode = $admissionCode;
        $this->evalOutboundRecipientsService->expects(static::never())->method('checkDeviceRecipient');

        try {
            $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), 'the task');
            static::fail('the admission refuses');
        } catch (ForbiddenException $forbiddenException) {
            static::assertSame($admissionCode, $forbiddenException->getMessage());
        }

        static::assertSame([], $this->callStore->getRecordedMethodNames(), 'no expiry read, no call');
        static::assertSame([], $this->realtimeChannel->listenerReadAudiences);
        static::assertSame([['accountId' => 41, 'agentSpace' => null, 'conversationId' => 300]], $this->admissionService->askedCallStarts);
    }

    /** @return array<string, array{string}> */
    public static function provideAdmissionCodes(): array
    {
        return [
            'feature_not_available' => ['feature_not_available'],
            'no_ai_budget' => ['no_ai_budget'],
            'daily_limit_reached' => ['daily_limit_reached'],
        ];
    }

    public function testTheOverdueRingsOfTheAccountExpireBeforeTheNewRing(): void
    {
        $this->storeRingingCall(400, time(), -10);

        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        static::assertSame(AgentVoiceCall::END_REASON_EXPIRED, $this->callStore->storedCallsById[400]->endReason);
        static::assertSame(['findUnansweredCallsOfAccountPastRingDeadline', 'claimUnansweredCallEnd', 'markCallEnded', 'createCall'], $this->callStore->getRecordedMethodNames());
        static::assertSame(501, $agentVoiceCall->id);
    }

    public function testAFailingExpiryNeverStopsTheRing(): void
    {
        $this->callStore->failingMethodNames = ['findUnansweredCallsOfAccountPastRingDeadline'];

        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        static::assertSame(['findUnansweredCallsOfAccountPastRingDeadline', 'createCall'], $this->callStore->getRecordedMethodNames());
        static::assertNull($agentVoiceCall->endedAt);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testAnEvalRunNeverRingsAndTheGuardIsAskedForTheOwner(): void
    {
        $this->evalOutboundRecipientsService->expects(static::once())->method('isConversationOfAnEvalRunTree')->willReturn(true);
        $this->evalOutboundRecipientsService->expects(static::once())->method('checkDeviceRecipient')
            ->with(EvalOutboundRecipientsService::CHANNEL_APP_CALL_RING, static::OWNER_ACCOUNT_ID, null, true);
        $this->evalOutboundRecipientsService->method('throwIfBlocked')->willThrowException(new EvalOutboundRecipientBlockedException('a ring has no test sink'));

        try {
            $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));
            static::fail('an eval run never rings');
        } catch (EvalOutboundRecipientBlockedException $evalOutboundRecipientBlockedException) {
            static::assertSame('a ring has no test sink', $evalOutboundRecipientBlockedException->getMessage());
        }

        static::assertSame(['findUnansweredCallsOfAccountPastRingDeadline'], $this->callStore->getRecordedMethodNames(), 'the expiry ran first, no call');
        static::assertSame([], $this->realtimeChannel->listenerReadAudiences);
    }

    public function testARingThatNoTargetCanTakeIsRefusedWithoutACall(): void
    {
        $this->realtimeChannel->listeningByMailboxName = [];

        try {
            $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));
            static::fail('nothing can ring');
        } catch (BadRequestException $badRequestException) {
            static::assertSame('no_registered_devices', $badRequestException->getMessage());
        }

        static::assertNotContains('createCall', $this->callStore->getRecordedMethodNames());
        static::assertSame([], $this->messageBus->dispatchedMessages);
    }

    public function testTheRingCreatesOneOutboundCallOfTheRungAccountAndRingsIt(): void
    {
        $nowUnixTime = time();

        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), '  ask about the opening hours  ');

        static::assertSame(501, $agentVoiceCall->id);
        static::assertSame(AgentVoiceCall::DIRECTION_OUTBOUND, $agentVoiceCall->direction);
        static::assertSame(static::OWNER_ACCOUNT_ID, $agentVoiceCall->accountId);
        static::assertSame(300, $agentVoiceCall->aiConversationId);
        static::assertSame('ask about the opening hours', $agentVoiceCall->callTask);
        static::assertEqualsWithDelta($nowUnixTime + 90, $agentVoiceCall->ringDeadlineAt->getTimestamp(), 1);
        static::assertNull($agentVoiceCall->startedAt);
        static::assertNull($agentVoiceCall->endedAt);
        static::assertSame(['findUnansweredCallsOfAccountPastRingDeadline', 'createCall'], $this->callStore->getRecordedMethodNames());
        static::assertSame([501], array_keys($this->findRingPayloadsInMailbox()));
        static::assertSame(['account-41'], array_map([static::class, 'getMailboxNameOf'], $this->realtimeChannel->listenerReadAudiences), 'one reachability read');
        static::assertSame(['ring.browser_tab_ring_written', 'call.ring_started'], $this->traceEventsService->getRecordedEventTypes());
        static::assertCount(1, $this->traceEventsService->recordedWrites, 'one batched write');
        static::assertSame('1 of 1 devices rung', $this->traceEventsService->recordedWrites[0][1]->summary);
    }

    public function testTheDeadlineMessageIsDelayedByTheRingSecondsPlusFiveToItsTransport(): void
    {
        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));
        $secondsUntilDeadline = $agentVoiceCall->ringDeadlineAt->getTimestamp() - time();

        static::assertCount(1, $this->messageBus->dispatchedMessages);
        $dispatchedMessage = $this->messageBus->dispatchedMessages[0];
        static::assertInstanceOf(AgentVoiceRingDeadlineMessage::class, $dispatchedMessage['message']);
        static::assertSame(501, $dispatchedMessage['message']->agentVoiceCallId);
        static::assertCount(1, $dispatchedMessage['stamps']);
        static::assertInstanceOf(DelayStamp::class, $dispatchedMessage['stamps'][0]);
        static::assertSame(90, $secondsUntilDeadline);
        static::assertSame((90 + 5) * 1000, $dispatchedMessage['stamps'][0]->getDelay());
        static::assertSame(AgentVoiceMessengerTransports::RING_DEADLINE, AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS[AgentVoiceRingDeadlineMessage::class]);
    }

    public function testTheDelayFollowsTheConfiguredRingLength(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true, 'callStore' => 'entity', 'limits' => ['ringSeconds' => 30]]));

        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        static::assertSame(30, $agentVoiceCall->ringDeadlineAt->getTimestamp() - time());
        static::assertSame(35000, $this->messageBus->dispatchedMessages[0]['stamps'][0]->getDelay());
    }

    public function testARingTargetTheBaseDoesNotKnowIsNeitherReachableNorRung(): void
    {
        $agentVoiceCallStartsService = new FurtherRingTargetAgentVoiceCallStartsServiceDouble();

        static::assertSame(['openClients'], $agentVoiceCallStartsService->callFindReachableRingTargetNames(static::buildAccount(static::OWNER_ACCOUNT_ID), null));
        static::assertSame(['account-41'], array_map([static::class, 'getMailboxNameOf'], $this->realtimeChannel->listenerReadAudiences), 'only the open clients are read');

        $this->realtimeChannel->listeningByMailboxName['account-' . static::OWNER_ACCOUNT_ID] = false;
        static::assertSame([], $agentVoiceCallStartsService->callFindReachableRingTargetNames(static::buildAccount(static::OWNER_ACCOUNT_ID), null));
    }

    public function testARingThatReachedNoTargetEndsTheCallFailedWithoutADeadlineLook(): void
    {
        $this->realtimeChannel->failingOperations = [VoiceRingRealtimeChannelServiceDouble::OPERATION_SEND];

        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        static::assertSame(AgentVoiceCall::END_REASON_FAILED, $agentVoiceCall->endReason);
        static::assertNotNull($agentVoiceCall->endedAt);
        static::assertSame(['findUnansweredCallsOfAccountPastRingDeadline', 'createCall', 'markCallEnded'], $this->callStore->getRecordedMethodNames());
        static::assertSame([], $this->messageBus->dispatchedMessages);
        static::assertSame('0 of 1 devices rung', $this->traceEventsService->recordedWrites[0][1]->summary);
    }

    public function testALostDeadlineDispatchNeverFailsTheRing(): void
    {
        $this->messageBus->dispatchFailureMessage = 'broker down';

        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        static::assertNull($agentVoiceCall->endedAt);
        static::assertSame([501], array_keys($this->findRingPayloadsInMailbox()));
    }

    public function testARingWithoutAConversationCreatesOneForTheOwner(): void
    {
        $this->callsService->conversationCreatedForRing = static::buildConversation(310);

        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, null, '   ');

        static::assertSame([['accountId' => 41, 'agentSpace' => null, 'callId' => null]], $this->callsService->recordedConversationCreations);
        static::assertSame(310, $agentVoiceCall->aiConversationId);
        static::assertNull($agentVoiceCall->callTask, 'a blank task is no task');
    }

    public function testARingWhoseConversationCannotBeCreatedRingsWithoutOne(): void
    {
        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, null);

        static::assertNull($agentVoiceCall->aiConversationId);
        static::assertNull($agentVoiceCall->endedAt);
    }

    public function testTheCallBelongsToTheAccountOfItsConversation(): void
    {
        $this->realtimeChannel->listeningByMailboxName['account-43'] = true;

        $agentVoiceCall = $this->getCallStartsService()->startRing(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300, 43));

        static::assertSame(43, $agentVoiceCall->accountId);
        static::assertSame(['account-43'], array_map([static::class, 'getMailboxNameOf'], $this->realtimeChannel->listenerReadAudiences));
        static::assertSame([501], array_keys($this->findRingPayloadsInMailbox(43)));
        static::assertSame(41, $this->callStore->recordedCalls[0]['subject'], 'the expiry reads the asked owner');
    }
}
