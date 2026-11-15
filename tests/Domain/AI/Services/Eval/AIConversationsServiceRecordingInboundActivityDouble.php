<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The conversations service of a runner test: an inbound-activity stamp is recorded with the number of owner rows the
 * messages double had stored at that moment and set on the conversation in memory; nothing is written.
 */
class AIConversationsServiceRecordingInboundActivityDouble extends AIConversationsService
{
    /** @var AIConversationMessagesServiceRecordingInboundRowsDouble|null The messages double whose stored rows are counted */
    public ?AIConversationMessagesServiceRecordingInboundRowsDouble $messagesServiceDouble = null;

    /** @var int[] Per stamp: the id of the stamped conversation */
    public array $stampedConversationIds = [];

    /** @var int[] Per stamp: how many owner rows were stored before it */
    public array $storedOwnerRowsBeforeStamp = [];

    public function recordInboundMessageActivity(AIConversation $conversation, bool $stampAgentTurnOwed = false): void
    {
        $this->stampedConversationIds[] = (int)$conversation->id;
        $this->storedOwnerRowsBeforeStamp[] = count($this->messagesServiceDouble?->storedInboundMessageTexts ?? []);
        $conversation->lastInboundMessageAt = new DateTime();
    }
}
