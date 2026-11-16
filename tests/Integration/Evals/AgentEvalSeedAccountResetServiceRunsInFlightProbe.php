<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Evals;

use DDD\Domain\AI\Services\Eval\AgentEvalSeedAccountResetService;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * The clean start's count of the runs in flight, opened for a database test.
 */
class AgentEvalSeedAccountResetServiceRunsInFlightProbe extends AgentEvalSeedAccountResetService
{
    public function countRunsInFlightOfAccountForTest(Account $account): int
    {
        return $this->countRunsInFlightOfAccount($account);
    }
}
