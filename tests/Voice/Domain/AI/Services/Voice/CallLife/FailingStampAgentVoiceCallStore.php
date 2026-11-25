<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use RuntimeException;

/** The in-memory call store whose stamp write records the question, then throws. */
class FailingStampAgentVoiceCallStore extends InMemoryAgentVoiceCallStore
{
    public function markCallStartedByAgentInConversation(AgentVoiceCall $agentVoiceCall, AIConversation $conversation): void
    {
        $this->record('markCallStartedByAgentInConversation', [(int)$agentVoiceCall->id, (int)$conversation->id]);
        throw new RuntimeException('row locked');
    }
}
