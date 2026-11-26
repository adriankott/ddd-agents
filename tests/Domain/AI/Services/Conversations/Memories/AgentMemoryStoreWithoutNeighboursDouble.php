<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use LogicException;

/** An empty store without a table: no neighbour, no directive, no duplicate; a write is refused. */
class AgentMemoryStoreWithoutNeighboursDouble extends AgentMemoryStoreService
{
    public function __construct()
    {
    }

    public function findSimilarMemories(
        string $searchText,
        int $accountId,
        ?int $agentSpaceId = null,
        int $limit = 10,
        ?string $memoryType = null
    ): AgentMemories {
        return AgentMemories::newInstance();
    }

    public function findCurrentMemoriesForScope(
        int $accountId,
        ?int $agentSpaceId = null,
        ?string $memoryType = null,
        ?int $limit = null
    ): AgentMemories {
        return AgentMemories::newInstance();
    }

    public function findCurrentDuplicateOfContent(int $accountId, ?int $agentSpaceId, string $content): ?AgentMemory
    {
        return null;
    }

    public function persistMemory(AgentMemory $agentMemory): AgentMemory
    {
        throw new LogicException('The pass of this test stores nothing.');
    }
}
