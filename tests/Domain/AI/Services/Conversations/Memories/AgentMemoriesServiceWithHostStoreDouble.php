<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;

/** The port with memory switched on and a host's store behind it. */
class AgentMemoriesServiceWithHostStoreDouble extends AgentMemoriesService
{
    public function __construct(public HostBackedAgentMemoryStoreService $hostBackedAgentMemoryStoreService)
    {
    }

    public static function isMemoryStoreEnabled(): bool
    {
        return true;
    }

    protected function getAgentMemoryStoreService(): AgentMemoryStoreService
    {
        return $this->hostBackedAgentMemoryStoreService;
    }
}
