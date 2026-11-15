<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/** The messages service of a runner test: records the owner rows it is asked to store; stores nothing. */
class AIConversationMessagesServiceRecordingInboundRowsDouble extends AIConversationMessagesService
{
    /** @var string[] The texts of the rows stored as inbound rows, in order */
    public array $storedInboundMessageTexts = [];

    public function persistInboundRowIndexSafe(AIConversation $conversation, AIConversationMessage $message): void
    {
        $this->storedInboundMessageTexts[] = (string)$message->message;
    }
}
