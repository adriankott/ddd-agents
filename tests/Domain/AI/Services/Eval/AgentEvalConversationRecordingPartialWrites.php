<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;

/** A conversation that records its targeted writes instead of reaching a repository. */
class AgentEvalConversationRecordingPartialWrites extends AIConversation
{
    /** @var array<int, string[]> */
    public array $partialWrites = [];

    public function updatePartialIgnoringRights(string ...$propertyNames): static
    {
        $this->partialWrites[] = $propertyNames;
        return $this;
    }
}
