<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories\HostBackedAgentMemoryStoreService;

/** The evaluation purge with a host's memory store behind it. */
class AIConversationEvalPurgeServiceWithHostMemoryStore extends AIConversationEvalPurgeService
{
    public function __construct(public HostBackedAgentMemoryStoreService $hostBackedAgentMemoryStoreService)
    {
    }

    protected function getAgentMemoryStoreService(): AgentMemoryStoreService
    {
        return $this->hostBackedAgentMemoryStoreService;
    }
}
