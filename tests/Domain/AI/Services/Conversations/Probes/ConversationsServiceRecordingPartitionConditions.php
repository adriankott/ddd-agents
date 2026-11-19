<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Probes;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpaceSqlCondition;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** The conversations service with the partition condition of the open-chat read recorded instead of read. */
class ConversationsServiceRecordingPartitionConditions extends AIConversationsService
{
    /** @var AgentSpaceSqlCondition[] the condition of each read, in order */
    public array $recordedConditions = [];

    protected function findNewestOpenCustomerChatIdInAgentSpacePartition(AgentSpaceSqlCondition $agentSpaceSqlCondition, DateTime $inboundSince, ?int $preferredConversationId, ?int $excludedConversationId): ?int
    {
        $this->recordedConditions[] = $agentSpaceSqlCondition;
        return null;
    }
}
