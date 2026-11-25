<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Briefing;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use RuntimeException;

/**
 * The agent's memory store with fixed standing directives; records the scope of every read; can fail instead.
 */
class BriefingAgentMemoryStoreService extends AgentMemoryStoreService
{
    /** @var string[] The directive texts the store holds */
    public array $directiveContents = [];

    public bool $failsRead = false;

    /** @var array<int, array{int, ?int}> account id and agent space id of every read */
    public array $readScopes = [];

    public function findCurrentDirectivesForScope(int $accountId, ?int $agentSpaceId = null): AgentMemories
    {
        $this->readScopes[] = [$accountId, $agentSpaceId];
        if ($this->failsRead) {
            throw new RuntimeException('the memory store cannot be read');
        }
        $agentMemories = AgentMemories::newInstance();
        foreach ($this->directiveContents as $directiveContent) {
            $directive = AgentMemory::buildStated($directiveContent, AgentMemory::MEMORY_TYPE_DIRECTIVE, null);
            $agentMemories->add($directive);
        }

        return $agentMemories;
    }
}
