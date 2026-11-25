<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Services\Eval\AgentScriptedUIClientService;

/** No evaluation screen: every request goes to a real client */
class NoScriptedScreenUIClientServiceDouble extends AgentScriptedUIClientService
{
    public function hasScriptedScreen(int $conversationId): bool
    {
        return false;
    }
}
