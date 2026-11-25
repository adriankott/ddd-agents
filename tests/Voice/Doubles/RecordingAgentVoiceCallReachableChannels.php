<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;

/** What reaches the owner, answering a fixed follow-up offer and recording the call it was asked for. */
class RecordingAgentVoiceCallReachableChannels extends AgentVoiceCallReachableChannels
{
    /** @var AgentVoiceCall[] The calls the offer was asked for, in order */
    public array $callsAskedFor = [];

    public function buildUnansweredFollowUpOffer(AgentVoiceCall $agentVoiceCall): string
    {
        $this->callsAskedFor[] = $agentVoiceCall;
        return 'offer the fixed thing.';
    }
}
