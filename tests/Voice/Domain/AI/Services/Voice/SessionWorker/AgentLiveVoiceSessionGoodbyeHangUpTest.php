<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallGoodbyeHangUp;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;

/**
 * The hang-up the agent asked for (`endVoiceCall` on this call): armed the tick the request is read, the line is
 * dropped only once the goodbye was spoken — the answer handed over, the agent's voice produced output after it and
 * has been quiet for {@see AgentVoiceCallGoodbyeHangUp::LIVE_QUIET_MILLISECONDS}, or produced none for
 * {@see AgentVoiceCallGoodbyeHangUp::NO_SPEECH_AFTER_THE_HAND_OVER_MILLISECONDS}. Then the call ends with the requested
 * reason and the request is cleared; a farewell another guard armed wins.
 */
class AgentLiveVoiceSessionGoodbyeHangUpTest extends AgentLiveVoiceSessionWorkerTestCase
{
    public function testNoRequestNoHangUp(): void
    {
        $worker = $this->buildWorkerWithConversation();

        static::assertFalse($worker->runWorkerStep('hangUpAfterRequestedGoodbyeWhenDue', static::buildRunningCall(), new RecordingSidebandWebSocket()));
        static::assertNull($worker->getWorkerState('requestedGoodbyeHangUp'));
        static::assertSame([], $worker->agentVoiceCallsService->persistedCallEnds);
    }

