<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/**
 * The messages service of a chat test: records the owner rows it is asked to store and to embed and the conversations
 * whose bounded tail a resume reads (an empty tail); stores, embeds and reads nothing.
 */
class RecordingConversationMessagesServiceDouble extends AIConversationMessagesService
{
    /** @var string[] the texts of the rows stored as inbound rows */
    public array $storedInboundMessageTexts = [];

    /** @var array<int, array<string, mixed>> per embedding: the text and whether it was asked in the background */
    public array $embeddings = [];

    /** @var int[] the ids of the conversations whose context tail was read */
    public array $contextTailConversationIds = [];

    public function persistInboundRowIndexSafe(AIConversation $conversation, AIConversationMessage $message): void
    {
        $this->storedInboundMessageTexts[] = (string)$message->message;
    }

    public function findContextTailMessages(AIConversation $conversation): AIConversationMessages
    {
        $this->contextTailConversationIds[] = (int)$conversation->id;
        return AIConversationMessages::newInstance();
    }

    public function embedMessage(AIConversationMessage $message, bool $async = false): bool
    {
        $this->embeddings[] = ['message' => (string)$message->message, 'async' => $async];
        return true;
    }
}
