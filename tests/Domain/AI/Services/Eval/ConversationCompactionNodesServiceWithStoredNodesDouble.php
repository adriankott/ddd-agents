<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService;

/** The compaction nodes service of a judged-run test: the stored tree is the set the test gives; reads no database. */
class ConversationCompactionNodesServiceWithStoredNodesDouble extends ConversationCompactionNodesService
{
    /** @var AIConversationCompactionNodes|null What a read of the stored tree answers */
    public ?AIConversationCompactionNodes $storedCompactionNodes = null;

    /** @var int[] The conversation id of each read */
    public array $readConversationIds = [];

    public function findAllNodesForConversation(AIConversation $aIConversation): ?AIConversationCompactionNodes
    {
        $this->readConversationIds[] = (int)$aIConversation->id;
        return $this->storedCompactionNodes;
    }
}
