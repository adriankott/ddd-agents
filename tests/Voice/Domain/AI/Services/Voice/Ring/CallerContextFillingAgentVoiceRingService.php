<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceRingCallerContext;
use DDD\Domain\AI\Services\Voice\AgentVoiceRingService;

/**
 * An application's ring service that fills its caller context ({@see ApplicationAgentVoiceRingCallerContext}) in the
 * override point `buildCallerContext()`, from the class `AgentVoiceRingCallerContext::newInstance()` answers.
 */
class CallerContextFillingAgentVoiceRingService extends AgentVoiceRingService
{
    public const int AGENT_SPACE_ID = 12;

    public const string DISPLAY_NAME = 'Front desk "north"';

    protected function buildCallerContext(AgentVoiceCall $agentVoiceCall, ?AgentSpace $agentSpace): AgentVoiceRingCallerContext
    {
        $agentVoiceRingCallerContext = AgentVoiceRingCallerContext::newInstance();
        if ($agentVoiceRingCallerContext instanceof ApplicationAgentVoiceRingCallerContext) {
            $agentVoiceRingCallerContext->agentSpaceId = static::AGENT_SPACE_ID;
            $agentVoiceRingCallerContext->displayName = static::DISPLAY_NAME;
        }
        return $agentVoiceRingCallerContext;
    }
}
