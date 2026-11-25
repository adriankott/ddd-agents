<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/** An uncompacted conversation: the cover read of the resume answers an empty node set. */
class RunReleaseCompactionNodesServiceDouble extends ConversationCompactionNodesService
{
    public function __construct()
    {
        // The parent service is container-managed and holds no constructor state.
    }

    public function findCoverNodesForConversation(AIConversation $aIConversation, ?DateTime $asOf = null): AIConversationCompactionNodes
    {
        return new AIConversationCompactionNodes();
    }
}
