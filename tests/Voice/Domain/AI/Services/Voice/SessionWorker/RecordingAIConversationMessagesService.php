<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/** The two message reads of the session worker: the context tail answers no rows, the newest index is scripted. */
class RecordingAIConversationMessagesService extends AIConversationMessagesService
{
    public ?int $newestMessageIndex = null;

    /** @var array<int, int|null> The conversation id of every context tail read */
    public array $contextTailReadConversationIds = [];

    /** @var array<int, int|null> The conversation id of every newest-index read */
    public array $newestIndexReadConversationIds = [];

    public function findContextTailMessages(AIConversation $conversation): AIConversationMessages
    {
        $this->contextTailReadConversationIds[] = $conversation->id;

        return new AIConversationMessages();
    }

    public function findNewestMessageIndex(AIConversation $conversation): ?int
    {
        $this->newestIndexReadConversationIds[] = $conversation->id;

        return $this->newestMessageIndex;
    }
}
