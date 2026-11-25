<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;

/**
 * The call store port with the map of store names the test hands in (the override point
 * {@see getCallStoreClassNames()}, the way an application maps a store of its own).
 */
class ConfigurableAgentVoiceCallStoreService extends AgentVoiceCallStoreService
{
    /** @param array<string, string> $callStoreClassNames store name => class */
    public function __construct(public array $callStoreClassNames)
    {
    }

    /** @return array<string, string> */
    protected function getCallStoreClassNames(): array
    {
        return $this->callStoreClassNames;
    }
}
