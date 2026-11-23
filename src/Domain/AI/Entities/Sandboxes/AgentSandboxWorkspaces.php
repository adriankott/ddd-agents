<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Every agent workspace the host knows, running or offloaded.
 *
 * @property AgentSandboxWorkspace[] $elements
 * @method AgentSandboxWorkspace|null first()
 * @method AgentSandboxWorkspace|null getByUniqueKey(string $uniqueKey)
 * @method AgentSandboxWorkspace[] getElements()
 */
class AgentSandboxWorkspaces extends ObjectSet
{
    /**
     * @return int[] the agent spaces of the workspaces, each once
     */
    public function getAgentSpaceIds(): array
    {
        $agentSpaceIds = [];
        foreach ($this->getElements() as $agentSandboxWorkspace) {
            if ($agentSandboxWorkspace->agentSpaceId !== null) {
                $agentSpaceIds[$agentSandboxWorkspace->agentSpaceId] = $agentSandboxWorkspace->agentSpaceId;
            }
        }
        return array_values($agentSpaceIds);
    }
}
