<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\LiveTranscriptSentence;

/**
 * An application's session worker that takes completed sentences out of the conversation (the override point
 * `keepCompletedSentenceOutOfTheConversation()`): it keeps every sentence it is handed, with the call, and answers
 * {@see $keepsSentencesOut}.
 */
class SentenceHoldingAgentLiveVoiceSessionsService extends StagedAgentLiveVoiceSessionsService
{
    public bool $keepsSentencesOut = true;

    /** @var LiveTranscriptSentence[] Every sentence the override point was handed, in order */
    public array $handedSentences = [];

    /** @var array<int, int|null> The id of the call of every handed sentence */
    public array $handedCallIds = [];

    protected function keepCompletedSentenceOutOfTheConversation(AgentVoiceCall $agentVoiceCall, LiveTranscriptSentence $completedSentence): bool
    {
        $this->handedSentences[] = $completedSentence;
        $this->handedCallIds[] = $agentVoiceCall->id;
        return $this->keepsSentencesOut;
    }
}
