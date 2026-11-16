<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\RunReports;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetricsTimeIntervals;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReport;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportMessageRows;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportModelCost;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportTaskPlanSnapshot;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportTaskPlanSnapshots;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportTimeWindow;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\Exception as DBALException;
use Throwable;

/**
 * The standard per-run report of one stored conversation ({@see AIConversationRunReport}): it reads the conversation's
 * message rows and delegation figures ({@see AIConversationMetricsService}), the plan snapshots of the rows and the
 * decision model's cost, and builds the report from them. The console command `app:ai-agent:run-report` prints it; an
 * application may return it from a tool of its own.
 *
 * What the module cannot know is an override point an application implements in its class of the same name at the
 * mirrored path:
 *
 * - {@see findUsageLogScopeIdOfConversation()}: the id the application's usage log files the run's model calls under
 *   (the scope of the run in the usage log). The default: none, so the decision model's cost stays zero.
 * - {@see findModelCostInUsageLog()}: the cost and the calls of one usage scope under that id within the run's time
 *   window. The default: none (the module keeps no usage log of its own).
 * - {@see getSkillSectionIdPrefixToDrop()}: a prefix the variants timeline drops from every skill section id. The
 *   default: none.
 */
class AIConversationRunReportService extends Service
{
    /**
     * The report of a stored conversation; null when it has no message rows.
     *
     * @param AIConversation $conversation
     * @return AIConversationRunReport|null
     * @throws DBALException when a read of the metric rows fails
     */
    public function findReportOfConversation(AIConversation $conversation): ?AIConversationRunReport
    {
        $aIConversationMetricsService = $this->getAIConversationMetricsService();
        $rows = AIConversationRunReportMessageRows::fromRowArrays($aIConversationMetricsService->findMessageRowsForConversation($conversation));
        $this->attachTaskPlanSnapshotsToRows($conversation, $rows);
        $runReport = $this->buildReportFromRows(
            $rows,
            $aIConversationMetricsService->findDelegationBatchIntervalsForConversation($conversation),
            $aIConversationMetricsService->findChildModelSecondsForConversation($conversation)
        );
        if ($runReport === null) {
            return null;
        }
        $runReport->aiConversationId = (int)$conversation->id;
        $runReport->applyDecisionModelCost($this->findDecisionModelCost($conversation, $rows));
        return $runReport;
    }

    /**
     * The report of message rows (pure; {@see AIConversationRunReport::buildFromRows()}), with the application's prefix
     * of skill section ids dropped in the variants timeline.
     *
     * @param AIConversationRunReportMessageRows $rows the message rows in index order
     * @param AIConversationMetricsTimeIntervals|null $delegationBatchIntervals
     * @param float $childModelSeconds
     * @return AIConversationRunReport|null null without rows
     */
    public function buildReportFromRows(AIConversationRunReportMessageRows $rows, ?AIConversationMetricsTimeIntervals $delegationBatchIntervals = null, float $childModelSeconds = 0.0): ?AIConversationRunReport
    {
        return AIConversationRunReport::buildFromRows($rows, $delegationBatchIntervals, $childModelSeconds, $this->getSkillSectionIdPrefixToDrop());
    }

    /**
     * Gives every row its stored plan snapshot (none when its message stamped none). The snapshots are read apart from
     * the metric rows, which never carry the snapshot of several kilobytes. When the read fails the rows keep no
     * snapshot and carry the class of the exception ({@see AIConversationRunReportMessageRows::$taskPlanSnapshotReadFailure}):
     * the report then measures no plan size and says why ({@see AIConversationRunReport::getPlanSizeNotMeasuredNote()}).
     */
    public function attachTaskPlanSnapshotsToRows(AIConversation $conversation, AIConversationRunReportMessageRows $rows): void
    {
        try {
            $aIConversationRunReportTaskPlanSnapshots = $this->findTaskPlanSnapshotsOfConversation($conversation);
        } catch (Throwable $snapshotReadFailure) {
            $rows->taskPlanSnapshotReadFailure = $snapshotReadFailure::class;
            return;
        }
        $rows->attachTaskPlanSnapshots($aIConversationRunReportTaskPlanSnapshots);
    }

