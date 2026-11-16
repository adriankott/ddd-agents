<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Reports\AIConversationOwnerReport;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;

/**
 * The owner-report reads of a schedule-run printout: the model-written report of every run is the one the test gives
 * (null = the run wrote none).
 */
class ScheduleRunOwnerReportDeliveryServiceDouble extends AIConversationOwnerReportDeliveryService
{
    /** @var AIConversationOwnerReport|null The report every run is read as having written */
    public ?AIConversationOwnerReport $ownerReport = null;

    public function findModelWrittenOwnerReportOfRun(AIConversation $runConversation): ?AIConversationOwnerReport
    {
        return $this->ownerReport;
    }
}
