<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Briefing;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentStandingDirectives;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;

/**
 * The memory port of an application whose memories sit behind its own service: it answers the standing directives
 * itself (whatever the package's memory switch says) and records the scope of every read.
 */
class BoundAgentMemoriesService extends AgentMemoriesService
{
    public string $directivesBlock = "\n\n# The application's own standing rules\n- Speak slowly.";

    /** @var array<int, array{int, ?int}> account id and agent space id of every read */
    public array $readScopes = [];

    public function findStandingDirectivesForAgentSpace(int $accountId, ?AgentSpace $agentSpace): ?AgentStandingDirectives
    {
        $this->readScopes[] = [$accountId, $agentSpace !== null ? (int)$agentSpace->id : null];
        $agentStandingDirectives = new AgentStandingDirectives();
        $agentStandingDirectives->directivesBlock = $this->directivesBlock;
        return $agentStandingDirectives;
    }
}
