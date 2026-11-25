<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedules;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Entities\Conversations\Schedules\RecurringScheduleMonthlyCostEstimate;
use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleBudgetPotStatus;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use RuntimeException;

/**
 * A schedules service without a database for the creating tools: the schedules of the scope, the recurring schedules
 * created today and the immediate start are scripted; it records what the tool asked and the schedule it was handed.
 */
class CreationRecordingSchedulesServiceDouble extends AIConversationSchedulesService
{
    public const int CREATED_SCHEDULE_ID = 3999;

    public const int STARTED_RUN_CONVERSATION_ID = 880999;

    /** @var int ACTIVE schedules the scope already has */
    public int $fixtureActiveScheduleCount = 0;

    /** @var int PAUSED schedules the scope already has */
    public int $fixturePausedScheduleCount = 0;

    /** @var int Recurring schedules the agent created in the scope since the start of the creation day */
    public int $fixtureRecurringSchedulesCreatedToday = 0;

    /** @var bool The dispatch of the immediate start throws */
    public bool $fixtureImmediateStartThrows = false;

    /** @var bool The immediate start finds the budget pot used up: it expires the schedule and starts no run */
    public bool $fixtureImmediateStartFindsThePotUsedUp = false;

    /** @var string[] The writes and starts the tool asked for, in order */
    public array $recordedServiceCalls = [];

    /** @var string[] The reads of the scope the tool asked for, in order */
    public array $scopeReads = [];

    /** @var DateTime[] The start moments the creations of the day were counted from */
    public array $creationCountStarts = [];

    public ?AIConversationSchedule $lastCreatedSchedule = null;

    public function createSchedule(AIConversationSchedule $schedule): AIConversationSchedule
    {
        $this->recordedServiceCalls[] = 'createSchedule';
        $schedule->id = static::CREATED_SCHEDULE_ID;
        $this->lastCreatedSchedule = $schedule;
        return $schedule;
    }

    public function startScheduleNow(AIConversationSchedule $schedule): ?AIConversation
    {
        $this->recordedServiceCalls[] = 'startScheduleNow';
        if ($this->fixtureImmediateStartThrows) {
            throw new RuntimeException('The dispatch infrastructure is unavailable.');
        }
        if ($this->fixtureImmediateStartFindsThePotUsedUp) {
            $schedule->status = AIConversationScheduleStatus::EXPIRED;
            return null;
        }
        $startedRunConversation = new AIConversation();
        $startedRunConversation->id = static::STARTED_RUN_CONVERSATION_ID;
        return $startedRunConversation;
    }

    public function refundClaimedOccurrence(AIConversationSchedule $schedule, bool $reArmNow): void
    {
        $this->recordedServiceCalls[] = 'refundClaimedOccurrence' . ($reArmNow ? ' reArmNow' : '');
    }

    public function findSchedulesOfAgentSpace(AgentSpace $agentSpace, bool $includeArchived = false): AIConversationSchedules
    {
        $this->scopeReads[] = 'schedules of space ' . $agentSpace->id;
        return $this->buildSchedulesOfScope();
    }

    public function findSchedulesOfAccountWithoutAgentSpace(Account $account, bool $includeArchived = false): AIConversationSchedules
    {
        $this->scopeReads[] = 'schedules of account ' . $account->id;
        return $this->buildSchedulesOfScope();
    }

    public function countAgentRecurringSchedulesCreatedForAgentSpaceSince(AgentSpace $agentSpace, DateTime $since): int
    {
        $this->scopeReads[] = 'creations of space ' . $agentSpace->id;
        $this->creationCountStarts[] = $since;
        return $this->fixtureRecurringSchedulesCreatedToday;
    }

    public function countAgentRecurringSchedulesCreatedForAccountWithoutAgentSpaceSince(Account $account, DateTime $since): int
    {
        $this->scopeReads[] = 'creations of account ' . $account->id;
        $this->creationCountStarts[] = $since;
        return $this->fixtureRecurringSchedulesCreatedToday;
    }

    public function buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpace(AgentSpace $agentSpace): RecurringScheduleMonthlyCostEstimate
    {
        $this->scopeReads[] = 'monthly cost of space ' . $agentSpace->id;
        return RecurringScheduleMonthlyCostEstimate::newInstance();
    }

    public function buildMonthlyCostEstimateForActiveRecurringSchedulesOfAccountWithoutAgentSpace(Account $account): RecurringScheduleMonthlyCostEstimate
    {
        $this->scopeReads[] = 'monthly cost of account ' . $account->id;
        return RecurringScheduleMonthlyCostEstimate::newInstance();
    }

    /** No schedule budget: no pot line and no refusal of an immediate start */
    public function buildScheduleBudgetPotStatusForAccount(?Account $owningAccount): ?ScheduleBudgetPotStatus
    {
        return null;
    }

    protected function buildSchedulesOfScope(): AIConversationSchedules
    {
        $schedulesOfScope = new AIConversationSchedules();
        $scheduleId = 100;
        foreach ([
            AIConversationScheduleStatus::ACTIVE => $this->fixtureActiveScheduleCount,
            AIConversationScheduleStatus::PAUSED => $this->fixturePausedScheduleCount,
        ] as $status => $scheduleCount) {
            for ($scheduleIndex = 0; $scheduleIndex < $scheduleCount; $scheduleIndex++) {
                $schedule = new AIConversationSchedule();
                $schedule->id = $scheduleId++;
                $schedule->status = $status;
                $schedulesOfScope->add($schedule);
            }
        }
        return $schedulesOfScope;
    }
}
