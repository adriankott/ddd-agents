<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use RuntimeException;

/**
 * The memory seam of a chat test: records every curation it is asked for (and whether in the background); on demand
 * the curation fails.
 */
class RecordingAgentMemoriesServiceDouble extends AgentMemoriesService
{
    public const string CURATION_FAILURE_MESSAGE = 'the curation model is unavailable';

    /** @var array<int, array<string, mixed>> per curation: the conversation id and whether it was asked in the background */
    public array $curations = [];

    public bool $curationFails = false;

    public function curateConversationAfterTurn(AIConversation $conversation, bool $async): void
    {
        $this->curations[] = ['conversationId' => $conversation->id ?? null, 'async' => $async];
        if ($this->curationFails) {
            throw new RuntimeException(self::CURATION_FAILURE_MESSAGE);
        }
    }
}
