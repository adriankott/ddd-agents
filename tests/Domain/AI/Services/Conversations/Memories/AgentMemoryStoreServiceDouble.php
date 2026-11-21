<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** A store that answers one directive and records the scope it was asked for. */
class AgentMemoryStoreServiceDouble extends AgentMemoryStoreService
{
    /** @var int[] The account id and space id of the last directives read */
    public array $askedScope = [];

    public function __construct()
    {
    }

    public function findCurrentDirectivesForScope(int $accountId, ?int $agentSpaceId = null): AgentMemories
    {
        $this->askedScope = [$accountId, $agentSpaceId];
        $directive = AgentMemory::buildStated('answer briefly', AgentMemory::MEMORY_TYPE_DIRECTIVE, null);
        $directive->id = 1;
        $directive->validFrom = new DateTime('2026-01-01 00:00:00');
        $agentMemories = AgentMemories::newInstance();
        $agentMemories->add($directive);
        return $agentMemories;
    }
}
