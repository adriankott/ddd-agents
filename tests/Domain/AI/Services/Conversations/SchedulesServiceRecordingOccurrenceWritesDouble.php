<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;

/**
 * The schedules service with the space of a schedule given by the test; what a spawn writes — a status, a refund of
 * the claimed occurrence, the notice of a used-up pot — is recorded in order instead of stored or sent.
 */
class SchedulesServiceRecordingOccurrenceWritesDouble extends AIConversationSchedulesService
{
    /** @var AgentSpace|null The space every schedule is answered with */
    public ?AgentSpace $fixtureAgentSpace = null;

    /** @var string[] The writes the spawn asked for, in order */
    public array $recordedWrites = [];

    protected function findAgentSpaceOfSchedule(AIConversationSchedule $schedule): ?AgentSpace
    {
        return $this->fixtureAgentSpace;
    }

    public function transitionStatus(AIConversationSchedule $schedule, string $status): void
    {
        $this->recordedWrites[] = 'status ' . $status;
        $schedule->status = $status;
    }

    public function transitionStatusForAutomationState(AIConversationSchedule $schedule, string $status, ?string $stateReason): void
    {
        $this->recordedWrites[] = 'status ' . $status . ' for ' . $stateReason;
        $schedule->status = $status;
    }

    public function refundClaimedOccurrence(AIConversationSchedule $schedule, bool $reArmNow): void
    {
        $this->recordedWrites[] = $reArmNow ? 'refund, re-armed now' : 'refund';
    }

    protected function notifyOwnerOncePerMonthThatScheduleBudgetPotIsExhausted(AIConversationSchedule $schedule): void
    {
        $this->recordedWrites[] = 'notice of the used-up pot';
    }
}
