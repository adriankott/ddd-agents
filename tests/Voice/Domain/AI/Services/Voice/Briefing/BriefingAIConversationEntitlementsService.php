<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Briefing;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentProductAwareness;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use RuntimeException;

/** The agent's product awareness with fixed capabilities; can fail instead. */
class BriefingAIConversationEntitlementsService extends AIConversationEntitlementsService
{
    public ?string $productCapabilities = null;

    public bool $failsProductAwareness = false;

    public function buildProductAwareness(AIConversation $conversation): AgentProductAwareness
    {
        if ($this->failsProductAwareness) {
            throw new RuntimeException('the entitlements cannot be read');
        }
        $agentProductAwareness = new AgentProductAwareness();
        $agentProductAwareness->productCapabilities = $this->productCapabilities;

        return $agentProductAwareness;
    }
}
