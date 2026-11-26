<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;

/**
 * A host's goal store that writes a copy of the goal it gets and leaves the passed goal without an id, as a store does
 * that maps the goal onto rows of its own.
 */
class CopyingAgentGoalStoreService extends HostBackedAgentGoalStoreService
{
    public function writeGoal(AgentGoal $agentGoal): AgentGoal
    {
        $this->portCalls[] = 'writeGoal:' . $agentGoal->statement;
        $writtenGoal = clone $agentGoal;
        if (!isset($writtenGoal->id)) {
            $writtenGoal->id = $this->nextGoalId++;
        }
        if ($this->findHostGoal((int)$writtenGoal->id) === null) {
            $this->hostGoals->add($writtenGoal);
        }
        return $writtenGoal;
    }
}
