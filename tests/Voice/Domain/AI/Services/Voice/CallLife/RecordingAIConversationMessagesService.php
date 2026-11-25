<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use RuntimeException;

/**
 * The message service of the package without a database: the tail read answers an empty set, and each row the
 * index-safe insert is asked to write is recorded. The first {@see $failingInsertCount} inserts throw.
 */
class RecordingAIConversationMessagesService extends AIConversationMessagesService
{
    /** @var array<int, array{conversationId: int, message: AIConversationMessage}> The rows written, in order */
    public array $insertedRows = [];

    public int $insertAttempts = 0;

    public int $failingInsertCount = 0;

    public function findContextTailMessages(AIConversation $conversation): AIConversationMessages
    {
        return new AIConversationMessages();
    }

    public function persistInboundRowIndexSafe(AIConversation $conversation, AIConversationMessage $message): void
    {
        $this->insertAttempts++;
        if ($this->insertAttempts <= $this->failingInsertCount) {
            throw new RuntimeException('index taken');
        }
        $this->insertedRows[] = ['conversationId' => (int)$conversation->id, 'message' => $message];
    }
}
