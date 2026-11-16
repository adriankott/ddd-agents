<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\RunReport;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetricsTimeIntervals;
use DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService;

/**
 * The metrics service answering with the message rows of {@see RunReportPrintoutRuns} (without plan snapshots), no
 * delegation batch and no delegated model seconds. Reads nothing.
 */
class RunReportMetricsServiceDouble extends AIConversationMetricsService
{
    public function findMessageRowsForConversation(AIConversation $conversation): array
    {
        return RunReportPrintoutRuns::getMessageRowsByConversationId()[(int)$conversation->id] ?? [];
    }

    public function findChildModelSecondsForConversation(AIConversation $conversation): float
    {
        return 0.0;
    }

    public function findDelegationBatchIntervalsForConversation(AIConversation $conversation): AIConversationMetricsTimeIntervals
    {
        return new AIConversationMetricsTimeIntervals();
    }
}
