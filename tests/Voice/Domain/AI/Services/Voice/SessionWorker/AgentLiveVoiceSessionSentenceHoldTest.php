<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptSentence;
use DDD\Tests\Agents\Voice\Support\AgentVoiceValueObjectAssertionsTrait;

/**
 * The override point `keepCompletedSentenceOutOfTheConversation()` of the session worker: it is handed every
 * completed sentence as a {@see LiveTranscriptSentence} with the sentence's role, text and provider offsets, and the
 * call; a sentence it keeps out becomes no conversation row; one it lets through becomes the row as before.
 */
class AgentLiveVoiceSessionSentenceHoldTest extends AgentLiveVoiceSessionWorkerTestCase
{
    use AgentVoiceValueObjectAssertionsTrait;

    public function testTheOverridePointIsHandedTheCompletedSentenceWithItsValuesAndTheCall(): void
    {
        $worker = $this->buildSentenceHoldingWorker();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'I need my opening hours.', 0, 400));
        static::assertSame([], $worker->handedSentences, 'an open sentence is not handed yet');
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('assistant', 'Sure', 1300, 1600));

        static::assertSameValueObjects([LiveTranscriptSentence::create(AIConversationMessage::ROLE_USER, 'I need my opening hours.', 0, 400)], $worker->handedSentences);
        static::assertSame([static::CALL_ID], $worker->handedCallIds);
    }

    public function testASentenceKeptOutBecomesNoRow(): void
    {
        $worker = $this->buildSentenceHoldingWorker();
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'Let me in.', 0, 400));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('assistant', 'Sure', 1300, 1600));

        static::assertSame([], $worker->agentVoiceCallsService->persistedTranscripts);
    }

    public function testASentenceLetThroughBecomesTheRowAsBefore(): void
    {
        $worker = $this->buildSentenceHoldingWorker();
        $worker->keepsSentencesOut = false;
        $agentVoiceCall = static::buildRunningCall();
        $sidebandWebSocket = new RecordingSidebandWebSocket();

        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('user', 'I need my opening hours.', 0, 400));
        $worker->receiveSessionEvent($agentVoiceCall, $sidebandWebSocket, static::buildTranscriptDelta('assistant', 'Sure', 1300, 1600));

        static::assertSame([AIConversationMessage::ROLE_USER], array_column($worker->agentVoiceCallsService->persistedTranscripts, 'role'));
        static::assertSame(['I need my opening hours.'], array_column($worker->agentVoiceCallsService->persistedTranscripts, 'text'));
        static::assertCount(1, $worker->handedSentences);
    }

    /** The worker of the test with the override, holding the call's conversation (its one read happened). */
    protected function buildSentenceHoldingWorker(): SentenceHoldingAgentLiveVoiceSessionsService
    {
        $aiConversation = $this->fixedAIConversationsService->conversationsById[static::CONVERSATION_ID];
        $worker = new SentenceHoldingAgentLiveVoiceSessionsService();
        $worker->aIConversationsService = $this->fixedAIConversationsService;
        $worker->setWorkerState('attachedAiConversation', $aiConversation);
        $this->workersOfTheTest[] = $worker;
        return $worker;
    }
}
