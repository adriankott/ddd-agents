<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleBudgetPotStatus;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** A schedule budget pot of ten dollars that is used up or barely touched, as the test says; it counts its reads. */
class ScheduleBudgetPotsServiceWithScriptedPotDouble extends ScheduleBudgetPotsService
{
    public bool $fixturePotIsUsedUp = false;

    public int $potReadsCount = 0;

    public function findScheduleBudgetPotStatusForAccount(Account $account): ScheduleBudgetPotStatus
    {
        $this->potReadsCount++;
        $scheduleBudgetPotStatus = ScheduleBudgetPotStatus::newInstance();
        $scheduleBudgetPotStatus->monthlyPotUsd = 10.0;
        $scheduleBudgetPotStatus->spentUsd = $this->fixturePotIsUsedUp ? 10.0 : 1.0;
        return $scheduleBudgetPotStatus;
    }
}
