<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\Summaries\AIConversationMessageWriter;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptBacklogSentence;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptSentence;
use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSummary;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceValueObjectAssertionsTrait;
use RuntimeException;

/**
 * The transcript: the deltas of both sides become sentences, a completed sentence becomes a row of the call's
 * conversation with its provenance in the trace; while a turn runs (the call's own task or one somebody else started)
 * a sentence is held back, and the backlog is written in spoken order once the turn settled; a busy index lock sends
 * the sentence to the backlog instead of losing it; a progress sentence of the agent goes to the trace, never into
 * the conversation; the agent's first words are traced once.
 *
 * A sentence is completed here by the other side starting to speak more than 800 ms after it (the assembler's turn
 * change), by the teardown, or by a delegation: the idle close needs 1.5 s of real time.
 */
class AgentLiveVoiceSessionTranscriptTest extends AgentLiveVoiceSessionWorkerTestCase
{
    use AgentVoiceValueObjectAssertionsTrait;

    public function testACompletedSentenceOfTheCallerBecomesARowOfTheConversation(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'I need my opening hours.', 0, 400));
        static::assertSame([], $worker->agentVoiceCallsService->persistedTranscripts, 'an open sentence is no row yet');
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('assistant', 'Sure', 1300, 1600));

        static::assertSame([[
            'conversationId' => static::CONVERSATION_ID,
            'role' => AIConversationMessage::ROLE_USER,
            'text' => 'I need my opening hours.',
            'channelType' => AgentVoiceCall::CHANNEL_TYPE_APP,
            'index' => 20,
        ]], $worker->agentVoiceCallsService->persistedTranscripts);
        static::assertSame([static::CONVERSATION_ID], $worker->aIConversationMessagesService->contextTailReadConversationIds, 'the index comes from a fresh tail');
        $provenanceEvents = $worker->aIConversationTraceEventsService->findRecordedEventsOfType(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED);
        static::assertCount(1, $provenanceEvents, 'the provenance is written at once, beside the row');
        static::assertSame('call:7:transcript:41:20', $provenanceEvents[0]->eventKey);
        static::assertSame(20, $provenanceEvents[0]->messageIndex);
        static::assertSame('caller sentence persisted 0–0.4 s', $provenanceEvents[0]->summary);
        static::assertSame([
            'writer' => AIConversationMessageWriter::WRITER_VOICE_CALLER_TRANSCRIPT,
            'role' => AIConversationMessage::ROLE_USER,
            'spokenFromMs' => 0,
            'spokenUntilMs' => 400,
            'chars' => 24,
            'fromBacklog' => false,
        ], $provenanceEvents[0]->getPayloadArray());
    }

    public function testACompletedSentenceOfTheAgentBecomesAnAssistantRow(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('assistant', 'Hello, how can I help?', 0, 900));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'Hi', 2000, 2200));

        static::assertSame([AIConversationMessage::ROLE_ASSISTANT], array_column($worker->agentVoiceCallsService->persistedTranscripts, 'role'));
        $provenanceEvents = $worker->aIConversationTraceEventsService->findRecordedEventsOfType(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED);
        static::assertSame(AIConversationMessageWriter::WRITER_VOICE_AGENT_TRANSCRIPT, $provenanceEvents[0]->getPayloadArray()['writer']);
        static::assertSame('Assistant sentence persisted 0–0.9 s', $provenanceEvents[0]->summary, 'the agent is named by the persona');
    }

    public function testACallWithoutAConversationWritesNoRow(): void
    {
        $worker = $this->buildWorker();
        $agentVoiceCall = static::buildRunningCall(conversationId: null);

        $worker->runWorkerStep('persistCompletedSentences', $agentVoiceCall, [
            LiveTranscriptSentence::create(AIConversationMessage::ROLE_USER, 'Anybody there?', 0, 500),
        ]);

        static::assertSame([], $worker->agentVoiceCallsService->persistedTranscripts);
        static::assertSame([], $worker->getWorkerState('transcriptBacklog'));
    }

    public function testASentenceIsHeldBackWhileTheCallsOwnTaskRuns(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('pendingAgentTaskAfterMessageIndex', 3);

        $worker->runWorkerStep('persistCompletedSentences', static::buildRunningCall(), [
            LiveTranscriptSentence::create(AIConversationMessage::ROLE_USER, 'And on Sunday?', 5000, 5600),
        ]);

        static::assertSame([], $worker->agentVoiceCallsService->persistedTranscripts);
        static::assertSameValueObjects([
            LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_USER, 'And on Sunday?', 5000, 5600, false),
        ], $worker->getWorkerState('transcriptBacklog'));
        $pushedEvents = static::findBufferedTraceEvents($worker, AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_BACKLOG_PUSHED);
        static::assertCount(1, $pushedEvents);
        static::assertSame('caller sentence held back while a turn runs (14 chars)', $pushedEvents[0]->summary);
        static::assertSame(5000, $pushedEvents[0]->providerOffsetMs);
        static::assertSame(['role' => AIConversationMessage::ROLE_USER, 'startMs' => 5000, 'endMs' => 5600], $pushedEvents[0]->getPayloadArray());
    }

    public function testASentenceIsHeldBackWhileAnotherTurnRunsAndReleasedOnceItSettled(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $this->fixedAIConversationsService->freshRunStates = [AIConversationRunState::RUNNING, AIConversationRunState::RUNNING, AIConversationRunState::IDLE];

        $worker->runWorkerStep('persistCompletedSentences', $agentVoiceCall, [
            LiveTranscriptSentence::create(AIConversationMessage::ROLE_USER, 'Is it done?', 0, 500),
        ]);
        static::assertSame([], $worker->agentVoiceCallsService->persistedTranscripts);
        static::assertTrue($worker->getWorkerState('foreignTurnObservedRunning'));

        $worker->runWatcherTickOn($agentVoiceCall, new RecordingSidebandWebSocket());
        static::assertSame([], $worker->agentVoiceCallsService->persistedTranscripts, 'still running: still held back');

        $worker->runWatcherTickOn($agentVoiceCall, new RecordingSidebandWebSocket());
        static::assertSame(['Is it done?'], $worker->agentVoiceCallsService->getPersistedTranscriptTexts(), 'released the tick the run settled');
        static::assertSame([], $worker->getWorkerState('transcriptBacklog'));
        static::assertSame(3, $this->fixedAIConversationsService->countFreshReads());
    }

    public function testTheBacklogIsWrittenInSpokenOrder(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('transcriptBacklog', [
            LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_USER, 'First the caller.', 1000, 1500, false),
            LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_ASSISTANT, 'Then the agent.', 2000, 2500, false),
            LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_USER, 'Then the caller again.', 3000, 3500, false),
        ]);

        $worker->runWorkerStep('flushTranscriptBacklog', static::buildRunningCall());

        static::assertSame(['First the caller.', 'Then the agent.', 'Then the caller again.'], $worker->agentVoiceCallsService->getPersistedTranscriptTexts());
        static::assertSame([20, 21, 22], array_column($worker->agentVoiceCallsService->persistedTranscripts, 'index'));
        static::assertSame([], $worker->getWorkerState('transcriptBacklog'));
        static::assertSame([static::CONVERSATION_ID], $worker->aIConversationMessagesService->contextTailReadConversationIds, 'one fresh tail before the first row');
        $provenanceEvents = $worker->aIConversationTraceEventsService->findRecordedEventsOfType(AIConversationTraceEvent::EVENT_TYPE_TRANSCRIPT_SENTENCE_PERSISTED);
        static::assertSame([true, true, true], array_map(
            static fn (AIConversationTraceEvent $provenanceEvent): bool => $provenanceEvent->getPayloadArray()['fromBacklog'],
            $provenanceEvents
        ));
        static::assertSame('caller sentence persisted 1–1.5 s (from the backlog)', $provenanceEvents[0]->summary);
        $flushedEvents = static::findBufferedTraceEvents($worker, 'transcript.backlog_flushed');
        static::assertSame('3 held-back sentences written', $flushedEvents[0]->summary);
    }

    public function testTheTeardownWritesTheBacklogBeforeTheSentencesStillOpen(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('usageEventReceived', true);
        $agentVoiceCall = static::buildRunningCall();
        $worker->setWorkerState('transcriptBacklog', [
            LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_USER, 'Said while the turn ran.', 1000, 1500, false),
        ]);
        $worker->receiveSessionEvent($agentVoiceCall, new RecordingSidebandWebSocket(), static::buildTranscriptDelta('assistant', 'Said last', 9000, 9500));

        $worker->runWorkerStep('finishCall', $agentVoiceCall, AgentVoiceCall::END_REASON_COMPLETED);

        static::assertSame(['Said while the turn ran.', 'Said last'], $worker->agentVoiceCallsService->getPersistedTranscriptTexts());
    }

    public function testABusyIndexLockSendsTheSentenceToTheBacklog(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->agentVoiceCallsService->transcriptWriteFailures = [new MessageIndexLockUnavailableException(static::CONVERSATION_ID)];

        $worker->runWorkerStep('persistTranscriptSentenceNow', static::buildRunningCall(), AIConversationMessage::ROLE_USER, 'Do not lose me.', 100, 900);

        static::assertSame([], $worker->agentVoiceCallsService->persistedTranscripts);
        static::assertSameValueObjects([
            LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_USER, 'Do not lose me.', 100, 900, false),
        ], $worker->getWorkerState('transcriptBacklog'));
        static::assertSame([], $worker->aIConversationTraceEventsService->recordedSingleEvents, 'no provenance without a row');
    }

    public function testAFailedFlushKeepsTheUnwrittenSentencesInOrder(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->agentVoiceCallsService->transcriptWriteFailures = [null, new RuntimeException('the row write timed out')];
        $secondSentence = LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_ASSISTANT, 'Second.', 2000, 2500, false);
        $thirdSentence = LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_USER, 'Third.', 3000, 3500, false);
        $worker->setWorkerState('transcriptBacklog', [
            LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_USER, 'First.', 1000, 1500, false),
            $secondSentence,
            $thirdSentence,
        ]);

        $worker->runWorkerStep('flushTranscriptBacklog', static::buildRunningCall());

        static::assertSame(['First.'], $worker->agentVoiceCallsService->getPersistedTranscriptTexts());
        static::assertSame([$secondSentence, $thirdSentence], $worker->getWorkerState('transcriptBacklog'));
        static::assertSame(
            ['LIVE live_session_seven transcript backlog flush failed (2 sentences kept for the next flush): the row write timed out'],
            $worker->findVoiceLogLinesContaining('backlog flush failed')
        );
    }

    public function testAProgressSentenceGoesToTheTraceAndNeverIntoTheConversation(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('transcriptBacklog', [
            LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_ASSISTANT, 'I am reading the reviews now.', 5000, 6500, true),
            LiveTranscriptBacklogSentence::create(AIConversationMessage::ROLE_USER, 'Okay.', 7000, 7300, false),
        ]);

        $worker->runWorkerStep('flushTranscriptBacklog', static::buildRunningCall());

        static::assertSame(['Okay.'], $worker->agentVoiceCallsService->getPersistedTranscriptTexts());
        $progressEvents = static::findBufferedTraceEvents($worker, AgentLiveVoiceSessionsService::TRACE_EVENT_TYPE_TASK_PROGRESS_SPOKEN);
        static::assertCount(1, $progressEvents);
        static::assertSame('Assistant said a progress sentence: I am reading the reviews now.', $progressEvents[0]->summary);
        static::assertSame(['text' => 'I am reading the reviews now.', 'spokenFromMs' => 5000, 'spokenUntilMs' => 6500], $progressEvents[0]->getPayloadArray());
        static::assertSame(5000, $progressEvents[0]->providerOffsetMs);
    }

    public function testAnAgentSentenceStartedAfterTheProgressCommentaryIsClassifiedAsProgressWhenHeldBack(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('pendingAgentTaskAfterMessageIndex', 4);
        $worker->setWorkerState('progressCommentarySentAtSessionMs', 4000);

        $worker->runWorkerStep('persistCompletedSentences', static::buildRunningCall(), [
            LiveTranscriptSentence::create(AIConversationMessage::ROLE_ASSISTANT, 'One moment, I am checking.', 3000, 4000),
            LiveTranscriptSentence::create(AIConversationMessage::ROLE_ASSISTANT, 'I am reading the reviews now.', 5000, 6500),
            LiveTranscriptSentence::create(AIConversationMessage::ROLE_USER, 'Okay, thanks.', 7000, 7800),
        ]);

        static::assertSame([false, true, false], array_column($worker->getWorkerState('transcriptBacklog'), 'isProgress'));
    }

    public function testNoAgentSentenceIsProgressWithoutAnOutstandingCommentary(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $worker->setWorkerState('pendingAgentTaskAfterMessageIndex', 4);

        $worker->runWorkerStep('persistCompletedSentences', static::buildRunningCall(), [
            LiveTranscriptSentence::create(AIConversationMessage::ROLE_ASSISTANT, 'Let me look that up.', 5000, 6000),
        ]);

        static::assertSame([false], array_column($worker->getWorkerState('transcriptBacklog'), 'isProgress'));
    }

    public function testTheFirstAgentTranscriptIsTracedOnce(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('assistant', '', 100, 100));
        static::assertSame([], static::findTraceEvents($worker, AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT), 'an empty delta is no word');
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('assistant', 'Hello', 250, 500));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('assistant', ' there', 600, 900));

        $firstTranscriptEvents = static::findTraceEvents($worker, AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT);
        static::assertCount(1, $firstTranscriptEvents);
        static::assertSame(
            $firstTranscriptEvents,
            $worker->aIConversationTraceEventsService->findRecordedEventsOfType(AgentVoiceCallTraceSummary::EVENT_TYPE_FIRST_AGENT_TRANSCRIPT),
            'written at once, not on the next tick'
        );
        static::assertSame('agent.first_transcript', $firstTranscriptEvents[0]->eventType);
        static::assertSame(250, $firstTranscriptEvents[0]->providerOffsetMs);
        static::assertSame(AIConversationTraceEvent::SOURCE_VOICE_PROVIDER, $firstTranscriptEvents[0]->source);
        static::assertSame('Assistant\'s first words on the transcript', $firstTranscriptEvents[0]->summary);
    }

    public function testReceivedEventsEnterTheTraceButNeitherAudioNorTheDeltas(): void
    {
        $worker = $this->buildWorkerWithConversation();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, ['type' => 'session.started', 'event_id' => 'evt_provider_1']);
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, ['type' => 'session.output_audio.delta', 'delta' => 'UklGRg==']);
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'Hi', 0, 200));

        $bufferedEventTypes = array_map(
            static fn (AIConversationTraceEvent $bufferedTraceEvent): string => (string)$bufferedTraceEvent->eventType,
            $worker->getWorkerState('traceEventBuffer')
        );
        static::assertSame(['session.started'], $bufferedEventTypes);
        static::assertSame('call:7:session.started:pevt_provider_1', $worker->getWorkerState('traceEventBuffer')[0]->eventKey, 'the provider\'s event id is the key');
        static::assertSame([], $worker->findVoiceLogLinesContaining('audio'), 'no audio in the voice log');
        static::assertTrue($worker->getWorkerState('sessionStartedReceived'));
    }
}
