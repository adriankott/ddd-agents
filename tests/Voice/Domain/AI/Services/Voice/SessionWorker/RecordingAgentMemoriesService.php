<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;

/** The memory curation after a call: every request is kept, nothing is curated. */
class RecordingAgentMemoriesService extends AgentMemoriesService
{
    /** @var array<int, array{conversationId: int|null, async: bool}> */
    public array $curationRequests = [];

    public function curateConversationAfterTurn(AIConversation $conversation, bool $async): void
    {
        $this->curationRequests[] = ['conversationId' => $conversation->id, 'async' => $async];
    }
}
