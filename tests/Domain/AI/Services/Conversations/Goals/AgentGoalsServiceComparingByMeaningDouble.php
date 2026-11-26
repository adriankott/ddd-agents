<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals;

use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalsService;

/** A host's goal rules that compare statements by meaning: two statements about weekend catering are the same goal. */
class AgentGoalsServiceComparingByMeaningDouble extends AgentGoalsService
{
    protected function areStatementsNearDuplicates(string $statement, string $otherStatement): bool
    {
        return str_contains(strtolower($statement . ' ' . $otherStatement), 'catering')
            && (str_contains(strtolower($statement), 'weekend') || str_contains(strtolower($otherStatement), 'weekend'));
    }
}
