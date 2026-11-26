<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;

/** The package's goal store over a database whose catalog answers a fixed number of goal tables; counts the questions */
class AgentGoalStoreServiceCountingTableChecksDouble extends AgentGoalStoreService
{
    public int $goalTableCount = 0;

    public int $askedCatalogCount = 0;

    protected function countGoalTablesInDatabase(): int
    {
        $this->askedCatalogCount++;
        return $this->goalTableCount;
    }
}
