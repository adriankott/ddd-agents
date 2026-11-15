<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/** The messages service answering the newest-rows read with an empty set; records the limit it was asked for. */
class OwedRunsMessagesServiceDouble extends AIConversationMessagesService
{
    /** @var int[] The limits asked, in order */
    public array $askedLimits = [];

    public function findNewestMessages(AIConversation $conversation, int $limit): AIConversationMessages
    {
        $this->askedLimits[] = $limit;
        return new AIConversationMessages();
    }
}
