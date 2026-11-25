<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;

/**
 * The call log of an application with a further medium, written the way such an application overrides the set: its
 * summary line adds the count of the kiosk calls ({@see AnotherMediumAgentVoiceCall}) among the set's own elements.
 */
class KioskCountingAgentVoiceCalls extends AgentVoiceCalls
{
    protected function buildLlmMarkdownSummaryLine(bool $onlyOpenCalls): string
    {
        $kioskCallCount = 0;
        foreach ($this->getElements() as $agentVoiceCall) {
            if ($agentVoiceCall->getChannelType() === AnotherMediumAgentVoiceCall::CHANNEL_TYPE_KIOSK) {
                $kioskCallCount++;
            }
        }

        return parent::buildLlmMarkdownSummaryLine($onlyOpenCalls) . ' (' . $kioskCallCount . ' by kiosk)';
    }
}
