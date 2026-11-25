<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleBudgetPotStatus;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * A schedule budget pot that is used up for every account, with a monthly budget of ten dollars per account id; it
 * records whose pot and whose budget were read.
 */
class ScheduleBudgetPotsServiceRecordingPayersDouble extends ScheduleBudgetPotsService
{
    /** @var int[] the ids of the accounts whose pot was read, in order */
    public array $accountIdsOfPotReads = [];

    /** @var int[] the ids of the accounts whose monthly budget was read, in order */
    public array $accountIdsOfBudgetReads = [];

    public function findScheduleBudgetPotStatusForAccount(Account $account): ScheduleBudgetPotStatus
    {
        $this->accountIdsOfPotReads[] = (int)$account->id;
        $scheduleBudgetPotStatus = ScheduleBudgetPotStatus::newInstance();
        $scheduleBudgetPotStatus->monthlyPotUsd = 10.0;
        $scheduleBudgetPotStatus->spentUsd = 10.0;
        return $scheduleBudgetPotStatus;
    }

    public function findMonthlyBudgetForAccount(Account $account): float
    {
        $this->accountIdsOfBudgetReads[] = (int)$account->id;
        return 10.0 * (int)$account->id;
    }
}
