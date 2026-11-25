<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Voice\Calls\AgentCallTaskOutcome;
use DDD\Domain\AI\Entities\Voice\Calls\AgentCallTaskStart;
use DDD\Domain\AI\Services\Voice\AgentCallTasksService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The delegation: `session.delegation.created` carries no text — what the caller said is already the transcript,
 * so the open sentence is closed into a row and the agent turn is started on it. One task at a time: a second
 * delegation is queued and started once the first answer is handed over; a conversation already running gets the
 * delegation attached to its turn; a task nobody works on is driven again once after 75 s, the second time the caller
 * is told; the answer goes back as ONE commentary append, cut to the provider's cap.
 */
class AgentLiveVoiceSessionDelegationTest extends AgentLiveVoiceSessionWorkerTestCase
{
    public function testADelegationTurnsWhatTheCallerSaidIntoAnAgentTurn(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentCallTasksService->taskStartAnswers = [AgentCallTaskStart::forStarted(21)];

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'What are my opening hours', 0, 900));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildDelegation('del_first'));

        static::assertSame(['What are my opening hours'], $worker->agentVoiceCallsService->getPersistedTranscriptTexts(), 'the open sentence is closed into a row first');
        static::assertSame([static::CONVERSATION_ID], $worker->agentCallTasksService->taskStartConversationIds);
        static::assertSame(21, $worker->getWorkerState('pendingAgentTaskAfterMessageIndex'));
        static::assertSame('del_first', $worker->getWorkerState('pendingDelegationId'));
        static::assertFalse($worker->getWorkerState('pendingAgentTaskRedriven'));
        static::assertSame(
            ['LIVE live_session_seven delegation del_first → agent task on the transcript (after message index 21, 25 chars of caller speech)'],
            $worker->findVoiceLogLinesContaining('delegation del_first')
        );
        $taskStartedEvents = $worker->aIConversationTraceEventsService->findRecordedEventsOfType(AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED);
        static::assertCount(1, $taskStartedEvents, 'flushed at once');
        static::assertSame('agent turn started on the transcript (25 chars of caller speech)', $taskStartedEvents[0]->summary);
        static::assertSame(21, $taskStartedEvents[0]->messageIndex);
        static::assertSame([], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.thinking.append'), 'the voice model is told nothing: the answer follows');
    }

    public function testADelegationWithNothingSaidAsksTheCallerToRepeat(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->receiveSessionEvent(static::buildRunningCall(), $sidebandWebSocket, static::buildDelegation('del_empty'));

        static::assertSame([], $worker->agentCallTasksService->taskStartConversationIds);
        static::assertSame([[
            'type' => 'session.thinking.append',
            'delegation_id' => 'del_empty',
            'content' => 'The caller\'s request was not captured. Ask them briefly to repeat what they need.',
        ]], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.thinking.append'));
        static::assertNull($worker->getWorkerState('pendingAgentTaskAfterMessageIndex'));
    }

    public function testADelegationWithoutAnIdIsIgnored(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $agentVoiceCall = static::buildRunningCall();

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'Hello', 0, 300));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, ['type' => 'session.delegation.created', 'delegation' => []]);

        static::assertSame([], $worker->agentCallTasksService->taskStartConversationIds);
        static::assertSame([], $sidebandWebSocket->sentClientEvents);
    }

    public function testADelegationOfACallWithoutAConversationIsTakenDownAsAMessage(): void
    {
        $worker = $this->buildWorker();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $agentVoiceCall = static::buildRunningCall(conversationId: null);

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'Call me back', 0, 600));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildDelegation('del_orphan'));

        static::assertSame([], $worker->agentCallTasksService->taskStartConversationIds);
        static::assertSame(
            'No backend conversation is attached to this call — take the request down as a message and say so honestly.',
            $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.thinking.append')[0]['content']
        );
    }

    public function testASecondDelegationIsQueuedAndStartedOnceTheFirstAnswerIsHandedOver(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentCallTasksService->taskStartAnswers = [
            AgentCallTaskStart::forStarted(21),
            AgentCallTaskStart::forStarted(24),
        ];
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'What are my opening hours', 0, 900));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildDelegation('del_first'));

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'And my phone number', 5000, 5800));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildDelegation('del_second'));

        static::assertSame([static::CONVERSATION_ID], $worker->agentCallTasksService->taskStartConversationIds, 'one task at a time');
        static::assertSame('del_second', $worker->getWorkerState('queuedDelegation')->delegationId);
        static::assertSame([[
            'type' => 'session.thinking.append',
            'delegation_id' => 'del_second',
            'content' => 'The backend is still working on the previous request; what the caller just said goes to it right'
                . ' after that result. Bridge briefly, do not start over and do not guess.',
        ]], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.thinking.append'));
        static::assertSame(['delegation queued behind the running task'], array_map(
            static fn (AIConversationTraceEvent $queuedEvent): string => (string)$queuedEvent->summary,
            static::findTraceEvents($worker, 'delegation.queued')
        ));

        $worker->agentCallTasksService->taskOutcomeAnswers = [AgentCallTaskOutcome::forCompleted('Nine to five.')];
        $worker->runFastCheckOn($agentVoiceCall, $sidebandWebSocket);

        static::assertSame([[
            'type' => 'session.commentary.append',
            'delegation_id' => 'del_first',
            'content' => 'Nine to five.',
        ]], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.commentary.append'));
        static::assertSame([static::CONVERSATION_ID, static::CONVERSATION_ID], $worker->agentCallTasksService->taskStartConversationIds, 'the queued one starts');
        static::assertSame('del_second', $worker->getWorkerState('pendingDelegationId'));
        static::assertSame(24, $worker->getWorkerState('pendingAgentTaskAfterMessageIndex'));
        static::assertNull($worker->getWorkerState('queuedDelegation'));
        $taskStartedEvents = static::findTraceEvents($worker, AIConversationTraceEvent::EVENT_TYPE_TASK_STARTED);
        static::assertSame(['correlationIds' => ['delegationId' => 'del_second'], 'requestChars' => 19, 'startedFromQueue' => true], $taskStartedEvents[1]->getPayloadArray());
        static::assertSame('agent turn started on the transcript (19 chars of caller speech), from the queue', $taskStartedEvents[1]->summary);
    }

    public function testAQueuedDelegationWithNothingNewSaidContinuesFromTheSpokenResult(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentCallTasksService->taskStartAnswers = [AgentCallTaskStart::forStarted(21)];
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'What are my opening hours', 0, 900));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildDelegation('del_first'));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildDelegation('del_again'));

        $worker->agentCallTasksService->taskOutcomeAnswers = [AgentCallTaskOutcome::forCompleted('Nine to five.')];
        $worker->runFastCheckOn($agentVoiceCall, $sidebandWebSocket);

        static::assertCount(1, $worker->agentCallTasksService->taskStartConversationIds);
        static::assertSame(
            'The caller said nothing new since the previous request — the result just spoken covers it; continue from there.',
            $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.thinking.append')[1]['content']
        );
        static::assertNull($worker->getWorkerState('pendingAgentTaskAfterMessageIndex'));
    }

    /** @return array<string, array{0: int|null, 1: int}> */
    public static function provideNewestIndexOfTheRunningTurn(): array
    {
        return [
            'rows written so far' => [33, 33],
            'no row yet' => [null, 0],
        ];
    }

    #[DataProvider('provideNewestIndexOfTheRunningTurn')]
    public function testABusyConversationGetsTheDelegationAttachedToItsRunningTurn(?int $newestMessageIndex, int $expectedTaskAfterMessageIndex): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentCallTasksService->taskStartAnswers = [AgentCallTaskStart::forBusy()];
        $worker->aIConversationMessagesService->newestMessageIndex = $newestMessageIndex;

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'Is the post out', 0, 700));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildDelegation('del_busy'));

        static::assertSame($expectedTaskAfterMessageIndex, $worker->getWorkerState('pendingAgentTaskAfterMessageIndex'));
        static::assertSame('del_busy', $worker->getWorkerState('pendingDelegationId'));
        static::assertSame([[
            'type' => 'session.thinking.append',
            'delegation_id' => 'del_busy',
            'content' => 'The backend is already working on it; the result follows as spoken commentary. Bridge briefly, do not guess.',
        ]], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.thinking.append'));
        static::assertSame(
            ["LIVE live_session_seven delegation del_busy attached to the running turn (after message index {$expectedTaskAfterMessageIndex})"],
            $worker->findVoiceLogLinesContaining('attached to the running turn')
        );
    }

    public function testARefusedTaskStartIsToldToTheVoiceModel(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentCallTasksService->taskStartAnswers = [AgentCallTaskStart::forError(AgentCallTaskStart::ERROR_CONVERSATION_NOT_FOUND)];

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'Book a table', 0, 700));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildDelegation('del_refused'));

        static::assertSame(
            'The backend could not take the request right now (conversation_not_found). Tell the caller honestly and say what you can do instead.',
            $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.thinking.append')[0]['content']
        );
        static::assertNull($worker->getWorkerState('pendingAgentTaskAfterMessageIndex'));
    }

    public function testATaskStillWorkingIsLeftAloneBeforeItIsLost(): void
    {
        $worker = $this->buildWorkerWithPendingTask(startedSecondsAgo: 70);

        $worker->runFastCheckOn(static::buildRunningCall(), new RecordingSidebandWebSocket());

        static::assertSame([], $worker->agentCallTasksService->taskStartConversationIds);
        static::assertSame(0, $this->fixedAIConversationsService->countFreshReads(), 'no run-state read before the task is old enough');
        static::assertSame(21, $worker->getWorkerState('pendingAgentTaskAfterMessageIndex'));
    }

    public function testAnOldTaskOfARunningConversationIsNotLost(): void
    {
        $worker = $this->buildWorkerWithPendingTask(startedSecondsAgo: 200);
        $this->fixedAIConversationsService->freshRunStates = [AIConversationRunState::RUNNING];

        $worker->runFastCheckOn(static::buildRunningCall(), new RecordingSidebandWebSocket());

        static::assertSame([], $worker->agentCallTasksService->taskStartConversationIds);
        static::assertFalse($worker->getWorkerState('pendingAgentTaskRedriven'));
    }

    public function testALostTaskIsDrivenAgainOnceThenTheCallerIsTold(): void
    {
        $worker = $this->buildWorkerWithPendingTask(startedSecondsAgo: 80);
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentCallTasksService->taskStartAnswers = [AgentCallTaskStart::forStarted(23)];
        $this->fixedAIConversationsService->freshRunStates = [AIConversationRunState::IDLE, AIConversationRunState::IDLE];

        $worker->runFastCheckOn($agentVoiceCall, $sidebandWebSocket);

        static::assertSame([static::CONVERSATION_ID], $worker->agentCallTasksService->taskStartConversationIds);
        static::assertTrue($worker->getWorkerState('pendingAgentTaskRedriven'));
        static::assertSame(23, $worker->getWorkerState('pendingAgentTaskAfterMessageIndex'), 'the re-driven task is the one waited for');
        $redriveLines = $worker->findVoiceLogLinesContaining('re-driven once');
        static::assertCount(1, $redriveLines);
        static::assertMatchesRegularExpression('/^LIVE live_session_seven delegated task lost after 8[01]s \(conversation idle, no answer\) — re-driven once: ok$/', $redriveLines[0]);
        static::assertCount(1, static::findTraceEvents($worker, 'task.redriven'));
        static::assertSame([], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.commentary.append'));

        $worker->runFastCheckOn($agentVoiceCall, $sidebandWebSocket);
        static::assertCount(1, $worker->agentCallTasksService->taskStartConversationIds, 'the clock restarted with the re-drive');

        $worker->setWorkerState('pendingAgentTaskStartedAtUnixTime', time() - 80);
        $worker->runFastCheckOn($agentVoiceCall, $sidebandWebSocket);

        static::assertCount(1, $worker->agentCallTasksService->taskStartConversationIds, 'driven again only once');
        static::assertSame(['LIVE live_session_seven delegated task lost twice — reporting the failure to the caller'], $worker->findVoiceLogLinesContaining('lost twice'));
        static::assertSame([[
            'type' => 'session.commentary.append',
            'delegation_id' => 'del_first',
            'content' => 'The backend task failed: the backend did not pick the request up. Tell the caller honestly and say what you can do instead.',
        ]], $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.commentary.append'));
        static::assertNull($worker->getWorkerState('pendingAgentTaskAfterMessageIndex'));
    }

    public function testTheAnswerIsCutToTheAppendCapInOneAppend(): void
    {
        $worker = $this->buildWorkerWithPendingTask(startedSecondsAgo: 3);
        $worker->voiceProviderAdapter->appendMaximumCharacters = 120;
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $answerText = 'The shop opens at nine on weekdays. It closes at six in the evening. '
            . 'On Sundays it stays closed the whole day, and on public holidays it opens late.';
        $worker->agentCallTasksService->taskOutcomeAnswers = [AgentCallTaskOutcome::forCompleted($answerText)];

        $worker->runFastCheckOn(static::buildRunningCall(), $sidebandWebSocket);

        $commentaryEvents = $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.commentary.append');
        static::assertCount(1, $commentaryEvents, 'one append, never several pieces');
        $commentaryText = $commentaryEvents[0]['content'];
        static::assertLessThanOrEqual(120, mb_strlen($commentaryText));
        static::assertStringStartsWith('The shop opens at nine on weekdays.', $commentaryText);
        static::assertStringNotContainsString('public holidays', $commentaryText, 'the rest of the answer is dropped');
        $outcomeEvents = static::findTraceEvents($worker, AIConversationTraceEvent::EVENT_TYPE_TASK_OUTCOME);
        static::assertSame(mb_strlen($answerText), $outcomeEvents[0]->getPayloadArray()['answerChars']);
        static::assertSame(mb_strlen($commentaryText), $outcomeEvents[0]->getPayloadArray()['commentaryChars']);
        static::assertStringContainsString(', cut)', (string)$outcomeEvents[0]->summary);
    }

    public function testAnAnswerEndingInAQuestionWaitsForTheCallersAnswer(): void
    {
        $worker = $this->buildWorkerWithPendingTask(startedSecondsAgo: 3);
        $worker->agentCallTasksService->taskOutcomeAnswers = [AgentCallTaskOutcome::forCompleted('Which day do you mean?')];

        $worker->runFastCheckOn(static::buildRunningCall(), new RecordingSidebandWebSocket());

        static::assertIsInt($worker->getWorkerState('callerAnswerAwaitedSinceUnixTime'));
        static::assertFalse($worker->getWorkerState('unansweredQuestionReasked'));
        static::assertNull($worker->getWorkerState('taskProgressSchedule'), 'the hand-over ends the progress');
    }

    public function testAFailedTaskIsToldAsSuch(): void
    {
        $worker = $this->buildWorkerWithPendingTask(startedSecondsAgo: 3);
        $sidebandWebSocket = new RecordingSidebandWebSocket();
        $worker->agentCallTasksService->taskOutcomeAnswers = [AgentCallTaskOutcome::forFailed('the review store timed out')];

        $worker->runFastCheckOn(static::buildRunningCall(), $sidebandWebSocket);

        static::assertSame(
            'The backend task failed: the review store timed out. Tell the caller honestly and say what you can do instead.',
            $sidebandWebSocket->findSentClientEventsOfTypeWithoutEventIds('session.commentary.append')[0]['content']
        );
        static::assertNull($worker->getWorkerState('callerAnswerAwaitedSinceUnixTime'));
    }

    protected function buildWorkerWithPendingTask(int $startedSecondsAgo): StagedAgentLiveVoiceSessionsService
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('pendingAgentTaskAfterMessageIndex', 21);
        $worker->setWorkerState('pendingDelegationId', 'del_first');
        $worker->setWorkerState('pendingAgentTaskStartedAtUnixTime', time() - $startedSecondsAgo);
        $worker->setWorkerState('pendingAgentTaskRedriven', false);

        return $worker;
    }

    /** @return array<string, mixed> */
    protected static function buildDelegation(string $delegationId): array
    {
        return ['type' => 'session.delegation.created', 'delegation' => ['id' => $delegationId]];
    }
}
