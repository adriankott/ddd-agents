<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanSize;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReport;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportMessageRows;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\RunReport\RunReportPrintoutRuns;

/**
 * The plan size in the standard per-run report, over a run of two owner turns: the plan in force at a model call is
 * the newest snapshot BEFORE its row, nodes and depth are the maxima over the plans in force, the tail tokens are
 * chars/4 per model call (max and Σ). A row's snapshot is read as the JSON text of its column or as the array the
 * column decodes to.
 */
class AIConversationRunReportTaskPlanSizeTest extends UnitTestCase
{
    public function testThePlanSizeColumnsOfARunWithSnapshotTexts(): void
    {
        $this->assertPlanSizeOfRun(true);
    }

    public function testThePlanSizeColumnsOfARunWithDecodedSnapshots(): void
    {
        $this->assertPlanSizeOfRun(false);
    }

    public function testARunWithoutAPlanSnapshotReportsNoPlanSize(): void
    {
        $runReport = AIConversationRunReport::buildFromRows(AIConversationRunReportMessageRows::fromRowArrays([
            $this->row(1, 'USER', 'TEXT', '2026-10-20 10:00:00'),
            $this->row(2, 'ASSISTANT', 'TEXT', '2026-10-20 10:00:05', null, 1),
        ]));
        self::assertNotNull($runReport);
        self::assertNull($runReport->taskPlanSize);
    }

    /**
     * @param bool $snapshotsAsText true: the rows carry the JSON text of the column; false: the decoded array
     */
    protected function assertPlanSizeOfRun(bool $snapshotsAsText): void
    {
        $smallPlan = RunReportPrintoutRuns::smallPlan();
        $largePlan = RunReportPrintoutRuns::largePlan();
        $rows = [
            $this->row(1, 'USER', 'TEXT', '2026-10-20 10:00:00'),
            // the call that creates the plan ran without one: its own snapshot is the state after it
            $this->row(2, 'ASSISTANT', 'TOOL_CALL', '2026-10-20 10:00:05', 'update_task_plan', 1, $this->encodeSnapshot($smallPlan, $snapshotsAsText)),
            $this->row(3, 'ASSISTANT', 'TOOL_CALL', '2026-10-20 10:00:09', 'set_task_status', 1),
            $this->row(4, 'ASSISTANT', 'TEXT', '2026-10-20 10:00:12', null, 1),
            $this->row(5, 'USER', 'TEXT', '2026-10-20 10:00:30'),
            $this->row(6, 'ASSISTANT', 'TOOL_CALL', '2026-10-20 10:00:35', 'update_task_plan', 1, $this->encodeSnapshot($largePlan, $snapshotsAsText)),
            $this->row(7, 'ASSISTANT', 'TEXT', '2026-10-20 10:00:40', null, 1),
        ];
        $smallTailTokens = TaskPlanSize::estimateTailTokens($smallPlan);
        $largeTailTokens = TaskPlanSize::estimateTailTokens($largePlan);
        self::assertGreaterThan($smallTailTokens, $largeTailTokens);

        $runReport = AIConversationRunReport::buildFromRows(AIConversationRunReportMessageRows::fromRowArrays($rows));

        self::assertNotNull($runReport);
        self::assertNotNull($runReport->taskPlanSize);
        self::assertSame([
            'nodes' => 15,
            'maxDepth' => 3,
            'tailTokensMax' => $largeTailTokens,
            // rows 3, 4 and 6 read the small plan, row 7 the large one
            'tailTokensSum' => 3 * $smallTailTokens + $largeTailTokens,
        ], $runReport->taskPlanSize->toReportFields());
        self::assertSame(5, $runReport->modelCalls, 'five model calls, four of them with a plan in force');
    }

    /**
     * @return string|array<string, mixed> the plan as the JSON text of its column, or as the array the column decodes to
     */
    protected function encodeSnapshot(TaskPlan $taskPlan, bool $asText): string|array
    {
        return $asText ? (string)json_encode($taskPlan->mapToRepository()) : RunReportPrintoutRuns::encodeAndDecode($taskPlan);
    }

    /**
     * @param string|array<string, mixed>|null $taskPlanSnapshot
     * @return array<string, mixed> one message row as the metrics read returns it, with its plan snapshot
     */
    protected function row(int $index, string $role, string $type, string $created, ?string $tool = null, int $snap = 0, string|array|null $taskPlanSnapshot = null): array
    {
        return [
            'i' => $index, 'role' => $role, 't' => $type, 'author' => $role === 'USER' ? 'USER' : 'AGENT', 'c' => $created, 'u' => $created,
            'snap' => $snap, 'dur' => 0.0, 'startedAt' => null, 'egress' => 0, 'cost' => 0.0, 'tool' => $tool, 'args' => '',
            'refusedFlag' => 0, 'resultHead' => '', 'variants' => null, 'skillSections' => null, 'taskPlanSnapshot' => $taskPlanSnapshot,
        ];
    }
}
