<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;

/** The evaluation hooks with the agent spaces a fixture may name by reference held in memory. */
class AgentEvalRunHooksServiceWithNamedAgentSpacesDouble extends AgentEvalRunHooksService
{
    /** @var array<string, int> agent space name → id */
    public array $agentSpaceIdsByName = [];

    public function resolveAgentSpaceReference(string $agentSpaceName): ?int
    {
        return $this->agentSpaceIdsByName[$agentSpaceName] ?? null;
    }
}
