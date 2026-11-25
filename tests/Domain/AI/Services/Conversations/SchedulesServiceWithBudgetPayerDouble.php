<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\RecurringScheduleMonthlyCostEstimate;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * The schedules service of an application that — when set — names another account as the payer of a schedule's
 * budget pot; the budget of the monthly cost estimate opened for the test, writes kept in memory.
 */
class SchedulesServiceWithBudgetPayerDouble extends SchedulesServiceWithOwnPotAndRunWordsDouble
{
    /** @var Account|null null = the package's default payer (the schedule's account) */
    public ?Account $fixtureBudgetPayerAccount = null;

    public function applyBudgetOfPayerToMonthlyCostEstimateForTest(
        RecurringScheduleMonthlyCostEstimate $monthlyCostEstimate,
        AIConversationSchedule $scheduleOfPartition
    ): void {
        $this->applyBudgetOfPayerToMonthlyCostEstimate($monthlyCostEstimate, $scheduleOfPartition);
    }

    protected function findBudgetPayerOfSchedule(AIConversationSchedule $schedule): ?Account
    {
        return $this->fixtureBudgetPayerAccount ?? parent::findBudgetPayerOfSchedule($schedule);
    }
}
