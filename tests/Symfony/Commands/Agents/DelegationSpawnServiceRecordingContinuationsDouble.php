<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;

/**
 * A delegation spawn service that records the continuation runs it is asked to open and opens none.
 */
class DelegationSpawnServiceRecordingContinuationsDouble extends AIConversationDelegationSpawnService
{
    /** @var array<int, array<string, mixed>> per request: the conversation id, the opening message, who continued */
    public array $continuationRequests = [];

    public function openSubAgentContinuationRun(AIConversation $childConversation, string $openingMessage, string $continuedBy): bool
    {
        $this->continuationRequests[] = ['conversationId' => $childConversation->id ?? null, 'openingMessage' => $openingMessage, 'continuedBy' => $continuedBy];
        return false;
    }
}
