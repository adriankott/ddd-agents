<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;

/** A conversation whose newest wire message and unanswered inbound message the test scripts instead of its rows. */
class OwedRunsScriptedTailConversationDouble extends AIConversation
{
    public bool $endsOnFinalAssistantText = false;

    public bool $hasUnansweredInbound = true;

    public function lastWireMessageIsFinalAssistantText(): bool
    {
        return $this->endsOnFinalAssistantText;
    }

    public function hasUnansweredInboundMessage(): bool
    {
        return $this->hasUnansweredInbound;
    }
}
