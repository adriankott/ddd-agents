<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/** A conversation whose purpose is given, with the directive phase of the model input build callable alone */
class PurposeStubbingConversation extends AIConversation
{
    /** The conversation's purpose; null = none */
    public ?AIConversationPurpose $stubbedPurpose = null;

    public function findPurpose(): ?AIConversationPurpose
    {
        return $this->stubbedPurpose;
    }

    public function putDirectivesIntoModelInput(AgentModelInput $agentModelInput): void
    {
        $this->putDirectivesIntoContext($agentModelInput);
    }
}
