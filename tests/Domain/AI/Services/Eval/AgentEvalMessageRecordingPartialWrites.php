<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;

/** A message that records its targeted writes instead of reaching a repository. */
class AgentEvalMessageRecordingPartialWrites extends AIConversationMessage
{
    /** @var array<int, string[]> */
    public array $partialWrites = [];

    public function updatePartialIgnoringRights(string ...$propertyNames): static
    {
        $this->partialWrites[] = $propertyNames;
        return $this;
    }
}
