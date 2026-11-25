<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;

/**
 * The owner reports of the release stage: the two stamps of an obligation (owed, delivered) are recorded instead of
 * written; the delivery between them runs for real and persists its row through the stage's messages service.
 */
class RunReleaseOwnerReportDeliveryServiceDouble extends AIConversationOwnerReportDeliveryService
{
    public RunReleaseEventLog $eventLog;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function stampReportOwed(AIConversation $conversation): void
    {
        $this->eventLog->record('stampReportOwed');
    }

    public function stampReportDelivered(AIConversation $conversation): void
    {
        $this->eventLog->record('stampReportDelivered');
    }
}