    public function testTheLineStaysUpWhileTheTurnThatSaysGoodbyeStillRuns(): void
    {
        $worker = $this->buildWorkerWithGoodbyeRequest();
        $worker->setWorkerState('pendingAgentTaskAfterMessageIndex', 21);

        static::assertFalse($worker->runWorkerStep('hangUpAfterRequestedGoodbyeWhenDue', static::buildRunningCall(), new RecordingSidebandWebSocket()));

        static::assertInstanceOf(AgentVoiceCallGoodbyeHangUp::class, $worker->getWorkerState('requestedGoodbyeHangUp'), 'armed the tick the request appeared');
        static::assertSame([], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
        static::assertSame(
            ['LIVE live_session_seven end requested by Assistant (' . AgentVoiceCall::END_REASON_ENDED_BY_AGENT . ') — hanging up after the goodbye'],
            $worker->findVoiceLogLinesContaining('end requested by')
        );
        static::assertCount(1, static::findTraceEvents($worker, AgentLiveVoiceSessionsService::TRACE_EVENT_TYPE_GOODBYE_HANG_UP_ARMED));
    }

    public function testTheGoodbyeIsNotCutOffRightAfterItWasHandedOver(): void
    {
        $worker = $this->buildWorkerWithArmedGoodbye(armedMillisecondsAgo: 20_000, handedOverMillisecondsAgo: 1_000, agentSpokeMillisecondsAgo: 25_000);

        static::assertFalse($worker->runWorkerStep('hangUpAfterRequestedGoodbyeWhenDue', static::buildRunningCall(), new RecordingSidebandWebSocket()));
        static::assertSame([], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
    }

    public function testTheCallEndsWithTheRequestedReasonOnceTheGoodbyeWasSpoken(): void
    {
        $worker = $this->buildWorkerWithArmedGoodbye(
            armedMillisecondsAgo: 20_000,
            handedOverMillisecondsAgo: 4_000,
            agentSpokeMillisecondsAgo: AgentVoiceCallGoodbyeHangUp::LIVE_QUIET_MILLISECONDS + 50
        );
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        static::assertTrue($worker->runWorkerStep('hangUpAfterRequestedGoodbyeWhenDue', static::buildRunningCall(), $sidebandWebSocket));

        static::assertSame([static::PROVIDER_SESSION_ID], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
        static::assertSame([AgentVoiceCall::END_REASON_ENDED_BY_AGENT], array_column($worker->agentVoiceCallsService->persistedCallEnds, 'endReason'));
        static::assertSame(1, $worker->requestedCallEndClears, 'the request is cleared');
        static::assertSame(1, $this->scriptedEventLoop->stopCount);
        $doneEvents = $worker->aIConversationTraceEventsService->findRecordedEventsOfType(AgentLiveVoiceSessionsService::TRACE_EVENT_TYPE_GOODBYE_HANG_UP_DONE);
        static::assertCount(1, $doneEvents);
        static::assertSame(AgentVoiceCall::END_REASON_ENDED_BY_AGENT, $doneEvents[0]->getPayloadArray()['endReason']);
    }

    public function testTheGoodbyeIsStillBeingSpokenInsideTheQuietWindow(): void
    {
        $worker = $this->buildWorkerWithArmedGoodbye(armedMillisecondsAgo: 20_000, handedOverMillisecondsAgo: 4_000, agentSpokeMillisecondsAgo: 800);

        static::assertFalse($worker->runWorkerStep('hangUpAfterRequestedGoodbyeWhenDue', static::buildRunningCall(), new RecordingSidebandWebSocket()));
        static::assertSame([], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
    }

    public function testNoSpeechAfterTheHandOverEndsTheCallAfterSixSeconds(): void
    {
        $worker = $this->buildWorkerWithArmedGoodbye(
            armedMillisecondsAgo: 20_000,
            handedOverMillisecondsAgo: AgentVoiceCallGoodbyeHangUp::NO_SPEECH_AFTER_THE_HAND_OVER_MILLISECONDS + 50,
            agentSpokeMillisecondsAgo: null
        );

        static::assertTrue($worker->runWorkerStep('hangUpAfterRequestedGoodbyeWhenDue', static::buildRunningCall(), new RecordingSidebandWebSocket()));
        static::assertSame([AgentVoiceCall::END_REASON_ENDED_BY_AGENT], array_column($worker->agentVoiceCallsService->persistedCallEnds, 'endReason'));
    }

    public function testTheFastCheckHangsUpAnArmedGoodbyeAndStaysIdleOtherwise(): void
    {
        $idleWorker = $this->buildWorkerWithGoodbyeRequest();
        $idleWorker->runFastCheckOn(static::buildRunningCall(), new RecordingSidebandWebSocket());
        static::assertNull($idleWorker->getWorkerState('requestedGoodbyeHangUp'), 'no task, no armed goodbye: the fast check reads nothing');

        $worker = $this->buildWorkerWithArmedGoodbye(armedMillisecondsAgo: 10_000, handedOverMillisecondsAgo: 4_000, agentSpokeMillisecondsAgo: 1_600);
        $worker->runFastCheckOn(static::buildRunningCall(), new RecordingSidebandWebSocket());
        static::assertSame([AgentVoiceCall::END_REASON_ENDED_BY_AGENT], array_column($worker->agentVoiceCallsService->persistedCallEnds, 'endReason'));
    }

    public function testTheFastCheckArmsTheRequestWhileTheTaskRuns(): void
    {
        $worker = $this->buildWorkerWithGoodbyeRequest();
        $worker->setWorkerState('pendingAgentTaskAfterMessageIndex', 21);
        $worker->setWorkerState('pendingAgentTaskStartedAtUnixTime', time());

        $worker->runFastCheckOn(static::buildRunningCall(), new RecordingSidebandWebSocket());

        static::assertInstanceOf(AgentVoiceCallGoodbyeHangUp::class, $worker->getWorkerState('requestedGoodbyeHangUp'));
        static::assertSame([], $worker->voiceProviderAdapter->hungUpProviderSessionIds);
    }

    public function testNothingRunsOnAFinishedCall(): void
    {
        $worker = $this->buildWorkerWithArmedGoodbye(armedMillisecondsAgo: 60_000, handedOverMillisecondsAgo: 50_000, agentSpokeMillisecondsAgo: 40_000);
        $worker->setWorkerState('callFinished', true);
        $fastCheckTimer = $this->scriptedEventLoop->addPeriodicTimer(0.5, [$worker, 'runGoodbyeAndTaskOutcomeCheckOfAttachedCall']);
        $worker->setWorkerState('goodbyeAndTaskOutcomeTimer', $fastCheckTimer);

        $worker->runFastCheckOn(static::buildRunningCall(), new RecordingSidebandWebSocket());
        $worker->runWatcherTickOn(static::buildRunningCall(), new RecordingSidebandWebSocket());

        static::assertSame([], $worker->voiceProviderAdapter->hungUpProviderSessionIds, 'no second hang-up after the call was finished');
        static::assertSame(0, $worker->getWorkerState('ticksSinceAttached'), 'the watcher tick returns at once');
        static::assertSame([$fastCheckTimer], $this->scriptedEventLoop->cancelledTimers, 'the fast check cancels itself');
    }

    public function testAFarewellAnotherGuardArmedWins(): void
    {
        $worker = $this->buildWorkerWithGoodbyeRequest();
        $worker->setWorkerState('farewellHangUpArmed', true);

        static::assertFalse($worker->runWorkerStep('hangUpAfterRequestedGoodbyeWhenDue', static::buildRunningCall(), new RecordingSidebandWebSocket()));
        static::assertNull($worker->getWorkerState('requestedGoodbyeHangUp'));
    }

    protected function buildWorkerWithGoodbyeRequest(): StagedAgentLiveVoiceSessionsService
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->requestedCallEndAfterGoodbye = AgentVoiceCall::END_REASON_ENDED_BY_AGENT;

        return $worker;
    }

    protected function buildWorkerWithArmedGoodbye(
        int $armedMillisecondsAgo,
        int $handedOverMillisecondsAgo,
        ?int $agentSpokeMillisecondsAgo
    ): StagedAgentLiveVoiceSessionsService {
        $worker = $this->buildWorkerWithGoodbyeRequest();
        $nowUnixMs = AIConversationTraceEvent::nowUnixMs();
        $worker->setWorkerState(
            'requestedGoodbyeHangUp',
            AgentVoiceCallGoodbyeHangUp::forLiveSession($nowUnixMs - $armedMillisecondsAgo, AgentVoiceCall::END_REASON_ENDED_BY_AGENT)
        );
        $worker->setWorkerState('lastAnswerHandedOverAtUnixMs', $nowUnixMs - $handedOverMillisecondsAgo);
        $worker->setWorkerState('lastAgentSpeechAtUnixMs', $agentSpokeMillisecondsAgo !== null ? $nowUnixMs - $agentSpokeMillisecondsAgo : null);

        return $worker;
    }
}
