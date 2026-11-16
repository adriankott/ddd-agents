<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Evals;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonScript;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Symfony\Commands\Agents\MCPCompareToolCallsProbeCommand;

/** The comparison command over the fixture roots, working as the stored account a database test names. */
class MCPCompareToolCallsStoredAccountProbeCommand extends MCPCompareToolCallsProbeCommand
{
    public ?Account $comparisonAccount = null;

    protected function findComparisonAccount(ToolCallComparisonScript $toolCallComparisonScript): ?Account
    {
        return $this->comparisonAccount;
    }
}
