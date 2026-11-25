<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Voice\TraceEvents\AgentVoiceCallTraceSummary;

/** An application's call header whose stored events carry an earlier spelling of the agent's first transcript. */
class EarlierSpellingAgentVoiceCallTraceSummary extends AgentVoiceCallTraceSummary
{
    public const string EVENT_TYPE_FIRST_AGENT_TRANSCRIPT = 'earlier.first_transcript';
}
