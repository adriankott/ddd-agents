<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Probes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Entities\Conversations\Schedules\RecurringScheduleMonthlyCostEstimate;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** The schedules service with the partition condition of each space-keyed read recorded instead of read. */
class SchedulesServiceRecordingPartitionConditions extends AIConversationSchedulesService
{
    /** @var AgentSpaceSqlCondition[] the condition of each read, in order */
    public array $recordedConditions = [];

    protected function countAgentRecurringSchedulesCreatedInAgentSpacePartitionSince(AgentSpaceSqlCondition $agentSpaceSqlCondition, DateTime $since): int
    {
        $this->recordedConditions[] = $agentSpaceSqlCondition;
        return 0;
    }

    protected function buildMonthlyCostEstimateForActiveRecurringSchedulesInAgentSpacePartition(AgentSpaceSqlCondition $agentSpaceSqlCondition): RecurringScheduleMonthlyCostEstimate
    {
        $this->recordedConditions[] = $agentSpaceSqlCondition;
        return new RecurringScheduleMonthlyCostEstimate();
    }

    protected function countUnseenFinishedRunsInAgentSpacePartition(AgentSpaceSqlCondition $agentSpaceSqlCondition): int
    {
        $this->recordedConditions[] = $agentSpaceSqlCondition;
        return 0;
    }
}
