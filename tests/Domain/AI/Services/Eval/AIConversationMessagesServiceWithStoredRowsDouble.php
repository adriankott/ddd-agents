<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;

/**
 * The messages service of a judged-run test: the stored rows of a conversation are the set the test gives, the owner
 * rows the run stores are recorded; reads and writes no database.
 */
class AIConversationMessagesServiceWithStoredRowsDouble extends AIConversationMessagesServiceRecordingInboundRowsDouble
{
    /** @var AIConversationMessages|null What a read of the stored rows answers */
    public ?AIConversationMessages $storedMessages = null;

    /** @var bool[] Whether each read went past the entity registry, in order */
    public array $readsPastTheEntityRegistry = [];

    public function findAllMessagesForConversationInOrder(
        AIConversation $aiConversation,
        ?int $offset = null,
        ?int $limit = null,
        bool $useEntityRegistrCache = true
    ): ?AIConversationMessages {
        $this->readsPastTheEntityRegistry[] = !$useEntityRegistrCache;
        return $this->storedMessages;
    }
}
