<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunReports;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportTaskPlanSnapshots;
use DDD\Domain\AI\Services\Conversations\RunReports\AIConversationRunReportService;
use RuntimeException;

/** The run-report service whose read of the plan snapshots fails, as a read of an unreachable database does. */
class RunReportServiceWithFailingSnapshotReadDouble extends AIConversationRunReportService
{
    public function findTaskPlanSnapshotsOfConversation(AIConversation $conversation): AIConversationRunReportTaskPlanSnapshots
    {
        throw new RuntimeException('The snapshot read failed');
    }
}
