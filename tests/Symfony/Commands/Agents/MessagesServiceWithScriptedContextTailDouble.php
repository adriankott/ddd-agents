<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/** The messages service answering the bounded context tail of a conversation with the rows the test scripted; reads nothing. */
class MessagesServiceWithScriptedContextTailDouble extends AIConversationMessagesService
{
    /** @var AIConversationMessages|null The tail the read answers */
    public ?AIConversationMessages $scriptedContextTail = null;

    /** @var int[] The ids of the conversations whose tail was read */
    public array $contextTailConversationIds = [];

    public function findContextTailMessages(AIConversation $conversation): AIConversationMessages
    {
        $this->contextTailConversationIds[] = (int)$conversation->id;
        return $this->scriptedContextTail ?? AIConversationMessages::newInstance();
    }
}
