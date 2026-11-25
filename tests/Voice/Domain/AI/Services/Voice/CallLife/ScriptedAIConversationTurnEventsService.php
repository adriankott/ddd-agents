<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvents;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use RuntimeException;

/** The turn events of a conversation as the test put them in, recording each conversation asked; can be made to fail. */
class ScriptedAIConversationTurnEventsService extends AIConversationTurnEventsService
{
    public ?AIConversationTurnEvents $turnEvents = null;

    public ?string $failureMessage = null;

    /** @var int[] */
    public array $askedConversationIds = [];

    public function findForConversation(int $conversationId, ?int $initiatorMessageIndex = null, int $limit = 500): ?AIConversationTurnEvents
    {
        $this->askedConversationIds[] = $conversationId;
        if ($this->failureMessage !== null) {
            throw new RuntimeException($this->failureMessage);
        }

        return $this->turnEvents;
    }
}
