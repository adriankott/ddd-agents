<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Presentation\Mcp\Client\Agents\AgentMemoriesMCPPackage;

/** The package's memory tools with the memory store switched on, without touching the configuration. */
class AgentMemoriesMCPPackageSwitchedOn extends AgentMemoriesMCPPackage
{
    public static function isAvailable(): bool
    {
        return true;
    }
}
