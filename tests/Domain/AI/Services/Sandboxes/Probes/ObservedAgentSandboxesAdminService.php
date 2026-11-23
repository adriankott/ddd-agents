<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Sandboxes\Probes;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntry;
use DDD\Domain\AI\Services\Sandboxes\AgentSandboxesAdminService;

/** The admin service without a database: given live turns and counts, the audit entries kept in memory. */
class ObservedAgentSandboxesAdminService extends AgentSandboxesAdminService
{
    /** @var int[] */
    public array $agentSpaceIdsWithALiveTurn = [];

    /** @var int[] agent space id => count */
    public array $conversationCountsByAgentSpaceId = [];

    /** @var AgentSandboxActionLogEntry[] */
    public array $recordedActionLogEntries = [];

    protected function countRootConversationsPerAgentSpaceId(array $agentSpaceIds): array
    {
        return $this->conversationCountsByAgentSpaceId;
    }

    protected function findAgentSpaceIdsWithALiveTurn(array $agentSpaceIds): array
    {
        return array_values(array_intersect($this->agentSpaceIdsWithALiveTurn, $agentSpaceIds));
    }

    protected function recordActionLogEntry(AgentSandboxActionLogEntry $agentSandboxActionLogEntry): ?AgentSandboxActionLogEntry
    {
        $this->recordedActionLogEntries[] = clone $agentSandboxActionLogEntry;
        return null;
    }
}
