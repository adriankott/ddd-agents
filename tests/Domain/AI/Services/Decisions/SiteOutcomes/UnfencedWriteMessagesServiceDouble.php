<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageWriteScope;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageWriteUndo;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;

/**
 * The messages service of the skill-preload site: the claim-fenced write runs its writes in place (no lock, no
 * transaction), its open and commit are world lines; an undo registration is dropped. No row is touched.
 */
class UnfencedWriteMessagesServiceDouble extends AIConversationMessagesService
{
    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function openClaimFencedWrite(AIConversation $conversation, ?string $runToken): AIConversationMessageWriteScope
    {
        $this->worldLog->record('claim-fenced write opens');
        return AIConversationMessageWriteScope::unfenced();
    }

    public function commitClaimFencedWrite(AIConversationMessageWriteScope $writeScope): void
    {
        $this->worldLog->record('claim-fenced write commits');
    }

    public function undoOnRollback(AIConversationMessageWriteUndo $undo): void
    {
    }
}
