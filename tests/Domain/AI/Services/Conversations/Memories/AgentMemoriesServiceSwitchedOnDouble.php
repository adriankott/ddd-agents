<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;

/** The port with the memory store switched on and a store double behind it. */
class AgentMemoriesServiceSwitchedOnDouble extends AgentMemoriesService
{
    public AgentMemoryStoreServiceDouble $agentMemoryStoreServiceDouble;

    public function __construct()
    {
        $this->agentMemoryStoreServiceDouble = new AgentMemoryStoreServiceDouble();
    }

    public static function isMemoryStoreEnabled(): bool
    {
        return true;
    }

    protected function getAgentMemoryStoreService(): AgentMemoryStoreService
    {
        return $this->agentMemoryStoreServiceDouble;
    }
}
