<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;

/** The conversations service whose conversations all run on one wire API, without asking the model catalog. */
class AIConversationsServiceWithFixedAgentWireApiDouble extends AIConversationsService
{
    public function __construct(public string $agentWireApi)
    {
    }

    protected function resolveAgentWireApiOfConversation(AIConversation $conversation, ?string $modelNameOverride): string
    {
        return $this->agentWireApi;
    }
}
