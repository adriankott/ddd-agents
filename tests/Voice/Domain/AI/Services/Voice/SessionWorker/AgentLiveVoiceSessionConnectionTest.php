<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Exceptions\Voice\LiveSessionGoneException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use LogicException;
use RuntimeException;

/**
 * The sideband connection: three connect attempts one second apart, then the call ends FAILED and the attach throws
 * (so Messenger retries visibly); a session the provider reports as gone is not tried again and ends with
 * {@see LiveSessionGoneException}; an opened sideband gets the fast check, the watcher and the two socket handlers; a
 * close of the socket, the provider's `session.closed` and a silent sideband end the call.
 *
 * The connect itself is the WebSocket client's real connector ({@see \DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService::buildSidebandWebSocketConnector()}
 * is not replaced): the scripted adapter gives an address the client refuses at once, without a network. A connect
 * that gives up already on its first attempt ends the attach without entering the loop (a stop before `run()` is lost).
 */
class AgentLiveVoiceSessionConnectionTest extends AgentLiveVoiceSessionWorkerTestCase
{
    public function testThreeConnectAttemptsThenTheCallEndsFailed(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $worker->voiceProviderAdapter->sidebandWebSocketUrls = ['invalid-scheme://attempt-1', 'invalid-scheme://attempt-2', 'invalid-scheme://attempt-3'];

        try {
            $worker->attachToCallSession($agentVoiceCall, async: false);
            static::fail('The attach was expected to throw.');
        } catch (InternalErrorException $connectFailure) {
            static::assertSame(
                'The sideband connect to the provider scriptedLive failed for session live_session_seven after 3 attempts: Cannot connect to invalid URL (invalid-scheme://attempt-3)',
                $connectFailure->getMessage()
            );
        }

        static::assertSame([static::PROVIDER_SESSION_ID, static::PROVIDER_SESSION_ID, static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->sidebandWebSocketUrlRequests);
        static::assertSame([1.0, 1.0], $this->scriptedEventLoop->firedOneShotTimerIntervals, 'a retry one second after each failed attempt');
        static::assertSame([
            'LIVE live_session_seven sideband connect attempt 1 failed: Cannot connect to invalid URL (invalid-scheme://attempt-1) — retrying',
            'LIVE live_session_seven sideband connect attempt 2 failed: Cannot connect to invalid URL (invalid-scheme://attempt-2) — retrying',
        ], $worker->findVoiceLogLinesContaining('sideband connect attempt'));
        static::assertSame([['callId' => static::CALL_ID, 'endReason' => AgentVoiceCall::END_REASON_FAILED]], $worker->agentVoiceCallsService->persistedCallEnds);
        static::assertSame([['conversationId' => static::CONVERSATION_ID, 'async' => true]], $worker->agentMemoriesService->curationRequests);
        static::assertSame(1, $this->scriptedEventLoop->runCount);
        static::assertSame(1, $this->scriptedEventLoop->stopCount);
        static::assertSame([SIGTERM], $this->scriptedEventLoop->removedSignals, 'the drain listener leaves the loop with the call');
        static::assertSame([], $this->scriptedEventLoop->signalListeners);
    }

    public function testAGoneSessionIsNotTriedAgain(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->voiceProviderAdapter->sidebandWebSocketUrls = ['invalid-scheme://attempt-1', 'invalid-scheme://' . RecordingVoiceProviderAdapter::SESSION_GONE_MARKER];

        try {
            $worker->attachToCallSession(static::buildRunningCall(), async: false);
            static::fail('The attach was expected to throw.');
        } catch (LiveSessionGoneException $sessionGone) {
            static::assertSame(
                'Session live_session_seven is gone at the provider scriptedLive — nothing to attach to: Cannot connect to invalid URL (invalid-scheme://session-gone)',
                $sessionGone->getMessage()
            );
        }

        static::assertCount(2, $worker->voiceProviderAdapter->sidebandWebSocketUrlRequests, 'no third attempt after the provider said the session is gone');
        static::assertSame([1.0], $this->scriptedEventLoop->firedOneShotTimerIntervals);
        static::assertSame([], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals(), 'no retry is waiting');
        static::assertSame([['callId' => static::CALL_ID, 'endReason' => AgentVoiceCall::END_REASON_FAILED]], $worker->agentVoiceCallsService->persistedCallEnds);
    }

    public function testAConnectThatGivesUpAtOnceEndsTheAttachWithoutTheLoop(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->voiceProviderAdapter->sidebandWebSocketUrls = ['invalid-scheme://' . RecordingVoiceProviderAdapter::SESSION_GONE_MARKER];
        $terminationSignalHandlerBeforeTheAttach = pcntl_signal_get_handler(SIGTERM);

        try {
            $worker->attachToCallSession(static::buildRunningCall(), async: false);
            static::fail('The attach was expected to throw.');
        } catch (LiveSessionGoneException $sessionGone) {
            static::assertSame(
                'Session live_session_seven is gone at the provider scriptedLive — nothing to attach to: Cannot connect to invalid URL (invalid-scheme://session-gone)',
                $sessionGone->getMessage()
            );
        }

        static::assertSame(0, $this->scriptedEventLoop->runCount, 'the loop is not entered: it would wait for ever');
        static::assertTrue($worker->getWorkerState('eventLoopStopRequested'));
        static::assertSame([], $this->scriptedEventLoop->signalListeners, 'no SIGTERM listener is left on the loop');
        static::assertSame([SIGTERM], $this->scriptedEventLoop->removedSignals);
        static::assertSame($terminationSignalHandlerBeforeTheAttach, pcntl_signal_get_handler(SIGTERM));
        static::assertSame([], $worker->aIUsageEnvelopesService->openedEnvelopes, 'no envelope without the loop');
        static::assertSame([], $worker->aIUsageEnvelopesService->closedEnvelopes);
        static::assertCount(1, $this->leaseAndCounterCache->lockReleaseRequests, 'the lease is released');
        static::assertSame([['callId' => static::CALL_ID, 'endReason' => AgentVoiceCall::END_REASON_FAILED]], $worker->agentVoiceCallsService->persistedCallEnds);
    }

    public function testATransientFailureIsRetriedAndAGoneOneIsNot(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('attachedAgentVoiceCall', static::buildRunningCall());
        $worker->setWorkerState('sidebandConnectAttemptCount', 1);

        $worker->retryOrGiveUpSidebandConnect(new RuntimeException('connection reset'));
        static::assertSame([1.0], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals());
        static::assertNull($worker->getWorkerState('sidebandConnectFailure'));

        $sessionGoneFailure = new RuntimeException('the provider answered 404: ' . RecordingVoiceProviderAdapter::SESSION_GONE_MARKER);
        $worker->retryOrGiveUpSidebandConnect($sessionGoneFailure);
        static::assertSame([1.0], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals(), 'no second retry');
        static::assertSame($sessionGoneFailure, $worker->getWorkerState('sidebandConnectFailure'));
        static::assertSame(1, $this->scriptedEventLoop->stopCount);
    }

    public function testTheThirdFailedAttemptGivesUp(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('attachedAgentVoiceCall', static::buildRunningCall());
        $worker->setWorkerState('sidebandConnectAttemptCount', 3);
        $thirdFailure = new RuntimeException('connection reset');

        $worker->retryOrGiveUpSidebandConnect($thirdFailure);

        static::assertSame([], $this->scriptedEventLoop->getWaitingOneShotTimerIntervals());
        static::assertSame($thirdFailure, $worker->getWorkerState('sidebandConnectFailure'));
        static::assertSame(1, $this->scriptedEventLoop->stopCount);
    }

    public function testAnOpenedSidebandGetsTheTimersAndTheSocketHandlers(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('attachedAgentVoiceCall', static::buildRunningCall());
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->continueWithConnectedSideband($sidebandWebSocket);

        static::assertSame([0.5, 2.0], $this->scriptedEventLoop->getPeriodicTimerIntervals());
        static::assertSame(1, $sidebandWebSocket->countListeners('message'));
        static::assertSame(1, $sidebandWebSocket->countListeners('close'));
        static::assertSame($sidebandWebSocket, $worker->getWorkerState('sidebandWebSocketConnection'));
        $attachedEvents = $worker->aIConversationTraceEventsService->findRecordedEventsOfType('worker.attached');
        static::assertCount(1, $attachedEvents, 'written at once, not on the next tick');
        static::assertSame('call:7:worker.attached:' . $worker->getWorkerState('traceWorkerToken'), $attachedEvents[0]->eventKey);
        static::assertSame(AIConversationTraceEvent::SOURCE_VOICE_WORKER, $attachedEvents[0]->source);
    }

    public function testAClosedSocketHangsUpAndEndsTheCallCompleted(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('usageEventReceived', true);
        $worker->setWorkerState('attachedAgentVoiceCall', static::buildRunningCall());

        $worker->finishCallAfterSidebandClosed(1006);

        static::assertSame([static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
        static::assertSame([['callId' => static::CALL_ID, 'endReason' => AgentVoiceCall::END_REASON_COMPLETED]], $worker->agentVoiceCallsService->persistedCallEnds);
        static::assertSame(['LIVE live_session_seven sideband closed (code 1006)'], $worker->findVoiceLogLinesContaining('sideband closed'));
        static::assertSame(1, $this->scriptedEventLoop->stopCount);
    }

    public function testTheProvidersCloseEventEndsTheCallWithTheMappedReason(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $worker->setWorkerState('billedSessionSeconds', 40);

        $worker->receiveSessionEvent($agentVoiceCall, new RecordingSidebandWebSocket(), [
            'type' => 'session.closed',
            'reason' => 'completed_by_the_provider',
            'usage' => ['seconds' => 52],
        ]);

        static::assertSame(['completed_by_the_provider'], $worker->voiceProviderAdapter->mappedSessionCloseReasons);
        static::assertSame([['callId' => static::CALL_ID, 'endReason' => AgentVoiceCall::END_REASON_COMPLETED]], $worker->agentVoiceCallsService->persistedCallEnds);
        static::assertSame([12.0], $worker->aISessionUsageService->getRecordedSeconds(), 'the last slice is booked although it is under the minimum');
        static::assertSame(['LIVE live_session_seven session closed reason=completed_by_the_provider'], $worker->findVoiceLogLinesContaining('session closed reason'));
        static::assertSame(1, $this->scriptedEventLoop->stopCount);
    }

    public function testASilentSidebandEndsTheCallFailed(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['limits' => ['sessionSilentSeconds' => 100]]));
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('usageEventReceived', true);
        $worker->setWorkerState('lastSessionEventReceivedAtUnixTime', time() - 101);
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->runWatcherTickOn(static::buildRunningCall(), $sidebandWebSocket);

        static::assertSame(['LIVE live_session_seven sideband silent for more than 100s — hanging up and ending the loop cleanly'], $worker->findVoiceLogLinesContaining('sideband silent'));
        static::assertSame([static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
        static::assertSame(['session.close'], $sidebandWebSocket->getSentClientEventTypes());
        static::assertSame([['callId' => static::CALL_ID, 'endReason' => AgentVoiceCall::END_REASON_FAILED]], $worker->agentVoiceCallsService->persistedCallEnds);
        static::assertSame(1, $this->scriptedEventLoop->stopCount);
    }

    public function testASidebandThatSpokeInsideTheSilenceLimitStays(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['limits' => ['sessionSilentSeconds' => 100]]));
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('lastSessionEventReceivedAtUnixTime', time() - 90);

        $worker->runWatcherTickOn(static::buildRunningCall(), new RecordingSidebandWebSocket());

        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds);
        static::assertSame(0, $this->scriptedEventLoop->stopCount);
    }

    public function testAnEventWhoseHandlingFailsIsLoggedAndNeverReachesTheLoop(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentVoiceCallsService->transcriptWriteFailures = [new LogicException('the transcript store is gone')];

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'Hello there.', 0, 400));
        // the agent's words a second later close the caller's sentence: its write throws
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('assistant', 'Hi', 1500, 1800));

        static::assertCount(1, $worker->findVoiceLogLinesContaining('LIVE live_session_seven event handling failed: the transcript store is gone at '));
        static::assertSame([], $worker->agentVoiceCallsService->persistedTranscripts);
        static::assertSame(0, $this->scriptedEventLoop->stopCount);
    }
}
