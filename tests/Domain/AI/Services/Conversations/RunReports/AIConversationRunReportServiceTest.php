<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunReports;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportMessageRows;
use DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService;
use DDD\Domain\AI\Services\Conversations\RunReports\AIConversationRunReportService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\RunReport\RunReportMetricsServiceDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\RunReport\RunReportPrintoutRuns;
use DDD\Tests\Agents\Symfony\Commands\Agents\RunReport\RunReportServiceWithScriptedReadsDouble;
use RuntimeException;

/**
 * The run-report service: the module's own class reads no usage log (no decision cost, no read) and drops no prefix of
 * skill section ids; the report of a conversation carries the conversation's id and the plan snapshots of its rows;
 * a failed snapshot read leaves the rows without snapshots and the report says why its plan size is missing; a
 * conversation without rows has no report.
 */
class AIConversationRunReportServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceService(AIConversationMetricsService::class, new RunReportMetricsServiceDouble());
        StaticRegistry::$entityServices = [];
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$entityServices = [];
    }

    public function testTheModulesServiceIsTheContainersAndReadsNoUsageLog(): void
    {
        /** @var AIConversationRunReportService $aIConversationRunReportService */
        $aIConversationRunReportService = DDDService::instance()->getService(AIConversationRunReportService::class);
        $rows = AIConversationRunReportMessageRows::fromRowArrays(RunReportPrintoutRuns::getMessageRowsByConversationId()[RunReportPrintoutRuns::FULL_RUN_ID]);

        $decisionModelCost = $aIConversationRunReportService->findDecisionModelCost($this->conversation(RunReportPrintoutRuns::FULL_RUN_ID), $rows);

        self::assertInstanceOf(AIConversationRunReportService::class, $aIConversationRunReportService);
        self::assertSame(0.0, $decisionModelCost->costInUSD);
        self::assertSame(0, $decisionModelCost->calls);
        $runReport = $aIConversationRunReportService->buildReportFromRows(AIConversationRunReportMessageRows::fromRowArrays(RunReportPrintoutRuns::getMessageRowsByConversationId()[RunReportPrintoutRuns::VARIANTS_RUN_ID]));
        self::assertNotNull($runReport);
        self::assertStringStartsWith('Flow.Identify', $runReport->variantsTimeline->changes->first()?->skillSections ?? '');
    }

    public function testTheReportOfAConversationCarriesItsIdItsPlanSizeAndItsDecisionCost(): void
    {
        $runReportServiceDouble = new RunReportServiceWithScriptedReadsDouble();

        $runReport = $runReportServiceDouble->findReportOfConversation($this->conversation(RunReportPrintoutRuns::FULL_RUN_ID));

        self::assertNotNull($runReport);
        self::assertSame(RunReportPrintoutRuns::FULL_RUN_ID, $runReport->aiConversationId);
        self::assertSame(15, $runReport->taskPlanSize?->nodes);
        self::assertSame(3, $runReport->taskPlanSize?->maxDepth);
        self::assertSame(RunReportPrintoutRuns::FULL_RUN_DECISION_COST_IN_USD, $runReport->decisionModelCostInUSD);
        self::assertSame(RunReportPrintoutRuns::FULL_RUN_DECISION_CALLS, $runReport->decisionModelCalls);
        self::assertNull($runReport->getPlanSizeNotMeasuredNote(), 'the snapshot read worked');
        self::assertNull($runReportServiceDouble->findReportOfConversation($this->conversation(RunReportPrintoutRuns::EMPTY_RUN_ID)));
    }

    public function testASnapshotReadThatFailsLeavesNoSnapshotAndTheReportSaysWhy(): void
    {
        $this->replaceService(AIConversationRunReportService::class, new RunReportServiceWithFailingSnapshotReadDouble());
        /** @var AIConversationRunReportService $failingSnapshotReadService */
        $failingSnapshotReadService = DDDService::instance()->getService(AIConversationRunReportService::class);
        $rows = AIConversationRunReportMessageRows::fromRowArrays(RunReportPrintoutRuns::getMessageRowsByConversationId()[RunReportPrintoutRuns::FULL_RUN_ID]);

        $failingSnapshotReadService->attachTaskPlanSnapshotsToRows($this->conversation(RunReportPrintoutRuns::FULL_RUN_ID), $rows);

        self::assertSame(RuntimeException::class, $rows->taskPlanSnapshotReadFailure);
        foreach ($rows->getElements() as $row) {
            self::assertNull($row->taskPlanSnapshot);
        }
        $runReport = $failingSnapshotReadService->findReportOfConversation($this->conversation(RunReportPrintoutRuns::FULL_RUN_ID));
        self::assertNotNull($runReport);
        self::assertNull($runReport->taskPlanSize, 'no plan size without the snapshots');
        self::assertSame('plan size not measured: ' . RuntimeException::class, $runReport->getPlanSizeNotMeasuredNote());
    }

    public function testEveryRowGetsItsSnapshotOrNull(): void
    {
        $runReportServiceDouble = new RunReportServiceWithScriptedReadsDouble();
        $rows = AIConversationRunReportMessageRows::fromRowArrays(RunReportPrintoutRuns::getMessageRowsByConversationId()[RunReportPrintoutRuns::FULL_RUN_ID]);
        $rowCount = $rows->count();

        $runReportServiceDouble->attachTaskPlanSnapshotsToRows($this->conversation(RunReportPrintoutRuns::FULL_RUN_ID), $rows);

        $rowsInOrder = $rows->getElements();
        self::assertCount($rowCount, $rowsInOrder);
        self::assertNull($rows->taskPlanSnapshotReadFailure);
        self::assertSame(2, $rowsInOrder[1]->taskPlanSnapshot?->messageIndex, 'message 2 stamped the small plan');
        self::assertSame(7, $rowsInOrder[6]->taskPlanSnapshot?->messageIndex, 'message 7 stamped the large plan');
        self::assertNull($rowsInOrder[0]->taskPlanSnapshot);
        self::assertNull($rowsInOrder[2]->taskPlanSnapshot);
    }

    protected function conversation(int $conversationId): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = $conversationId;
        return $conversation;
    }
}
