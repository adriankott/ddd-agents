<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Attributes\WithAIEnvelope;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Interfaces\AIConversationOverheadCostsMessageInterface;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/**
 * Harvest the owner's new messages of a conversation into the package's memory store
 * ({@see \DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryCurationService}). Routed to the compaction
 * transport (`AgentMessengerTransports`); its model calls are booked under the memory curation envelope.
 */
#[WithAIEnvelope(scope: AgentAIUsageScope::ENVELOPE_AI_MEMORY_CURATE)]
class AgentMemoryCurateMessage extends AppMessage implements AIConversationOverheadCostsMessageInterface
{
    public int $aiConversationId;

    /** @var int How often the harvest was put back (the turn was still running, or the model gave no answer) */
    public int $requeueAttempts = 0;

    public static string $messageHandler = AgentMemoryCurateHandler::class;

    public function __construct(int $aiConversationId)
    {
        parent::__construct();
        $this->aiConversationId = $aiConversationId;
    }

    public function getAIConversationIdForOverheadCosts(): ?int
    {
        return $this->aiConversationId;
    }
}
