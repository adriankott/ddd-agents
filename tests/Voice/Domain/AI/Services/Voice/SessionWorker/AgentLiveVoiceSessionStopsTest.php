<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptBacklogSentence;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoicePromptText;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * How a running call stops, from the watcher tick (every 2 s): the admission is asked again every
 * `admissionRecheckSeconds` (30 s = 15 ticks) and a refusal ends the call after one farewell with the refusal's end
 * reason; a silent line is checked, then said goodbye to and hung up; while a delegated task runs the caller waits
 * and nothing is stopped; a call ended from outside is hung up without a second end stamp; the teardown runs once.
 */
class AgentLiveVoiceSessionStopsTest extends AgentLiveVoiceSessionWorkerTestCase
{
    public function testTheAdmissionIsAskedEveryFifteenTicks(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 14);
        static::assertSame([], $worker->agentVoiceCallAdmissionService->runningCallQuestionCallIds);

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 1);
        static::assertSame([static::CALL_ID], $worker->agentVoiceCallAdmissionService->runningCallQuestionCallIds);

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 15);
        static::assertSame([static::CALL_ID, static::CALL_ID], $worker->agentVoiceCallAdmissionService->runningCallQuestionCallIds);
        static::assertSame([], $sidebandWebSocket->sentClientEvents, 'an admitted call is told nothing');
    }

    /** @return array<string, array{0: AgentVoiceCallRefusal}> */
    public static function provideRefusalsOfARunningCall(): array
    {
        return [
            'daily limit' => [AgentVoiceCallRefusal::forDailyLimitReached()],
            'no budget, own end reason' => [AgentVoiceCallRefusal::forCode(AgentVoiceCallRefusal::CODE_NO_AI_BUDGET, AgentVoiceCall::END_REASON_FAILED)],
        ];
    }

    #[DataProvider('provideRefusalsOfARunningCall')]
    public function testARefusalSaysGoodbyeThenHangsUpWithTheRefusalsEndReason(AgentVoiceCallRefusal $agentVoiceCallRefusal): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentVoiceCallAdmissionService->runningCallRefusals = [$agentVoiceCallRefusal];

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 15);

        static::assertSame([[
            'type' => 'session.instructions.append',
            'delegation_id' => null,
            'content' => RecordingAgentVoiceSessionContextsService::REFUSED_CALL_GOODBYE_PREFIX . $agentVoiceCallRefusal->code,
        ]], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.instructions.append'));
        static::assertSame(
            ["LIVE live_session_seven call refused mid-call ({$agentVoiceCallRefusal->code}) — saying goodbye and hanging up"],
            $worker->findVoiceLogLinesContaining('refused mid-call')
        );
        static::assertSame([6.0], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals(), 'the farewell gets six seconds');
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds, 'not ended before the farewell was spoken');

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 15);
        static::assertCount(1, $worker->agentVoiceCallAdmissionService->runningCallQuestionCallIds, 'an armed farewell asks no more');

        $worker->hangUpAfterFarewellWasSpoken();

        static::assertSame([static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
        static::assertSame(['session.close'], array_column($sidebandWebSocket->findSentClientEventsOfType('session.close'), 'type'));
        static::assertSame([$agentVoiceCallRefusal->endReason], array_column($worker->agentVoiceCallsService->persistedCallEnds, 'endReason'));
        static::assertSame(1, $this->scriptedEventLoop->stopCount);
    }

    /** @return array<string, array{0: AgentVoiceCallRefusal}> */
    public static function provideNonLimitRefusals(): array
    {
        return [
            'the maximum call length' => [AgentVoiceCallRefusal::forMaximumCallSecondsReached()],
            'a feature not available' => [AgentVoiceCallRefusal::forFeatureNotAvailable()],
        ];
    }

    #[DataProvider('provideNonLimitRefusals')]
    public function testANonLimitRefusalIsToldWithTheNeutralGoodbyeThenHungUp(AgentVoiceCallRefusal $agentVoiceCallRefusal): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentVoiceSessionContextsService->answersNonLimitRefusalsWithTheNeutralGoodbye = true;
        $worker->agentVoiceCallAdmissionService->runningCallRefusals = [$agentVoiceCallRefusal];

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 15);
        $worker->hangUpAfterFarewellWasSpoken();

        static::assertFalse($agentVoiceCallRefusal->isLimitRefusal());
        static::assertSame(
            [AgentVoicePromptText::forName('AI.Voice.RefusedCallGoodbye') . RecordingAgentVoiceSessionContextsService::CALL_LANGUAGE_CLAUSE],
            array_column($sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'), 'content'),
            'told first, with the neutral goodbye'
        );
        static::assertNotSame('', AgentVoicePromptText::forName('AI.Voice.RefusedCallGoodbye'));
        static::assertSame([AgentVoiceCall::END_REASON_LIMIT_REACHED], array_column($worker->agentVoiceCallsService->persistedCallEnds, 'endReason'));
        static::assertSame([static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
    }

    public function testASigtermEndsTheCallAfterOneShortGoodbyeThroughTheNormalTeardown(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 1);
        static::assertSame([], $sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'), 'nothing before the signal');

        $worker->setWorkerState('attachedAgentVoiceCall', $agentVoiceCall);
        $worker->logTerminationSignalDrain();
        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 3);

        static::assertSame(
            [RecordingAgentVoiceSessionContextsService::WORKER_STOPPING_GOODBYE],
            array_column($sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'), 'content'),
            'one goodbye, not one per tick'
        );
        static::assertSame(
            ['LIVE live_session_seven worker stopping (SIGTERM) — saying goodbye and hanging up'],
            $worker->findVoiceLogLinesContaining('worker stopping')
        );
        static::assertSame([6.0], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals(), 'the goodbye gets six seconds');
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds, 'not ended before the goodbye was spoken');

        $worker->hangUpAfterFarewellWasSpoken();

        static::assertSame([static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
        static::assertSame([AgentVoiceCall::END_REASON_COMPLETED], array_column($worker->agentVoiceCallsService->persistedCallEnds, 'endReason'));
        static::assertSame(1, $this->scriptedEventLoop->stopCount);
    }

    public function testWithTheSwitchOffASigtermLetsTheCallRunToItsEnd(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['endCallOnTerminationSignal' => false]));
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->setWorkerState('attachedAgentVoiceCall', $agentVoiceCall);
        $worker->logTerminationSignalDrain();
        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 5);

        static::assertSame(
            ['LIVE live_session_seven SIGTERM received — draining: the live call finishes before this worker stops'],
            $worker->findVoiceLogLinesContaining('SIGTERM received')
        );
        static::assertSame([], $worker->findVoiceLogLinesContaining('worker stopping'));
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds);
        static::assertTrue($worker->getWorkerState('terminationSignalDrained'), 'the signal is still passed on at the end');
    }

    public function testWithoutASigtermTheWorkerSaysNoStoppingGoodbye(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 5);

        static::assertSame([], $worker->findVoiceLogLinesContaining('worker stopping'));
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds);
    }

    public function testASilentLineIsCheckedOnceThenAgainAfterTheCallerSpoke(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->setWorkerState('lastSpeechAtUnixTime', time() - 50);

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 3);

        static::assertSame(
            [RecordingAgentVoiceSessionContextsService::SILENCE_CHECK],
            array_column($sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'), 'content'),
            'checked once, not every tick'
        );
        static::assertSame(1, $worker->getWorkerState('speechSilenceNudgeCount'));

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'Yes', 0, 200));
        $worker->setWorkerState('lastSpeechAtUnixTime', time() - 50);
        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 1);

        static::assertSame(2, $worker->getWorkerState('speechSilenceNudgeCount'));
    }

    public function testASilentLineAfterThreeChecksIsSaidGoodbyeToAndHungUp(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->setWorkerState('lastSpeechAtUnixTime', time() - 50);
        $worker->setWorkerState('speechSilenceNudgeCount', 3);

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 1);

        static::assertSame(
            [RecordingAgentVoiceSessionContextsService::GOODBYE_AFTER_SILENCE_CHECKS],
            array_column($sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'), 'content')
        );
        static::assertSame([6.0], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals());
        $worker->hangUpAfterFarewellWasSpoken();
        static::assertSame([AgentVoiceCall::END_REASON_COMPLETED], array_column($worker->agentVoiceCallsService->persistedCallEnds, 'endReason'));
    }

    public function testALineSilentForTheHangUpTimeIsSaidGoodbyeToAndHungUp(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->setWorkerState('lastSpeechAtUnixTime', time() - 95);

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 2);

        static::assertSame(
            [RecordingAgentVoiceSessionContextsService::GOODBYE_AFTER_THE_HANG_UP_TIME],
            array_column($sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'), 'content'),
            'one farewell, armed once'
        );
        static::assertMatchesRegularExpression(
            '/^LIVE live_session_seven nobody spoke for 9[56]s — saying goodbye and hanging up$/',
            $worker->findVoiceLogLinesContaining('nobody spoke')[0]
        );
    }

    public function testNoSilenceStopBeforeAnybodySpoke(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $this->runWatcherTicks($worker, static::buildRunningCall(), $sidebandWebSocket, 20);

        static::assertSame([], $sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'));
    }

    public function testNoSilenceStopWhileADelegatedTaskRuns(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->setWorkerState('lastSpeechAtUnixTime', time() - 500);
        $worker->setWorkerState('pendingAgentTaskAfterMessageIndex', 21);
        $worker->setWorkerState('pendingAgentTaskStartedAtUnixTime', time());

        $this->runWatcherTicks($worker, static::buildRunningCall(), $sidebandWebSocket, 3);

        static::assertSame([], $sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'));
        static::assertSame([], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals());
        static::assertSame(0, $worker->getWorkerState('speechSilenceNudgeCount'));
    }

    public function testAnUnansweredQuestionIsAskedOnceMoreAfterTwentySeconds(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->setWorkerState('lastSpeechAtUnixTime', time() - 21);
        $worker->setWorkerState('callerAnswerAwaitedSinceUnixTime', time() - 21);

        $this->runWatcherTicks($worker, static::buildRunningCall(), $sidebandWebSocket, 3);

        static::assertSame(
            [RecordingAgentVoiceSessionContextsService::QUESTION_REPEAT],
            array_column($sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'), 'content')
        );
        static::assertTrue($worker->getWorkerState('unansweredQuestionReasked'));
    }

    public function testACallEndedFromOutsideIsHungUpWithoutASecondEndStamp(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $endedAgentVoiceCall = static::buildRunningCall();
        $endedAgentVoiceCall->endedAt = DateTime::fromTimestamp(AgentVoiceCallFixtures::NOW_UNIX_TIME);
        $endedAgentVoiceCall->endReason = AgentVoiceCall::END_REASON_COMPLETED;
        $worker->agentVoiceCallStoreService->storedCallsById[static::CALL_ID] = $endedAgentVoiceCall;
        $worker->setWorkerState('pendingAgentTaskAfterMessageIndex', 21);
        $worker->setWorkerState('transcriptBacklog', [
            LiveTranscriptBacklogSentence::create('user', 'Held back.', 0, 500, false),
        ]);

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 4);
        static::assertSame([], $worker->agentVoiceCallStoreService->foundCallIds, 'the row is read every fifth tick');

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 1);

        static::assertSame([static::CALL_ID], $worker->agentVoiceCallStoreService->foundCallIds);
        static::assertSame(['LIVE live_session_seven call row ended externally (COMPLETED) — hanging up the session'], $worker->findVoiceLogLinesContaining('ended externally'));
        static::assertSame([static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds, 'no second end stamp');
        static::assertSame([], $worker->agentMemoriesService->curationRequests, 'no second curation');
        static::assertSame(['Held back.'], $worker->agentVoiceCallsService->getPersistedTranscriptTexts(), 'the backlog is written');
        static::assertTrue($worker->getWorkerState('callFinished'));
        static::assertNull($worker->getWorkerState('pendingAgentTaskAfterMessageIndex'));
        static::assertSame(1, $this->scriptedEventLoop->stopCount);

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 5);
        static::assertCount(1, $worker->agentVoiceCallStoreService->foundCallIds, 'a finished call ticks no more');
    }

    /** @return array<string, array{0: int, 1: array<int, array{callId: int|null, seconds: int}>, 2: string[]}> */
    public static function provideOwedSecondsAtAnOutsideEnd(): array
    {
        return [
            'the row takes them' => [1, [['callId' => self::CALL_ID, 'seconds' => 40]], []],
            'the row refuses them again' => [2, [], ['LIVE live_session_seven 40s still owed to the call were not written onto it: ' . RecordingAgentVoiceCallStoreService::BOOKED_SECONDS_REFUSAL_MESSAGE]],
        ];
    }

    /**
     * @param array<int, array{callId: int|null, seconds: int}> $expectedBookedSeconds
     * @param string[] $expectedOwedLogLines
     */
    #[DataProvider('provideOwedSecondsAtAnOutsideEnd')]
    public function testSecondsOwedWhenTheCallIsEndedFromOutsideAreWrittenOnce(int $callWriteFailures, array $expectedBookedSeconds, array $expectedOwedLogLines): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentVoiceCallStoreService->addBookedSecondsFailures = $callWriteFailures;
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, ['type' => 'session.usage.updated', 'usage' => ['seconds' => 40]]);
        static::assertSame(40, $worker->getWorkerState('bookedSecondsOwedToTheCall'));
        $endedAgentVoiceCall = static::buildRunningCall();
        $endedAgentVoiceCall->endedAt = DateTime::fromTimestamp(AgentVoiceCallFixtures::NOW_UNIX_TIME);
        $endedAgentVoiceCall->endReason = AgentVoiceCall::END_REASON_COMPLETED;
        $worker->agentVoiceCallStoreService->storedCallsById[static::CALL_ID] = $endedAgentVoiceCall;

        $this->runWatcherTicks($worker, $agentVoiceCall, $sidebandWebSocket, 5);

        static::assertSame($expectedBookedSeconds, $worker->agentVoiceCallStoreService->bookedSeconds, 'written onto the row once');
        static::assertSame(2, count($worker->agentVoiceCallStoreService->bookedSecondsRequests), 'one write at the booking, one at the stop');
        static::assertSame([40.0], $worker->aISessionUsageService->getRecordedSeconds(), 'no second usage record');
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds, 'no second end stamp');
        static::assertSame($expectedOwedLogLines, $worker->findVoiceLogLinesContaining('still owed'));
        static::assertTrue($worker->getWorkerState('callFinished'));
        static::assertSame(1, $this->scriptedEventLoop->stopCount, 'the stop happens');
        static::assertSame([static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
    }

    public function testTheTeardownStampsAFreshlyEndedCallNotAgain(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $endedAgentVoiceCall = static::buildRunningCall();
        $endedAgentVoiceCall->endedAt = DateTime::fromTimestamp(AgentVoiceCallFixtures::NOW_UNIX_TIME);
        $endedAgentVoiceCall->endReason = AgentVoiceCall::END_REASON_LIMIT_REACHED;
        $worker->agentVoiceCallStoreService->storedCallsById[static::CALL_ID] = $endedAgentVoiceCall;

        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);

        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds);
        static::assertSame(AgentVoiceCall::END_REASON_LIMIT_REACHED, $agentVoiceCall->endReason, 'the call in memory takes the stored end');
    }

    public function testTheTeardownRunsOnce(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();

        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);
        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_FAILED);

        static::assertSame([AgentVoiceCall::END_REASON_COMPLETED], array_column($worker->agentVoiceCallsService->persistedCallEnds, 'endReason'));
        static::assertSame([['conversationId' => static::CONVERSATION_ID, 'async' => true]], $worker->agentMemoriesService->curationRequests);
        static::assertCount(1, static::findTraceEvents($worker, AIConversationTraceEvent::EVENT_TYPE_TRACE_FLUSHED), 'one flush marker');
    }

    public function testTheGreetingIsRequestedOnceAtTheSecondTick(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->setWorkerState('sessionStartedReceived', true);

        $this->runWatcherTicks($worker, static::buildRunningCall(), $sidebandWebSocket, 1);
        static::assertSame([], $sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'));

        $this->runWatcherTicks($worker, static::buildRunningCall(), $sidebandWebSocket, 5);

        $greetingEvents = $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.instructions.append');
        static::assertCount(1, $greetingEvents);
        static::assertNull($greetingEvents[0]['delegation_id']);
        static::assertStringStartsWith('If you have NOT opened the call yet, do it now', $greetingEvents[0]['content']);
    }

    public function testNoGreetingOnceTheAgentHasSpoken(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->setWorkerState('sessionStartedReceived', true);
        $worker->setWorkerState('agentHasSpoken', true);

        $this->runWatcherTicks($worker, static::buildRunningCall(), $sidebandWebSocket, 3);

        static::assertSame([], $sidebandWebSocket->findSentClientEventsOfType('session.instructions.append'));
    }

    protected function runWatcherTicks(
        StagedAgentLiveVoiceSessionsService $worker,
        AgentVoiceCall $agentVoiceCall,
        RecordingSidebandWebSocket $sidebandWebSocket,
        int $tickCount
    ): void {
        for ($tickNumber = 0; $tickNumber < $tickCount; $tickNumber++) {
            $worker->runWatcherTickOn($agentVoiceCall, $sidebandWebSocket);
        }
    }
}