    /**
     * The plan snapshots the conversation's messages stamped (truncated messages excluded), one per message index.
     */
    public function findTaskPlanSnapshotsOfConversation(AIConversation $conversation): AIConversationRunReportTaskPlanSnapshots
    {
        $snapshotRows = $this->getEntityManager()->createQuery(
            'SELECT messageRow.indexInConversation AS messageIndex, messageRow.taskPlanSnapshot AS taskPlanSnapshot'
            . ' FROM ' . DBAIConversationMessageModel::class . ' messageRow'
            . ' WHERE messageRow.aiConversationId = :aiConversationId AND messageRow.truncatedAt IS NULL AND messageRow.taskPlanSnapshot IS NOT NULL'
        )->setParameter('aiConversationId', (int)$conversation->id)->getScalarResult();
        $aIConversationRunReportTaskPlanSnapshots = AIConversationRunReportTaskPlanSnapshots::newInstance();
        foreach ($snapshotRows as $snapshotRow) {
            $aIConversationRunReportTaskPlanSnapshot = AIConversationRunReportTaskPlanSnapshot::fromColumnValue((int)$snapshotRow['messageIndex'], $snapshotRow['taskPlanSnapshot'] ?? null);
            $aIConversationRunReportTaskPlanSnapshots->add($aIConversationRunReportTaskPlanSnapshot);
        }
        return $aIConversationRunReportTaskPlanSnapshots;
    }

    /**
     * The DECISION model's cost of a run (usage scope {@see AgentAIUsageScope::AI_AGENT_DECIDE_TURN}), apart from the
     * agent model's cost on the rows. The usage log books no conversation id, so it is read by the id the application
     * files the run under ({@see findUsageLogScopeIdOfConversation()}) within the run's time window
     * ({@see AIConversationRunReportTimeWindow}). No cost and no call when the run has no such id or no row carries a
     * time. When the read fails the cost carries the class of the exception
     * ({@see AIConversationRunReportModelCost::forReadFailure()}): the report then says the cost was not read
     * ({@see AIConversationRunReport::getDecisionCostNotReadNote()}).
     *
     * @param AIConversation $conversation
     * @param AIConversationRunReportMessageRows $rows the message rows (for the time window)
     * @return AIConversationRunReportModelCost
     */
    public function findDecisionModelCost(AIConversation $conversation, AIConversationRunReportMessageRows $rows): AIConversationRunReportModelCost
    {
        try {
            $usageLogScopeId = $this->findUsageLogScopeIdOfConversation($conversation);
            if ($usageLogScopeId === null) {
                return AIConversationRunReportModelCost::none();
            }
            $timeWindow = AIConversationRunReportTimeWindow::fromRows($rows);
            if ($timeWindow === null) {
                return AIConversationRunReportModelCost::none();
            }
            return $this->findModelCostInUsageLog(AgentAIUsageScope::AI_AGENT_DECIDE_TURN, $usageLogScopeId, $timeWindow);
        } catch (Throwable $usageLogReadFailure) {
            return AIConversationRunReportModelCost::forReadFailure($usageLogReadFailure::class);
        }
    }

    /**
     * Override point: the id the application's usage log files the run's model calls under (for example the entity a
     * run is billed to). Null = the run has none, and no usage-log cost is read. The default: null.
     *
     * @param AIConversation $conversation
     * @return int|null
     */
    protected function findUsageLogScopeIdOfConversation(AIConversation $conversation): ?int
    {
        return null;
    }

    /**
     * Override point: the cost and the calls the application's usage log books under one usage scope for one id within
     * a time window (both ends included). The default: none — the module keeps no usage log.
     *
     * @param string $usageScope the usage scope of the calls
     * @param int $usageLogScopeId the id of {@see findUsageLogScopeIdOfConversation()}
     * @param AIConversationRunReportTimeWindow $timeWindow
     * @return AIConversationRunReportModelCost
     */
    protected function findModelCostInUsageLog(string $usageScope, int $usageLogScopeId, AIConversationRunReportTimeWindow $timeWindow): AIConversationRunReportModelCost
    {
        return AIConversationRunReportModelCost::none();
    }

    /**
     * Override point: a prefix the variants timeline drops from the start of every skill section id (the ids of an
     * application's skill tree may share one). The default: none.
     *
     * @return string
     */
    protected function getSkillSectionIdPrefixToDrop(): string
    {
        return '';
    }

    /**
     * The metrics service (the application's class, when it replaces it).
     *
     * @return AIConversationMetricsService
     */
    protected function getAIConversationMetricsService(): AIConversationMetricsService
    {
        /** @var AIConversationMetricsService $aIConversationMetricsService */
        $aIConversationMetricsService = DDDService::instance()->getService(AIConversationMetricsService::class);
        return $aIConversationMetricsService;
    }

    protected function getEntityManager(): DoctrineEntityManager
    {
        return EntityManagerFactory::getInstance();
    }
}
