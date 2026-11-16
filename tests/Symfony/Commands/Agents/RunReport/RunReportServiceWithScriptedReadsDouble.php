<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\RunReport;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportModelCost;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportTaskPlanSnapshot;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportTaskPlanSnapshots;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportTimeWindow;
use DDD\Domain\AI\Services\Conversations\RunReports\AIConversationRunReportService;
use RuntimeException;

/**
 * The run-report service of an application with a usage log: the plan snapshots come from
 * {@see RunReportPrintoutRuns}, the full run is filed under a usage-log id, and the usage log books the full run's
 * decision cost under that id. Every read of the usage log is recorded; a test may let the snapshot read or the
 * usage-log read fail.
 */
class RunReportServiceWithScriptedReadsDouble extends AIConversationRunReportService
{
    /** @var array<int, array{usageScope: string, usageLogScopeId: int, from: string, to: string}> The usage-log reads */
    public array $usageLogReads = [];

    /** @var bool Whether the read of the plan snapshots fails, as a read of an unreachable database does */
    public bool $snapshotReadFails = false;

    /** @var bool Whether the read of the usage log fails, as a read of an unreachable usage log does */
    public bool $usageLogReadFails = false;

    /** @var string The prefix of skill section ids the application drops */
    public string $skillSectionIdPrefixToDrop = '';

    public function findTaskPlanSnapshotsOfConversation(AIConversation $conversation): AIConversationRunReportTaskPlanSnapshots
    {
        if ($this->snapshotReadFails) {
            throw new RuntimeException('The snapshot read failed');
        }
        $aIConversationRunReportTaskPlanSnapshots = AIConversationRunReportTaskPlanSnapshots::newInstance();
        foreach (RunReportPrintoutRuns::getTaskPlanSnapshotsByConversationId()[(int)$conversation->id] ?? [] as $messageIndex => $decodedSnapshot) {
            $aIConversationRunReportTaskPlanSnapshot = AIConversationRunReportTaskPlanSnapshot::fromColumnValue((int)$messageIndex, $decodedSnapshot);
            $aIConversationRunReportTaskPlanSnapshots->add($aIConversationRunReportTaskPlanSnapshot);
        }
        return $aIConversationRunReportTaskPlanSnapshots;
    }

    protected function findUsageLogScopeIdOfConversation(AIConversation $conversation): ?int
    {
        return (int)$conversation->id === RunReportPrintoutRuns::FULL_RUN_ID ? RunReportPrintoutRuns::FULL_RUN_USAGE_LOG_SCOPE_ID : null;
    }

    protected function findModelCostInUsageLog(string $usageScope, int $usageLogScopeId, AIConversationRunReportTimeWindow $timeWindow): AIConversationRunReportModelCost
    {
        if ($this->usageLogReadFails) {
            throw new RuntimeException('The usage-log read failed');
        }
        $this->usageLogReads[] = ['usageScope' => $usageScope, 'usageLogScopeId' => $usageLogScopeId, 'from' => $timeWindow->from, 'to' => $timeWindow->to];
        return AIConversationRunReportModelCost::forCostAndCalls(RunReportPrintoutRuns::FULL_RUN_DECISION_COST_IN_USD, RunReportPrintoutRuns::FULL_RUN_DECISION_CALLS);
    }

    protected function getSkillSectionIdPrefixToDrop(): string
    {
        return $this->skillSectionIdPrefixToDrop;
    }
}
