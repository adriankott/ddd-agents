<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;

/** The owner report delivery that records, instead of writing, the runs of a schedule it is asked to cancel */
class OwnerReportDeliveryServiceRecordingCancellationsDouble extends AIConversationOwnerReportDeliveryService
{
    /** @var string[] The cancellations in order: the schedule and the reason its runs and report obligations are closed with */
    public array $recordedCancellations = [];

    public function cancelRunsAndCloseReportObligationsOfSchedule(int $scheduleId, string $suppressionReason): int
    {
        $this->recordedCancellations[] = 'schedule ' . $scheduleId . ' for ' . $suppressionReason;
        return 0;
    }
}
