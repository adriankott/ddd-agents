<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallStore;

/** The call store port bound to the store the test hands in, whatever `AI.agentVoice.callStore` says. */
class InMemoryStoreAgentVoiceCallStoreService extends AgentVoiceCallStoreService
{
    public function __construct(public AgentVoiceCallStore $boundCallStore)
    {
    }

    public function getBoundCallStore(): AgentVoiceCallStore
    {
        return $this->boundCallStore;
    }
}
