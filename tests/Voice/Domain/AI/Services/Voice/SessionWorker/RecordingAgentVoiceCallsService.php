<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Voice\Support\AgentVoiceCallFixtures;
use Throwable;

/**
 * The calls service as the session worker uses it: every transcript row and every call end is kept instead of
 * written. `$transcriptWriteFailures` scripts the transcript writes: each write takes the next entry, and an entry that
 * is a throwable is thrown instead of writing (null = the write succeeds).
 */
class RecordingAgentVoiceCallsService extends AgentVoiceCallsService
{
    /** @var array<int, array{conversationId: int|null, role: string, text: string, channelType: string, index: int}> */
    public array $persistedTranscripts = [];

    /** @var array<int, Throwable|null> */
    public array $transcriptWriteFailures = [];

    /** @var array<int, array{callId: int|null, endReason: string}> */
    public array $persistedCallEnds = [];

    public int $nextMessageIndex = 20;

    public function persistTranscriptMessage(
        AIConversation $conversation,
        string $role,
        string $spokenSentence,
        string $callChannelType = AgentVoiceCall::CHANNEL_TYPE_APP
    ): AIConversationMessage {
        $transcriptWriteFailure = array_shift($this->transcriptWriteFailures);
        if ($transcriptWriteFailure instanceof Throwable) {
            throw $transcriptWriteFailure;
        }
        $transcriptMessage = new AIConversationMessage();
        $transcriptMessage->indexInConversation = $this->nextMessageIndex++;
        $this->persistedTranscripts[] = [
            'conversationId' => $conversation->id,
            'role' => $role,
            'text' => $spokenSentence,
            'channelType' => $callChannelType,
            'index' => $transcriptMessage->indexInConversation,
        ];

        return $transcriptMessage;
    }

    public function persistCallEnd(AgentVoiceCall $agentVoiceCall, string $endReason): void
    {
        $this->persistedCallEnds[] = ['callId' => $agentVoiceCall->id, 'endReason' => $endReason];
        $agentVoiceCall->endedAt = DateTime::fromTimestamp(AgentVoiceCallFixtures::NOW_UNIX_TIME);
        $agentVoiceCall->endReason = $endReason;
    }

    /** @return string[] The text of every transcript row, in order */
    public function getPersistedTranscriptTexts(): array
    {
        return array_column($this->persistedTranscripts, 'text');
    }
}
