<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents\Fixtures;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Services\WebSearch\WebSearchHostService;

/** A host whose search limit per turn is set by the test; null leaves it to the configuration */
class LimitedWebSearchHostService extends WebSearchHostService
{
    public ?int $maxSearchCallsPerTurn = null;

    public function findMaxSearchCallsPerTurn(AgentToolCallProvenance $callProvenance): ?int
    {
        return $this->maxSearchCallsPerTurn;
    }
}
