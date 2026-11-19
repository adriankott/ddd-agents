<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Rights;

use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** An application's rights service whose admin role reads no row of another account (scoped admins). */
class AgentAccountRightsServiceWithoutReadersOfAllRowsDouble extends AgentAccountRightsService
{
    public function isAccountReaderOfAllAgentRows(Account $account): bool
    {
        return false;
    }
}
