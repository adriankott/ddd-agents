<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Schedules\ScheduleBudgetPotStatus;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** A schedules service whose budget pot is used up for every account; it records whose pot was read. */
class BudgetPotRecordingSchedulesServiceDouble extends AIConversationSchedulesService
{
    /** @var int[] the ids of the accounts whose pot was read, in order */
    public array $accountIdsOfPotReads = [];

    public function buildScheduleBudgetPotStatusForAccount(?Account $owningAccount): ?ScheduleBudgetPotStatus
    {
        $this->accountIdsOfPotReads[] = (int)$owningAccount?->id;
        $scheduleBudgetPotStatus = ScheduleBudgetPotStatus::newInstance();
        $scheduleBudgetPotStatus->monthlyPotUsd = 10.0;
        $scheduleBudgetPotStatus->spentUsd = 10.0;
        return $scheduleBudgetPotStatus;
    }
}
