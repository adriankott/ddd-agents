<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityTurnFeed;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallGoodbyeHangUp;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceTaskProgressSchedule;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;

/**
 * While a delegated task runs, the turn's activity feed becomes what the caller hears: the newest line as spoken
 * progress (commentary under the pending delegation), older lines and steps without a line as unspoken thinking, the
 * bridge as an instruction after twelve seconds without a line; nothing after an armed goodbye or a farewell; the
 * worker stamps itself as a reader of the call's ROOT conversation.
 */
class AgentLiveVoiceSessionTaskProgressTest extends AgentLiveVoiceSessionWorkerTestCase
{
    public const int ROOT_CONVERSATION_ID = 300;

    public function testTheNewestLineIsSpokenAsProgressUnderThePendingDelegation(): void
    {
        $worker = $this->buildWorkerWithPendingTask(delegatedMillisecondsAgo: 7_000);
        $worker->activityTurnFeed = static::buildFeed([static::buildLine(11, 'I am pulling your newest review.')]);
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->runWorkerStep('sendPendingTaskProgressWhenDue', static::buildRunningCall(), $sidebandWebSocket);

        static::assertSame([[
            'type' => 'session.commentary.append',
            'delegation_id' => 'del_progress',
            'content' => 'Progress, not the result: I am pulling your newest review. Tell the caller in one short sentence what'
                . ' you are doing now; state no result — it follows.' . RecordingAgentVoiceSessionContextsService::CALL_LANGUAGE_CLAUSE,
        ]], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.commentary.append'));
        static::assertSame([['rootConversationId' => static::ROOT_CONVERSATION_ID, 'taskMessageIndex' => 4]], $worker->activityTurnFeedReads, 'the feed of the ROOT conversation, from the task index on');
        $progressSentEvents = static::findBufferedTraceEvents($worker, AgentLiveVoiceSessionsService::TRACE_EVENT_TYPE_TASK_PROGRESS_SENT);
        static::assertCount(1, $progressSentEvents);
        static::assertSame(AgentLiveVoiceSessionsService::TASK_PROGRESS_KIND_SPOKEN, $progressSentEvents[0]->getPayloadArray()['kind']);
        static::assertCount(1, static::findBufferedTraceEvents($worker, AgentLiveVoiceSessionsService::TRACE_EVENT_TYPE_ACTIVITY_FEED_LATENCY));
    }

    public function testNothingIsSpokenBeforeSixSeconds(): void
    {
        $worker = $this->buildWorkerWithPendingTask(delegatedMillisecondsAgo: 3_000);
        $worker->activityTurnFeed = static::buildFeed([static::buildLine(11, 'I am pulling your newest review')]);
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->runWorkerStep('sendPendingTaskProgressWhenDue', static::buildRunningCall(), $sidebandWebSocket);

        static::assertSame([], $sidebandWebSocket->sentClientEvents);
    }

    public function testOlderLinesAndStepsWithoutALineGoAsThinking(): void
    {
        $worker = $this->buildWorkerWithPendingTask(delegatedMillisecondsAgo: 7_000);
        $worker->activityTurnFeed = static::buildFeed([
            static::buildLine(11, 'Reading your reviews'),
            static::buildLine(12, 'Checking your ranking'),
            static::buildLine(13, null, 'searchWeb'),
        ]);
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->runWorkerStep('sendPendingTaskProgressWhenDue', static::buildRunningCall(), $sidebandWebSocket);

        static::assertSame(['session.commentary.append', 'session.thinking.append'], $sidebandWebSocket->getSentClientEventTypes());
        static::assertStringContainsString('Checking your ranking', $sidebandWebSocket->sentClientEvents[0]['content']);
        static::assertStringContainsString('Reading your reviews. Working on: search web.', $sidebandWebSocket->sentClientEvents[1]['content']);
        static::assertSame('del_progress', $sidebandWebSocket->sentClientEvents[1]['delegation_id']);
    }

    public function testTheBridgeComesFromTheScheduleAfterTwelveSecondsWithoutALine(): void
    {
        $worker = $this->buildWorkerWithPendingTask(delegatedMillisecondsAgo: 12_500);
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->runWorkerStep('sendPendingTaskProgressWhenDue', static::buildRunningCall(), $sidebandWebSocket);

        static::assertSame([[
            'type' => 'session.instructions.append',
            'delegation_id' => null,
            'content' => 'The backend is still working on the request. Say so in one short sentence — no question, do not ask'
                . ' whether the caller is there — then keep listening.' . RecordingAgentVoiceSessionContextsService::CALL_LANGUAGE_CLAUSE,
        ]], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.instructions.append'));
        $progressSentEvents = static::findBufferedTraceEvents($worker, AgentLiveVoiceSessionsService::TRACE_EVENT_TYPE_TASK_PROGRESS_SENT);
        static::assertSame(AgentLiveVoiceSessionsService::TASK_PROGRESS_KIND_BRIDGE, $progressSentEvents[0]->getPayloadArray()['kind']);
        static::assertCount(1, $worker->findVoiceLogLinesContaining('asking for a bridge line'));
    }

    public function testNothingAfterAnArmedGoodbye(): void
    {
        $worker = $this->buildWorkerWithPendingTask(delegatedMillisecondsAgo: 30_000);
        $worker->activityTurnFeed = static::buildFeed([static::buildLine(11, 'Ending the call')]);
        $worker->setWorkerState('requestedGoodbyeHangUp', AgentVoiceCallGoodbyeHangUp::forLiveSession(AIConversationTraceEvent::nowUnixMs(), AgentVoiceCall::END_REASON_ENDED_BY_AGENT));
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->runWorkerStep('sendPendingTaskProgressWhenDue', static::buildRunningCall(), $sidebandWebSocket);

        static::assertSame([], $sidebandWebSocket->sentClientEvents);
    }

    public function testNothingAfterAFarewell(): void
    {
        $worker = $this->buildWorkerWithPendingTask(delegatedMillisecondsAgo: 30_000);
        $worker->setWorkerState('farewellHangUpArmed', true);
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->runWorkerStep('sendPendingTaskProgressWhenDue', static::buildRunningCall(), $sidebandWebSocket);

        static::assertSame([], $sidebandWebSocket->sentClientEvents);
    }

    public function testNothingWithoutAPendingTask(): void
    {
        $worker = $this->buildWorkerWithRootedConversation();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->runWorkerStep('sendPendingTaskProgressWhenDue', static::buildRunningCall(), $sidebandWebSocket);

        static::assertSame([], $sidebandWebSocket->sentClientEvents);
        static::assertSame([], $worker->activityTurnFeedReads, 'no task, no feed read');
    }

    public function testTheWorkerStampsItselfAsAReaderOfTheRootConversation(): void
    {
        $worker = $this->buildWorkerWithRootedConversation();

        $worker->runWatcherTickOn(static::buildRunningCall(), new RecordingSidebandWebSocket());

        static::assertSame([static::ROOT_CONVERSATION_ID], $worker->activityReaderStampedForRootConversationIds);
    }

    public function testACallWithoutAConversationStampsNoReader(): void
    {
        $worker = $this->buildWorker();

        $worker->runWatcherTickOn(static::buildRunningCall(conversationId: null), new RecordingSidebandWebSocket());

        static::assertSame([], $worker->activityReaderStampedForRootConversationIds);
    }

    public function testANewTaskEndsTheOutstandingProgressCommentary(): void
    {
        $worker = $this->buildWorkerWithPendingTask(delegatedMillisecondsAgo: 7_000);
        $worker->activityTurnFeed = static::buildFeed([static::buildLine(11, 'Reading your reviews')]);
        $worker->receiveSessionEvent(static::buildRunningCall(), new RecordingSidebandWebSocket(), static::buildTranscriptDelta('assistant', 'One moment', 3000, 4000));
        // the agent's words arrived a while ago: the schedule does not talk over speech still going on
        $worker->setWorkerState('lastSpeechAtUnixMs', AIConversationTraceEvent::nowUnixMs() - 5_000);
        $worker->runWorkerStep('sendPendingTaskProgressWhenDue', static::buildRunningCall(), new RecordingSidebandWebSocket());
        static::assertSame(4000, $worker->getWorkerState('progressCommentarySentAtSessionMs'), 'the commentary went out at the transcript\'s end');

        $worker->runWorkerStep('startTaskProgressSchedule');

        static::assertNull($worker->getWorkerState('progressCommentarySentAtSessionMs'));
        static::assertSame(0, $worker->getWorkerState('taskProgressSchedule')->spokenProgressCount);
    }

    protected function buildWorkerWithRootedConversation(): StagedAgentLiveVoiceSessionsService
    {
        return $this->buildWorkerWithConversation(static::buildConversation(rootConversationId: static::ROOT_CONVERSATION_ID));
    }

    protected function buildWorkerWithPendingTask(int $delegatedMillisecondsAgo): StagedAgentLiveVoiceSessionsService
    {
        $worker = $this->buildWorkerWithRootedConversation();
        $worker->setWorkerState('pendingDelegationId', 'del_progress');
        $worker->setWorkerState('pendingAgentTaskAfterMessageIndex', 4);
        $worker->setWorkerState('pendingAgentTaskStartedAtUnixTime', time());
        $worker->setWorkerState(
            'taskProgressSchedule',
            AgentVoiceTaskProgressSchedule::startForDelegationAt(AIConversationTraceEvent::nowUnixMs() - $delegatedMillisecondsAgo)
        );

        return $worker;
    }

    /** @param AgentActivityLine[] $activityLines */
    protected static function buildFeed(array $activityLines): AgentActivityTurnFeed
    {
        $agentActivityLines = new AgentActivityLines();
        foreach ($activityLines as $activityLine) {
            $agentActivityLines->add($activityLine);
        }

        return AgentActivityTurnFeed::buildForTurn(static::ROOT_CONVERSATION_ID, 4, 1, $agentActivityLines);
    }

    protected static function buildLine(int $toolCallMessageId, ?string $line, string $toolName = 'getReviews'): AgentActivityLine
    {
        $activityLine = new AgentActivityLine();
        $activityLine->toolCallMessageId = $toolCallMessageId;
        $activityLine->state = AgentActivityLine::STATE_RUNNING;
        $activityLine->line = $line;
        $activityLine->narratorToolName = $toolName;

        return $activityLine;
    }
}
