<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * The package's rights service with its rule unchanged, except the answer to "does this account read every agent
 * row" (which would load the account's roles from the database): {@see $readsAllAgentRows}.
 */
class FixedReaderOfAllRowsAgentAccountRightsService extends AgentAccountRightsService
{
    public bool $readsAllAgentRows = false;

    public function isAccountReaderOfAllAgentRows(Account $account): bool
    {
        return $this->readsAllAgentRows;
    }
}
