<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;

/**
 * The booking of the session's seconds: the provider reports the cumulative seconds, the worker books the increment
 * since the last booking once it reaches `usageBookingMinimumSeconds` (30 s; the final report books any rest), as the
 * usage of the session's model call AND onto the call; the counter advances only when both writes succeeded. A
 * session that never reported usage is booked the 15-second initialisation charge once, at the teardown.
 */
class AgentLiveVoiceSessionBookingTest extends AgentLiveVoiceSessionWorkerTestCase
{
    public function testASliceUnderTheMinimumWaitsForTheNextReport(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildUsageReport(29));
        static::assertSame([], $worker->agentVoiceCallStoreService->bookedSecondsRequests);
        static::assertSame([], $worker->aISessionUsageService->recordedSessionSeconds);
        static::assertTrue($worker->getWorkerState('usageEventReceived'));

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildUsageReport(45));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildUsageReport(60));

        static::assertSame([45.0], $worker->aISessionUsageService->getRecordedSeconds(), 'the 15 s since are under the minimum again');
        static::assertSame([['callId' => static::CALL_ID, 'seconds' => 45]], $worker->agentVoiceCallStoreService->bookedSeconds);
        static::assertSame(45, $worker->getWorkerState('billedSessionSeconds'));
        static::assertSame(
            [['key' => 'voice-live-billed-seconds:' . static::PROVIDER_SESSION_ID, 'by' => 45, 'ttlSeconds' => 14400]],
            $this->leaseAndCounterCache->counterIncrements,
            'the shared baseline a successor starts from'
        );
    }

    public function testAReportWithoutUsageChangesNothing(): void
    {
        $worker = $this->buildWorkerWithConversation();

        $worker->receiveSessionEvent(static::buildRunningCall(), new RecordingSidebandWebSocket(), ['type' => 'session.usage.updated', 'usage' => []]);

        static::assertFalse($worker->getWorkerState('usageEventReceived'));
    }

    public function testTheInitialisationChargeIsBookedOnceWhenNothingWasReported(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();

        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_FAILED);
        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);
        $worker->runWorkerStep('bookInitialisationChargeWhenNothingWasBooked', $agentVoiceCall);

        static::assertSame([15.0], $worker->aISessionUsageService->getRecordedSeconds());
        static::assertSame([['callId' => static::CALL_ID, 'seconds' => 15]], $worker->agentVoiceCallStoreService->bookedSeconds);
        static::assertSame(15, $worker->getWorkerState('billedSessionSeconds'));
    }

    public function testNoInitialisationChargeOnceTheProviderReportedUsageButTheReportedSecondsAreBooked(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $worker->receiveSessionEvent($agentVoiceCall, new RecordingSidebandWebSocket(), static::buildUsageReport(10));
        $worker->setWorkerState('lastSessionUsageReportedAtUnixTime', time() + 60); // no second passed since the report

        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);

        static::assertSame([10.0], $worker->aISessionUsageService->getRecordedSeconds(), 'the reported seconds, no initialisation charge');
        static::assertSame([['callId' => static::CALL_ID, 'seconds' => 10]], $worker->agentVoiceCallStoreService->bookedSeconds);
    }

    public function testAnEndByTheWorkerBooksTheSecondsSinceTheLastBookingAndSinceTheLastReport(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildUsageReport(30));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildUsageReport(48));
        $worker->setWorkerState('lastSessionUsageReportedAtUnixTime', time() - 16);

        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);
        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);

        $recordedSeconds = $worker->aISessionUsageService->getRecordedSeconds();
        static::assertCount(2, $recordedSeconds, 'the slice of 30 s, then once the rest at the teardown');
        static::assertSame(30.0, $recordedSeconds[0]);
        static::assertGreaterThanOrEqual(34.0, $recordedSeconds[1], '18 s reported under the minimum plus at least 16 s since the report');
        static::assertLessThanOrEqual(35.0, $recordedSeconds[1]);
        static::assertSame(30 + (int)$recordedSeconds[1], array_sum(array_column($worker->agentVoiceCallStoreService->bookedSeconds, 'seconds')));
    }

    public function testAFinalReportLeavesNothingForTheTeardown(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildUsageReport(30));

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, ['type' => 'session.closed', 'reason' => 'client_closed', 'usage' => ['seconds' => 44]]);

        static::assertSame([30.0, 14.0], $worker->aISessionUsageService->getRecordedSeconds(), 'the final report books the rest, the teardown nothing more');
    }

    public function testAFailedInitialisationChargeIsLogged(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->agentVoiceCallStoreService->addBookedSecondsFailures = 1;

        $worker->runWorkerStep('finishCall', static::buildRunningCall(), AgentVoiceCall::END_REASON_COMPLETED);

        static::assertSame(
            ['LIVE live_session_seven initialisation-charge booking failed: ' . RecordingAgentVoiceCallStoreService::BOOKED_SECONDS_REFUSAL_MESSAGE],
            $worker->findVoiceLogLinesContaining('initialisation-charge')
        );
        static::assertSame(AgentVoiceCall::END_REASON_COMPLETED, $worker->agentVoiceCallsService->persistedCallEnds[0]['endReason'], 'the call is ended anyway');
    }

    public function testTheAdmissionBillsTheCallsAccountWithTheCallsProvider(): void
    {
        $worker = $this->buildWorkerWithConversation();

        $worker->receiveSessionEvent(static::buildRunningCall(), new RecordingSidebandWebSocket(), static::buildUsageReport(30));

        static::assertSame([[
            'billedAccountId' => static::ACCOUNT_ID,
            'billedEntity' => null,
            'providerName' => 'openAiLive',
        ]], $worker->agentVoiceCallAdmissionService->sessionModelCallRequests);
        static::assertSame([30.0], $worker->aISessionUsageService->getRecordedSeconds(), 'the usage is recorded on the model call the admission built');
        static::assertSame([static::ACCOUNT_ID], $this->fixedAccountsService->foundAccountIds);
    }

    public function testTheConversationsAccountIsBilledBeforeTheCallsOwn(): void
    {
        $this->fixedAccountsService->accountsById[9] = FixedAccountsService::buildAccount(9);
        $worker = $this->buildWorkerWithConversation(static::buildConversation(accountId: 9));

        $worker->receiveSessionEvent(static::buildRunningCall(), new RecordingSidebandWebSocket(), static::buildUsageReport(30));

        static::assertSame(9, $worker->agentVoiceCallAdmissionService->sessionModelCallRequests[0]['billedAccountId']);
    }

    public function testACallWithoutAConversationBillsItsOwnAccount(): void
    {
        $worker = $this->buildWorker();

        $worker->receiveSessionEvent(static::buildRunningCall(conversationId: null), new RecordingSidebandWebSocket(), static::buildUsageReport(30));

        static::assertSame(static::ACCOUNT_ID, $worker->agentVoiceCallAdmissionService->sessionModelCallRequests[0]['billedAccountId']);
        static::assertSame([['callId' => static::CALL_ID, 'seconds' => 30]], $worker->agentVoiceCallStoreService->bookedSeconds);
    }

    public function testTheUsageIsRecordedOnTheModelCallTheAdmissionBuiltForTheCall(): void
    {
        $worker = $this->buildWorkerWithConversation();

        $worker->receiveSessionEvent(static::buildRunningCall(), new RecordingSidebandWebSocket(), static::buildUsageReport(30));

        static::assertCount(1, $worker->agentVoiceCallAdmissionService->runningCallModelCalls);
        static::assertSame(static::CALL_ID, $worker->agentVoiceCallAdmissionService->runningCallModelCalls[0]['callId']);
        static::assertSame(
            $worker->agentVoiceCallAdmissionService->runningCallModelCalls[0]['modelCall'],
            $worker->aISessionUsageService->recordedSessionSeconds[0]['modelCall'],
            'the very model call the admission answered'
        );
    }

    public function testACallWriteThatFailsIsOwedAndWrittenWithTheNextBookingWithoutASecondUsage(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentVoiceCallStoreService->addBookedSecondsFailures = 1;

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildUsageReport(40));

        static::assertSame([40.0], $worker->aISessionUsageService->getRecordedSeconds());
        static::assertSame(40, $worker->getWorkerState('billedSessionSeconds'), 'the counter follows the recorded usage');
        static::assertSame(40, $worker->getWorkerState('bookedSecondsOwedToTheCall'));
        static::assertSame([['key' => 'voice-live-billed-seconds:' . static::PROVIDER_SESSION_ID, 'by' => 40, 'ttlSeconds' => 14400]], $this->leaseAndCounterCache->counterIncrements);
        static::assertSame([], $worker->agentVoiceCallStoreService->bookedSeconds);
        static::assertSame(
            ['LIVE live_session_seven usage accounting failed: ' . RecordingAgentVoiceCallStoreService::BOOKED_SECONDS_REFUSAL_MESSAGE],
            $worker->findVoiceLogLinesContaining('usage accounting failed')
        );

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildUsageReport(70));

        static::assertSame([40.0, 30.0], $worker->aISessionUsageService->getRecordedSeconds(), 'each second is recorded as usage once');
        static::assertSame([['callId' => static::CALL_ID, 'seconds' => 70]], $worker->agentVoiceCallStoreService->bookedSeconds, 'the owed 40 s with the new 30 s');
        static::assertSame(0, $worker->getWorkerState('bookedSecondsOwedToTheCall'));
        static::assertSame([40, 30], array_column($this->leaseAndCounterCache->counterIncrements, 'by'));
    }

    public function testSecondsOwedToTheCallAreWrittenAtTheTeardown(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $worker->agentVoiceCallStoreService->addBookedSecondsFailures = 1;
        $worker->receiveSessionEvent($agentVoiceCall, new RecordingSidebandWebSocket(), static::buildUsageReport(40));

        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);

        static::assertSame([['callId' => static::CALL_ID, 'seconds' => 40]], $worker->agentVoiceCallStoreService->bookedSeconds);
        static::assertSame([40.0], $worker->aISessionUsageService->getRecordedSeconds(), 'no usage and no initialisation charge at the teardown');
        static::assertSame(0, $worker->getWorkerState('bookedSecondsOwedToTheCall'));
    }

    public function testSecondsTheTeardownCannotWriteAreLoggedAndTheCallEndsAnyway(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $worker->agentVoiceCallStoreService->addBookedSecondsFailures = 2;
        $worker->receiveSessionEvent($agentVoiceCall, new RecordingSidebandWebSocket(), static::buildUsageReport(40));

        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);

        static::assertSame(
            ['LIVE live_session_seven 40s still owed to the call were not written onto it: ' . RecordingAgentVoiceCallStoreService::BOOKED_SECONDS_REFUSAL_MESSAGE],
            $worker->findVoiceLogLinesContaining('still owed')
        );
        static::assertSame([AgentVoiceCall::END_REASON_COMPLETED], array_column($worker->agentVoiceCallsService->persistedCallEnds, 'endReason'));
        static::assertSame([40.0], $worker->aISessionUsageService->getRecordedSeconds());
    }

    public function testACallOfNoAccountLandsItsSecondsOnTheCallWithoutUsage(): void
    {
        $worker = $this->buildWorker();

        $worker->receiveSessionEvent(static::buildRunningCall(conversationId: null, accountId: null), new RecordingSidebandWebSocket(), static::buildUsageReport(30));

        static::assertSame([['callId' => static::CALL_ID, 'seconds' => 30]], $worker->agentVoiceCallStoreService->bookedSeconds);
        static::assertSame([], $worker->aISessionUsageService->recordedSessionSeconds);
        static::assertSame(30, $worker->getWorkerState('billedSessionSeconds'));
        static::assertSame([30], array_column($this->leaseAndCounterCache->counterIncrements, 'by'));
    }

    public function testAnUnreadableAccountStillLandsTheSecondsOnTheCall(): void
    {
        unset($this->fixedAccountsService->accountsById[static::ACCOUNT_ID]);
        $worker = $this->buildWorkerWithConversation();

        $worker->receiveSessionEvent(static::buildRunningCall(), new RecordingSidebandWebSocket(), static::buildUsageReport(30));

        static::assertSame([], $worker->aISessionUsageService->recordedSessionSeconds);
        static::assertSame([['callId' => static::CALL_ID, 'seconds' => 30]], $worker->agentVoiceCallStoreService->bookedSeconds);
        static::assertSame(30, $worker->getWorkerState('billedSessionSeconds'));
    }

    /** @return array<string, mixed> */
    protected static function buildUsageReport(int $cumulativeSeconds): array
    {
        return ['type' => 'session.usage.updated', 'usage' => ['seconds' => $cumulativeSeconds]];
    }
}
