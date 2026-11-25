<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallFollowUpsService;

/** The follow-up service, recording each call it is asked to follow up on (id and end reason at that moment). */
class RecordingAgentVoiceCallFollowUpsService extends AgentVoiceCallFollowUpsService
{
    /** @var array<int, array{callId: int, endReason: ?string}> */
    public array $followUpQuestions = [];

    public function startFollowUpTurnIfAgentCallWentUnanswered(AgentVoiceCall $agentVoiceCall): bool
    {
        $this->followUpQuestions[] = ['callId' => (int)$agentVoiceCall->id, 'endReason' => $agentVoiceCall->endReason];

        return false;
    }
}
