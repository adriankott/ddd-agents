<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/**
 * The message reads of a schedule-run printout: the newest messages of a run are the ones the test gives, and the
 * limit of every read is recorded; nothing is read from a database.
 */
class ScheduleRunMessagesServiceDouble extends AIConversationMessagesService
{
    /** @var AIConversationMessages|null The messages every read returns */
    public ?AIConversationMessages $newestMessages = null;

    /** @var int[] The limit of every read */
    public array $readLimits = [];

    public function findNewestMessages(AIConversation $conversation, int $limit): AIConversationMessages
    {
        $this->readLimits[] = $limit;
        return $this->newestMessages ?? new AIConversationMessages();
    }
}
