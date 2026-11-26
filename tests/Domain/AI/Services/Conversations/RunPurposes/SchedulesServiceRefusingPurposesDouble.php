<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunPurposes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;

/** A schedules service of an application whose own rule refuses every purpose but the default. */
class SchedulesServiceRefusingPurposesDouble extends AIConversationSchedulesService
{
    protected function isPurposeAllowedForSchedule(AIConversationPurpose $aIConversationPurpose, AIConversationSchedule $schedule, ?AgentSpace $agentSpace): bool
    {
        return false;
    }
}
