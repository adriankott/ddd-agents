<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;

/** A run conversation whose last assistant text is given by the test instead of read from its messages */
class RunConversationWithScriptedLastAssistantTextDouble extends AIConversation
{
    public ?string $fixtureLastAssistantText = null;

    public function getLastAssistantText(): ?string
    {
        return $this->fixtureLastAssistantText;
    }
}
