<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Domain\AI\Exceptions\Evals\EvalOutboundRecipientBlockedException;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * {@see AgentVoiceCallStartsService::startVoiceCall()}, the agent's call to the owner: the refusals in their order
 * (automation, purpose, already on a call, the medium), each admission code mapped to its refusal, the ring that
 * reached no target, the conversation the ring runs on, and the stamp of a started call as the agent's own.
 */
class AgentVoiceCallStartsServiceStartVoiceCallTest extends AgentVoiceCallStartsStagingTestCase
{
    public const string CALLABLE_PURPOSE_NAME = 'Call.Check';

    public function testAnAccountThatTakesNoAutomationGetsNoCallBeforeAnyOtherCheck(): void
    {
        $this->eligibilityService->expects(static::once())->method('refusesAutomationForAccountId')
            ->with(static::OWNER_ACCOUNT_ID, AgentVoiceCallStartsService::AUTOMATION_OPERATION_NAME)->willReturn(true);
        $this->storeRunningCallOnConversation(300);

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, 'task', 'Any.Purpose');

        static::assertSame(AgentVoiceCallStart::REFUSED_SCOPE_NOT_ACTIVE, $agentVoiceCallStart->refusalReason);
        static::assertFalse($agentVoiceCallStart->appIsIncluded);
        static::assertSame([], $this->callStore->getRecordedMethodNames());
        static::assertSame([], $this->admissionService->askedCallStarts);
    }

    public function testAPurposeForTheCurrentConversationIsRefusedBeforeTheOnACallCheck(): void
    {
        $this->storeRunningCallOnConversation(300);

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, null, AIConversationPurpose::SCHEDULE_RUN);

        static::assertSame(AgentVoiceCallStart::REFUSED_PURPOSE_NEEDS_NEW_CONVERSATION, $agentVoiceCallStart->refusalReason);
        static::assertSame([], $this->callStore->getRecordedMethodNames());
    }

    public function testAPurposeWithoutASpokenTaskIsRefusedWithTheCallablePurposes(): void
    {
        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), true, null, AIConversationPurpose::SCHEDULE_RUN);

        static::assertSame(AgentVoiceCallStart::REFUSED_PURPOSE_NOT_CALLABLE, $agentVoiceCallStart->refusalReason);
        static::assertSame([], $agentVoiceCallStart->callablePurposeNames, 'no registered purpose of the kernel declares a spoken task');
        static::assertSame([], $this->callStore->getRecordedMethodNames());
    }

    public function testTheRefusalOfAPurposeNamesTheCallableOnes(): void
    {
        $agentVoiceCallStartsService = $this->buildPurposeStagingService();

        $agentVoiceCallStart = $agentVoiceCallStartsService->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, null, true, null, 'Not.Callable');

        static::assertSame(AgentVoiceCallStart::REFUSED_PURPOSE_NOT_CALLABLE, $agentVoiceCallStart->refusalReason);
        static::assertSame([static::CALLABLE_PURPOSE_NAME], $agentVoiceCallStart->callablePurposeNames);
    }

    public function testARunningCallOnTheCurrentConversationIsAlreadyOnACall(): void
    {
        $this->storeRunningCallOnConversation(300);

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), true, 'task');

        static::assertSame(AgentVoiceCallStart::REFUSED_ALREADY_ON_A_CALL, $agentVoiceCallStart->refusalReason);
        static::assertNotContains('createCall', $this->callStore->getRecordedMethodNames());
        static::assertSame([], $this->admissionService->askedCallStarts, 'the medium is not asked');
        static::assertFalse($this->callStore->recordedCalls[0]['rightsApplied'], 'read with the rights lifted');
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testAnOpenCallOfTheAccountElsewhereIsAlreadyOnACall(): void
    {
        $this->storeRingingCall(400, time(), 60, 399);

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, 'task');

        static::assertSame(AgentVoiceCallStart::REFUSED_ALREADY_ON_A_CALL, $agentVoiceCallStart->refusalReason);
        static::assertSame(['findRunningCallOfConversation', 'findUnansweredCallsOfAccountPastRingDeadline', 'findOpenCallOfAccount'], $this->callStore->getRecordedMethodNames());
    }

    public function testAnOverdueRingOfTheAccountIsNoCallAnyMore(): void
    {
        $this->storeRingingCall(400, time(), -10, 399);

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, 'task');

        static::assertFalse($agentVoiceCallStart->isRefused());
        static::assertSame('EXPIRED', $this->callStore->storedCallsById[400]->endReason);
    }

    public function testAChannelThatIsNotAvailableIsNotIncluded(): void
    {
        $this->admissionService->refusalCode = 'feature_not_available';

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, 'task');

        static::assertSame(AgentVoiceCallStart::REFUSED_APP_NOT_INCLUDED, $agentVoiceCallStart->refusalReason);
        static::assertFalse($agentVoiceCallStart->appIsIncluded);
        static::assertSame([], $this->realtimeChannel->listenerReadAudiences, 'the ring is not even asked');
    }

    public function testNoListeningClientIsNotRingable(): void
    {
        $this->realtimeChannel->listeningByMailboxName = [];

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, 'task');

        static::assertSame(AgentVoiceCallStart::REFUSED_APP_NOT_RINGABLE, $agentVoiceCallStart->refusalReason);
        static::assertTrue($agentVoiceCallStart->appIsIncluded);
        static::assertNotContains('createCall', $this->callStore->getRecordedMethodNames());
    }

    #[DataProvider('provideAdmissionCodesOfTheRing')]
    public function testEachAdmissionCodeOfTheRingMapsToItsRefusal(string $admissionCode, string $expectedRefusalReason, bool $expectedAppIsIncluded): void
    {
        $this->admissionService->refusalCode = $admissionCode;

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, 'task');

        static::assertSame($expectedRefusalReason, $agentVoiceCallStart->refusalReason);
        static::assertSame($expectedAppIsIncluded, $agentVoiceCallStart->appIsIncluded);
        static::assertNull($agentVoiceCallStart->agentVoiceCall);
        static::assertNotContains('createCall', $this->callStore->getRecordedMethodNames());
    }

    /** @return array<string, array{string, string, bool}> */
    public static function provideAdmissionCodesOfTheRing(): array
    {
        return [
            'no_ai_budget' => ['no_ai_budget', AgentVoiceCallStart::REFUSED_NO_AI_BUDGET, true],
            'daily_limit_reached' => ['daily_limit_reached', AgentVoiceCallStart::REFUSED_DAILY_LIMIT_REACHED, true],
            'a code of the application' => ['seat_not_granted', AgentVoiceCallStart::REFUSED_APP_NOT_INCLUDED, false],
        ];
    }

    public function testARingThatReachedNoTargetIsRefusedAndNotStamped(): void
    {
        $this->realtimeChannel->failingOperations = [VoiceRingRealtimeChannelServiceDouble::OPERATION_SEND];

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, 'task');

        static::assertSame(AgentVoiceCallStart::REFUSED_APP_RING_DELIVERED_NOWHERE, $agentVoiceCallStart->refusalReason);
        static::assertTrue($agentVoiceCallStart->appIsIncluded);
        static::assertSame('FAILED', $this->callStore->storedCallsById[501]->endReason);
        static::assertNotContains('markCallStartedByAgentInConversation', $this->callStore->getRecordedMethodNames());
    }

    public function testAStartedCallRingsOnTheCurrentConversationAndIsStampedAsTheAgents(): void
    {
        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, 'the task');

        static::assertFalse($agentVoiceCallStart->isRefused());
        static::assertSame(AgentVoiceCallStart::CHANNEL_APP, $agentVoiceCallStart->startedChannel);
        static::assertSame(501, $agentVoiceCallStart->agentVoiceCall->id);
        static::assertSame(300, $agentVoiceCallStart->callConversationId);
        static::assertTrue($agentVoiceCallStart->appIsIncluded);
        static::assertSame('the task', $agentVoiceCallStart->agentVoiceCall->callTask);
        static::assertSame(300, $agentVoiceCallStart->agentVoiceCall->startedByAgentInConversationId);
        static::assertContains('markCallStartedByAgentInConversation', $this->callStore->getRecordedMethodNames());
        $createCall = $this->findRecordedStoreCall('createCall');
        static::assertFalse($createCall['rightsApplied'], 'the ring writes with the rights lifted');
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testACallInANewConversationRingsThereAndTheStampNamesTheChatOfTheAgent(): void
    {
        $this->callsService->conversationCreatedForRing = static::buildConversation(310);

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), true, 'task');

        static::assertSame(310, $agentVoiceCallStart->callConversationId);
        static::assertCount(1, $this->callsService->recordedConversationCreations);
        static::assertSame(300, $agentVoiceCallStart->agentVoiceCall->startedByAgentInConversationId);
    }

    public function testAConversationHiddenFromTheOwnerNeverCarriesARing(): void
    {
        $hiddenConversation = static::buildConversation(300);
        $hiddenConversation->purposeName = AIConversationPurpose::SUPERVISOR_CHECKPOINT;
        $this->callsService->conversationCreatedForRing = static::buildConversation(310);

        $agentVoiceCallStart = $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, $hiddenConversation, false, 'task');

        static::assertSame(310, $agentVoiceCallStart->callConversationId);
        static::assertSame(300, $agentVoiceCallStart->agentVoiceCall->startedByAgentInConversationId);
    }

    public function testWithoutACurrentConversationTheStampNamesTheCallsOwnConversation(): void
    {
        $this->callsService->conversationCreatedForRing = static::buildConversation(310);
        $agentVoiceCallStartsService = $this->buildPurposeStagingService();
        $agentVoiceCallStartsService->callConversationsById = [310 => static::buildConversation(310)];

        $agentVoiceCallStart = $agentVoiceCallStartsService->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, null, true, 'task');

        static::assertSame(310, $agentVoiceCallStart->callConversationId);
        static::assertSame(310, $agentVoiceCallStart->agentVoiceCall->startedByAgentInConversationId);
    }

    public function testACallablePurposeRunsInItsOwnConversationAndItsSpokenTaskIsTheTask(): void
    {
        $agentVoiceCallStartsService = $this->buildPurposeStagingService();
        $agentVoiceCallStartsService->purposedConversation = static::buildConversation(320);

        $agentVoiceCallStart = $agentVoiceCallStartsService->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), true, 'ignored task', static::CALLABLE_PURPOSE_NAME);

        static::assertSame([['accountId' => 41, 'purposeName' => static::CALLABLE_PURPOSE_NAME]], $agentVoiceCallStartsService->recordedPurposedConversationRequests);
        static::assertSame(320, $agentVoiceCallStart->callConversationId);
        static::assertSame('check the reviews with the owner', $agentVoiceCallStart->agentVoiceCall->callTask);
    }

    public function testAPurposedConversationThatCannotBeCreatedIsRefused(): void
    {
        $agentVoiceCallStartsService = $this->buildPurposeStagingService();

        $agentVoiceCallStart = $agentVoiceCallStartsService->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, null, true, null, static::CALLABLE_PURPOSE_NAME);

        static::assertSame(AgentVoiceCallStart::REFUSED_NO_CALL_CONVERSATION, $agentVoiceCallStart->refusalReason);
        static::assertTrue($agentVoiceCallStart->appIsIncluded);
        static::assertNotContains('createCall', $this->callStore->getRecordedMethodNames());
    }

    public function testAnEvalRunThrows(): void
    {
        $this->evalOutboundRecipientsService->method('throwIfBlocked')->willThrowException(new EvalOutboundRecipientBlockedException('a ring has no test sink'));

        $this->expectException(EvalOutboundRecipientBlockedException::class);
        $this->expectExceptionMessageMatches('/^a ring has no test sink$/');

        $this->getCallStartsService()->startVoiceCall(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300), false, 'task');
    }

    public function testTheReachableChannelsOfAnOwnerAreReadWithoutSideEffects(): void
    {
        $agentVoiceCallReachableChannels = $this->getCallStartsService()->findReachableChannelsForOwner(static::buildAccount(static::OWNER_ACCOUNT_ID), null, static::buildConversation(300));

        static::assertTrue($agentVoiceCallReachableChannels->appIsIncluded);
        static::assertTrue($agentVoiceCallReachableChannels->appIsRingableNow);
        static::assertSame([], $this->callStore->getRecordedMethodNames());
        static::assertSame([], $this->realtimeChannel->publishedMessages);

        $this->admissionService->refusalCode = 'feature_not_available';
        $notIncludedChannels = $this->getCallStartsService()->findReachableChannelsForOwner(static::buildAccount(static::OWNER_ACCOUNT_ID), null, null);
        static::assertFalse($notIncludedChannels->appIsIncluded);
        static::assertFalse($notIncludedChannels->appIsRingableNow);
    }

    public function testTheReachableChannelsReadAHiddenConversationAsNoConversation(): void
    {
        $hiddenConversationOfAnotherAccount = static::buildConversation(300, static::OTHER_ACCOUNT_ID);
        $hiddenConversationOfAnotherAccount->purposeName = AIConversationPurpose::SUPERVISOR_CHECKPOINT;

        $agentVoiceCallReachableChannels = $this->getCallStartsService()->findReachableChannelsForOwner(static::buildAccount(static::OWNER_ACCOUNT_ID), null, $hiddenConversationOfAnotherAccount);

        static::assertTrue($agentVoiceCallReachableChannels->appIsRingableNow, 'the owner is read, not the account of the hidden conversation');
        static::assertSame(['account-41'], array_map([static::class, 'getMailboxNameOf'], $this->realtimeChannel->listenerReadAudiences));
    }

    protected function buildPurposeStagingService(): PurposeStagingAgentVoiceCallStartsServiceDouble
    {
        $spokenTaskPurpose = new SpokenTaskPurposeDouble();
        $spokenTaskPurpose->name = static::CALLABLE_PURPOSE_NAME;
        $spokenTaskPurpose->spokenCallTask = 'check the reviews with the owner';
        $agentVoiceCallStartsService = new PurposeStagingAgentVoiceCallStartsServiceDouble();
        $agentVoiceCallStartsService->callablePurposesByName = [static::CALLABLE_PURPOSE_NAME => $spokenTaskPurpose];
        return $agentVoiceCallStartsService;
    }

    protected function storeRunningCallOnConversation(int $conversationId): void
    {
        $runningCall = $this->storeRingingCall(400, time(), -100, $conversationId);
        $runningCall->startedAt = DateTime::fromTimestamp(time() - 60);
    }

    /** @return array{method: string, subject: mixed, rightsApplied: bool} */
    protected function findRecordedStoreCall(string $methodName): array
    {
        foreach ($this->callStore->recordedCalls as $recordedCall) {
            if ($recordedCall['method'] === $methodName) {
                return $recordedCall;
            }
        }
        static::fail('the store was not asked ' . $methodName);
    }
}
