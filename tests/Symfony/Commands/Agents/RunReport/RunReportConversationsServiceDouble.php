<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\RunReport;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;

/**
 * The conversations service that knows the stored runs of {@see RunReportPrintoutRuns}: a lookup of one of them returns
 * a conversation with that id, any other id none. Reads nothing.
 */
class RunReportConversationsServiceDouble extends AIConversationsService
{
    /** @var int[] The ids looked up, in order */
    public array $lookedUpConversationIds = [];

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $this->lookedUpConversationIds[] = (int)$entityId;
        if (!array_key_exists((int)$entityId, RunReportPrintoutRuns::getMessageRowsByConversationId())) {
            return null;
        }
        $conversation = AIConversation::newInstance();
        $conversation->id = (int)$entityId;
        return $conversation;
    }
}
