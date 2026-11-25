<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\SignalSources\PhoneConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallFollowUpsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The agent's own follow-up after a call it started went unanswered
 * ({@see AgentVoiceCallFollowUpsService::startFollowUpTurnIfAgentCallWentUnanswered()}) and the stamp that makes a call
 * one the agent started ({@see AgentVoiceCallFollowUpsService::markCallStartedByAgent()}).
 */
class AgentVoiceCallFollowUpsServiceTest extends AgentVoiceIntegrationTestCase
{
    protected StagedAgentVoiceCallFollowUpsService $followUpsService;

    protected RecordingAIConversationTurnSignalsService $turnSignalsService;

    protected RecordingAIConversationTraceEventsService $traceEventsService;

    protected InMemoryAgentVoiceCallStore $callStore;

    protected InMemoryStoreAgentVoiceCallStoreService $callStoreService;

    protected bool $rightsAppliedBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rightsAppliedBeforeTheTest = DBEntity::getApplyRightsRestrictions();
        DBEntity::setApplyRightsRestrictions(true);
        $this->turnSignalsService = new RecordingAIConversationTurnSignalsService();
        $this->traceEventsService = new RecordingAIConversationTraceEventsService();
        $this->callStore = new InMemoryAgentVoiceCallStore();
        $this->replaceService(AIConversationTurnSignalsService::class, $this->turnSignalsService);
        $this->replaceService(AIConversationTraceEventsService::class, $this->traceEventsService);
        $this->callStoreService = new InMemoryStoreAgentVoiceCallStoreService($this->callStore);
        $this->replaceService(AgentVoiceCallStoreService::class, $this->callStoreService);
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]));
        $this->followUpsService = new StagedAgentVoiceCallFollowUpsService();
        $this->followUpsService->followUpConversation = $this->buildConversation(30, 5);
        $this->followUpsService->ownerAccount = $this->buildAccount(5);
    }

    protected function onTearDown(): void
    {
        DBEntity::setApplyRightsRestrictions($this->rightsAppliedBeforeTheTest);
        parent::onTearDown();
    }

    public function testAnExpiredRingTheAgentStartedStartsOneFollowUpTurn(): void
    {
        $agentVoiceCall = $this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, 30);

        static::assertTrue($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCall));

        static::assertSame([[
            'conversationId' => 30,
            'signalText' => 'The in-app call you started to the owner (call #7) was not answered: the app rang for 90 seconds and nobody picked up.'
                . ' Write the owner ONE short message in their language: say that they did not pick up, then offer the fixed thing.'
                . ' Do NOT call again on your own: no startVoiceCall in this turn. Start a call only after the owner says yes in a later message.',
            'signalSource' => PhoneConversationSignalSource::PHONE_CALL_UNANSWERED_FOLLOW_UP->value,
            'async' => true,
            'signedInAccountId' => 5,
        ]], $this->turnSignalsService->injectedSignals);
        static::assertSame([$agentVoiceCall], $this->followUpsService->reachableChannels->callsAskedFor);
        static::assertSame(
            [
                ['read' => 'conversation:30', 'rightsApplied' => false],
                ['read' => 'account:5', 'rightsApplied' => false],
                ['read' => 'automation:30:voiceCallFollowUp', 'rightsApplied' => false],
                ['read' => 'reachable:5:none', 'rightsApplied' => false],
            ],
            $this->followUpsService->recordedReads
        );
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
        static::assertCount(1, $this->traceEventsService->recordedEvents);
        $traceEvent = $this->traceEventsService->recordedEvents[0];
        static::assertSame(AgentVoiceCallFollowUpsService::TRACE_EVENT_TYPE_FOLLOW_UP_STARTED, $traceEvent->eventType);
        static::assertSame(AIConversationTraceEvent::SOURCE_VOICE_WORKER, $traceEvent->source);
        static::assertSame('unanswered (EXPIRED): follow-up turn started in conversation 30', $traceEvent->summary);
        static::assertSame(['endReason' => 'EXPIRED', 'followUpConversationId' => 30, 'appIsRingableNow' => false], $traceEvent->getPayloadArray());
    }

    public function testTheCallsTopicIsOfferedInWritingUnlessItIsTheDefaultTask(): void
    {
        $agentVoiceCall = $this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, 30);
        $agentVoiceCall->callTask = 'Go through the open invoice questions.';
        $this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCall);
        $agentVoiceCallWithDefaultTask = $this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, 30);
        $agentVoiceCallWithDefaultTask->callTask = AgentVoiceCall::getDefaultOutboundCallTask();
        $this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCallWithDefaultTask);

        static::assertStringContainsString(
            ' The call was meant for: "Go through the open invoice questions." — if that can be done here in writing, offer that too.',
            $this->turnSignalsService->injectedSignals[0]['signalText']
        );
        static::assertStringNotContainsString('The call was meant for', $this->turnSignalsService->injectedSignals[1]['signalText']);
    }

    /** @param array{endReason: string, startedByAgentInConversationId: ?int, answered: bool} $callShape */
    #[DataProvider('provideCallsWithoutAFollowUp')]
    public function testNoFollowUpForACallTheAgentDoesNotFollowUpOn(array $callShape): void
    {
        $agentVoiceCall = $this->buildUnansweredCall($callShape['endReason'], $callShape['startedByAgentInConversationId']);
        if ($callShape['answered']) {
            $agentVoiceCall->startedAt = DateTime::fromTimestamp(AgentVoiceCallFixtures::NOW_UNIX_TIME - 60);
        }

        static::assertFalse($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCall));
        static::assertSame([], $this->followUpsService->recordedReads);
        static::assertSame([], $this->turnSignalsService->injectedSignals);
    }

    /** @return array<string, array{array{endReason: string, startedByAgentInConversationId: ?int, answered: bool}}> */
    public static function provideCallsWithoutAFollowUp(): array
    {
        return [
            'a declined ring is the owner\'s no' => [['endReason' => AgentVoiceCall::END_REASON_DECLINED, 'startedByAgentInConversationId' => 30, 'answered' => false]],
            'a ring nobody stamped as the agent\'s' => [['endReason' => AgentVoiceCall::END_REASON_EXPIRED, 'startedByAgentInConversationId' => null, 'answered' => false]],
            'a ring the agent withdrew' => [['endReason' => AgentVoiceCall::END_REASON_ENDED_BY_AGENT, 'startedByAgentInConversationId' => 30, 'answered' => false]],
            'an answered call' => [['endReason' => AgentVoiceCall::END_REASON_EXPIRED, 'startedByAgentInConversationId' => 30, 'answered' => true]],
        ];
    }

    public function testACallThatIsNotEndedYetIsNoFollowUp(): void
    {
        $agentVoiceCall = $this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, 30);
        $agentVoiceCall->endedAt = null;

        static::assertFalse($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCall));
        static::assertSame([], $this->turnSignalsService->injectedSignals);
    }

    public function testAnAutomationRefusedConversationGetsNoFollowUp(): void
    {
        $this->followUpsService->refusesAutomation = true;

        static::assertFalse($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, 30)));
        static::assertSame([], $this->turnSignalsService->injectedSignals);
        static::assertSame([], $this->traceEventsService->recordedEvents);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testNoFollowUpWhenTheConversationTheAccountOrTheSpaceIsGone(): void
    {
        $agentVoiceCall = $this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, 30);
        $this->followUpsService->followUpConversation = null;
        static::assertFalse($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCall));

        $this->followUpsService->followUpConversation = $this->buildConversation(30, 5);
        $this->followUpsService->ownerAccount = null;
        static::assertFalse($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCall));

        $conversationOfAGoneSpace = $this->buildConversation(30, 5);
        $conversationOfAGoneSpace->agentSpaceId = 12;
        $conversationOfAGoneSpace->agentSpace = null;
        $this->followUpsService->followUpConversation = $conversationOfAGoneSpace;
        $this->followUpsService->ownerAccount = $this->buildAccount(5);
        static::assertFalse($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($agentVoiceCall));

        static::assertSame([], $this->turnSignalsService->injectedSignals);
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testTheSignedInAccountIsRestoredAfterAFailedInjection(): void
    {
        $signedInAccount = $this->buildAccount(77);
        AuthService::instance()->setAccount($signedInAccount);
        $this->turnSignalsService->failureMessage = 'resume refused';

        static::assertFalse($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, 30)));

        static::assertSame(5, $this->turnSignalsService->injectedSignals[0]['signedInAccountId']);
        static::assertSame($signedInAccount, AuthService::instance()->getAccount());
        static::assertTrue(DBEntity::getApplyRightsRestrictions());
        static::assertSame([], $this->traceEventsService->recordedEvents);
    }

    public function testTheSignedInAccountIsRestoredAfterASuccessfulInjection(): void
    {
        $signedInAccount = $this->buildAccount(77);
        AuthService::instance()->setAccount($signedInAccount);

        static::assertTrue($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, 30)));
        static::assertSame($signedInAccount, AuthService::instance()->getAccount());
    }

    public function testAFailingTraceNeverUndoesTheFollowUp(): void
    {
        $this->traceEventsService->failureMessage = 'trace table gone';

        static::assertTrue($this->followUpsService->startFollowUpTurnIfAgentCallWentUnanswered($this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, 30)));
        static::assertCount(1, $this->turnSignalsService->injectedSignals);
    }

    public function testTheStampIsWrittenThroughTheCallStore(): void
    {
        $agentVoiceCall = $this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, null);

        $this->followUpsService->markCallStartedByAgent($agentVoiceCall, $this->buildConversation(30, 5));

        static::assertSame([[7, 30]], $this->callStore->getArgumentsOf('markCallStartedByAgentInConversation'));
        static::assertSame(30, $agentVoiceCall->startedByAgentInConversationId);
    }

    public function testNoStampWithoutTheIdsOfTheCallAndTheConversation(): void
    {
        $agentVoiceCallWithoutId = $this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, null);
        $agentVoiceCallWithoutId->id = null;
        $this->followUpsService->markCallStartedByAgent($agentVoiceCallWithoutId, $this->buildConversation(30, 5));
        $conversationWithoutId = AIConversation::newInstance();
        $this->followUpsService->markCallStartedByAgent($this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, null), $conversationWithoutId);

        static::assertSame([], $this->callStore->recordedCalls);
    }

    public function testAFailedStampIsSwallowed(): void
    {
        $failingStampCallStore = new FailingStampAgentVoiceCallStore();
        $this->callStoreService->boundCallStore = $failingStampCallStore;
        $agentVoiceCall = $this->buildUnansweredCall(AgentVoiceCall::END_REASON_EXPIRED, null);

        $this->followUpsService->markCallStartedByAgent($agentVoiceCall, $this->buildConversation(30, 5));

        static::assertSame([[7, 30]], $failingStampCallStore->getArgumentsOf('markCallStartedByAgentInConversation'));
        static::assertNull($agentVoiceCall->startedByAgentInConversationId);
    }

    protected function buildUnansweredCall(string $endReason, ?int $startedByAgentInConversationId): AgentVoiceCall
    {
        $agentVoiceCall = AgentVoiceCallFixtures::buildCallAt(AgentVoiceCallFixtures::NOW_UNIX_TIME, -120, -30, null, -30);
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;
        $agentVoiceCall->endReason = $endReason;
        $agentVoiceCall->startedByAgentInConversationId = $startedByAgentInConversationId;
        $agentVoiceCall->aiConversationId = $startedByAgentInConversationId;

        return $agentVoiceCall;
    }

    protected function buildConversation(int $conversationId, int $accountId): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = $conversationId;
        $conversation->accountId = $accountId;

        return $conversation;
    }

    protected function buildAccount(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;

        return $account;
    }
}
