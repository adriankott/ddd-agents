<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReport;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportMessageRows;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportModelCost;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportTimeWindow;
use DDD\Domain\AI\Entities\Conversations\RunReports\AIConversationRunReportVariantsTimeline;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The standard per-run report's figures over a synthetic message list: the active wall (a late posting is idle),
 * owner turns, model/tool calls, refusals and identical repeats, the agent/owner split, the tool SUM versus the tool
 * WALL (overlapping executions count once), the non-blocking delegates and the slowest tools — and the variants
 * timeline (one row per dimension change, an evidence-only `@N` change is none) with the header delta.
 */
class AIConversationRunReportTest extends UnitTestCase
{
    public function testTheReportFiguresOverASyntheticRun(): void
    {
        $rows = [
            $this->row(1, 'USER', 'TEXT', '2026-10-04 10:00:00'),
            $this->row(2, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:05', tool: 'readSummary', dur: 2.0, startedAt: '2026-10-04 10:00:03.000', args: '{"a":1}', snap: 1, egress: 2500),
            $this->row(3, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:05', tool: 'searchTheWeb', dur: 3.0, startedAt: '2026-10-04 10:00:03.500', args: '{"q":"x"}'),
            $this->row(4, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:09', tool: 'readSummary', dur: 0.0, startedAt: null, args: '{"a":1}', head: 'You already called `readSummary` with these'),
            $this->row(5, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:12', tool: 'updateNotes', dur: 2.0, startedAt: '2026-10-04 10:00:10.000', args: '{"n":"x"}', refusedFlag: 1, head: 'Execution failed for Tool'),
            $this->row(6, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:13', tool: 'delegate', dur: 0.5, startedAt: '2026-10-04 10:00:12.500', args: '{"t":"x"}'),
            $this->row(7, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:14', tool: 'set_task_status', dur: 0.0, startedAt: null, args: '{"u":1}', snap: 1, egress: 1500),
            $this->row(8, 'ASSISTANT', 'TEXT', '2026-10-04 10:00:20'),
            $this->row(9, 'USER', 'TEXT', '2026-10-04 10:00:35'),
            $this->row(10, 'ASSISTANT', 'TEXT', '2026-10-04 10:00:50', snap: 1, egress: 3000),
            // a scheduled posting an hour later is not the run's work
            $this->row(11, 'ASSISTANT', 'TEXT', '2026-10-04 11:30:00'),
        ];
        $runReport = AIConversationRunReport::buildFromRows(AIConversationRunReportMessageRows::fromRowArrays($rows));
        self::assertNotNull($runReport);
        self::assertSame(50.0, $runReport->wallSeconds, 'the active wall: the posting long after the turn ended is idle time, not the run');
        self::assertSame(2, $runReport->ownerTurns);
        self::assertSame(2, $runReport->executionTurns);
        self::assertEqualsWithDelta(24.5, $runReport->platformOverheadSeconds, 0.01, 'agent 35 − |union(model, tools) ∩ agent gaps| 10.5: overlapping model and tool time counts once');
        self::assertSame(3, $runReport->modelCalls, 'model calls = messages with a request snapshot');
        self::assertSame(6, $runReport->toolCalls);
        self::assertSame(2, $runReport->refusedToolCalls, 'the duplicate read and the gate-refused write');
        self::assertSame(1, $runReport->identicalRepeats, 'the identical second readSummary');
        self::assertSame(7.0, $runReport->modelSeconds, 'egress milliseconds summed');
        self::assertEqualsWithDelta(0.03, $runReport->modelCostInUSD, 0.0001, 'the agent model cost = the three model calls\' costInUSD');
        self::assertSame(0.0, $runReport->decisionModelCostInUSD, 'the decision-model cost is read separately');
        self::assertSame(0, $runReport->decisionModelCalls);
        self::assertSame(7.0, $runReport->toolSeconds, 'blocking tool seconds: 2 + 3 + 0 + 2 (the delegate is not blocking)');
        self::assertEqualsWithDelta(5.5, $runReport->toolWallSeconds, 0.01, 'the two overlapping reads count once (3.0–6.5), the write apart (10–12)');
        self::assertSame(1, $runReport->nonBlockingToolCalls);
        self::assertSame(0.5, $runReport->nonBlockingToolSeconds);
        self::assertSame(35.0, $runReport->agentSeconds, 'turn 1: 10:00:00 → 10:00:20 (20 s); turn 2: 10:00:35 → 10:00:50 (15 s)');
        self::assertSame(17.5, $runReport->agentSecondsPerTurn);
        self::assertSame(15.0, $runReport->ownerSeconds, 'the owner typed from 10:00:20 to 10:00:35');
        self::assertSame(5350.0, $runReport->metrics->idleSeconds, 'the scheduled posting an hour and a half later');
        self::assertSame(1, $runReport->planTicks);
        self::assertSame(0, $runReport->skillLoads);
        self::assertSame('searchTheWeb', $runReport->slowestTools->first()?->toolName);
        self::assertSame(1, $runReport->slowestTools->first()?->calls);
        self::assertNull(AIConversationRunReport::buildFromRows(AIConversationRunReportMessageRows::fromRowArrays([])));
    }

    public function testTheSlowestToolsKeepSixToolsSlowestFirstAndTiesInCallOrder(): void
    {
        $rows = [$this->row(1, 'USER', 'TEXT', '2026-10-04 10:00:00')];
        $toolSeconds = ['toolA' => 1.0, 'toolB' => 4.0, 'toolC' => 1.0, 'toolD' => 2.0, 'toolE' => 0.5, 'toolF' => 3.0, 'toolG' => 0.25];
        $messageIndex = 2;
        foreach ($toolSeconds as $toolName => $durationSeconds) {
            $rows[] = $this->row($messageIndex, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:0' . $messageIndex, tool: $toolName, dur: $durationSeconds, args: '{}');
            $messageIndex++;
        }
        $rows[] = $this->row($messageIndex, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:09', tool: 'toolA', dur: 1.5, args: '{"second":1}');

        $runReport = AIConversationRunReport::buildFromRows(AIConversationRunReportMessageRows::fromRowArrays($rows));

        self::assertNotNull($runReport);
        $slowestToolNames = [];
        foreach ($runReport->slowestTools->getElements() as $toolTime) {
            $slowestToolNames[] = $toolTime->toolName;
        }
        self::assertSame(['toolB', 'toolF', 'toolA', 'toolD', 'toolC', 'toolE'], $slowestToolNames, 'toolA sums 2.5 s over two calls; toolG is the seventh');
        $toolA = $runReport->slowestTools->getElements()[2];
        self::assertSame(2.5, $toolA->seconds);
        self::assertSame(2, $toolA->calls);
        self::assertSame(1.5, $toolA->maxSeconds);
    }

    public function testTheVariantsTimelineListsOneRowPerDimensionChangeAndTheHeaderDelta(): void
    {
        $rows = [
            $this->row(1, 'USER', 'TEXT', '2026-10-04 10:00:00'),
            $this->row(2, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:05', tool: 'importRecord', snap: 1,
                variants: '{"visitClass":"unknown","identityPath":"import(owner@1)","locality":"local(purpose)","modality":"chat(purpose)"}',
                skillSections: '["Flow.Identify[identityPath=import]","Flow.Profile[visitClass=unknown|hybrid]"]'),
            // the record proposes the class: ONE change (visitClass), the import evidence is re-anchored only
            $this->row(3, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:09', tool: 'readSummary', snap: 1,
                variants: '{"visitClass":"inPerson(proposed,record@2)","identityPath":"import(owner@2)","locality":"local(purpose)","modality":"chat(purpose)"}',
                skillSections: '["Flow.Identify[identityPath=import]","Flow.Profile[visitClass=inPerson]"]'),
            $this->row(4, 'ASSISTANT', 'TEXT', '2026-10-04 10:00:12'),
            $this->row(5, 'USER', 'TEXT', '2026-10-04 10:00:20'),
            // the owner's nod: the class is decided — same value, new provenance = a change; the evidence alone moved on identityPath
            $this->row(6, 'ASSISTANT', 'TOOL_CALL', '2026-10-04 10:00:25', tool: 'updateReach', snap: 1,
                variants: '{"visitClass":"inPerson(owner@5)","identityPath":"import(owner@5)","locality":"local(purpose)","modality":"chat(purpose)"}',
                skillSections: '["Flow.Profile[visitClass=inPerson]","Flow.Reach[visitClass=inPerson]"]'),
            $this->row(7, 'ASSISTANT', 'TEXT', '2026-10-04 10:00:30', snap: 1,
                variants: '{"visitClass":"inPerson(owner@5)","identityPath":"import(owner@5)","locality":"local(purpose)","modality":"chat(purpose)"}',
                skillSections: '["Flow.Profile[visitClass=inPerson]"]'),
        ];
        $runReport = AIConversationRunReport::buildFromRows(AIConversationRunReportMessageRows::fromRowArrays($rows), skillSectionIdPrefixToDrop: 'Flow.');
        self::assertNotNull($runReport);
        $timeline = $runReport->variantsTimeline->changes->toArray();
        self::assertCount(3, $timeline, 'the initial vector + the two dimension changes; the evidence-only moves do not count');
        self::assertSame(['at' => 2, 'dimension' => '*', 'from' => '—', 'to' => 'visitClass=unknown identityPath=import(owner@1) locality=local(purpose) modality=chat(purpose)', 'sections' => 'Identify[identityPath=import], Profile[visitClass=unknown|hybrid]', 'toolAfter' => 'readSummary'], $timeline[0]);
        self::assertSame(['at' => 3, 'dimension' => 'visitClass', 'from' => 'unknown', 'to' => 'inPerson(proposed,record@2)', 'sections' => 'Identify[identityPath=import], Profile[visitClass=inPerson]', 'toolAfter' => 'updateReach'], $timeline[1]);
        self::assertSame(['at' => 6, 'dimension' => 'visitClass', 'from' => 'inPerson(proposed,record@2)', 'to' => 'inPerson(owner@5)', 'sections' => 'Profile[visitClass=inPerson], Reach[visitClass=inPerson]', 'toolAfter' => ''], $timeline[2]);
        self::assertSame('visitClass unknown → inPerson(owner)', $runReport->variantsTimeline->delta, 'only the dimensions that changed, the evidence index stripped');
        self::assertSame(2, $runReport->variantTransitions);
        self::assertSame('visitClass=inPerson(owner) identityPath=import(owner) locality=local(purpose) modality=chat(purpose)', $runReport->variantsFinal, 'the final column strips the evidence index');

        $withoutSnapshots = AIConversationRunReport::buildFromRows(AIConversationRunReportMessageRows::fromRowArrays([$this->row(1, 'USER', 'TEXT', '2026-10-04 10:00:00')]));
        self::assertNotNull($withoutSnapshots);
        self::assertSame([], $withoutSnapshots->variantsTimeline->changes->toArray());
        self::assertSame('', $withoutSnapshots->variantsTimeline->delta);
    }

    public function testTheSkillSectionPrefixIsDroppedOnlyAtTheStartOfAnId(): void
    {
        $skillSectionsJson = '["Flow.Profile[a=b]","Other.Flow.Reach","Flow.","Flowchart.Plan"]';

        self::assertSame('Profile[a=b], Other.Flow.Reach, , Flowchart.Plan', AIConversationRunReportVariantsTimeline::describeSkillSections($skillSectionsJson, 'Flow.'));
        self::assertSame('Flow.Profile[a=b], Other.Flow.Reach, Flow., Flowchart.Plan', AIConversationRunReportVariantsTimeline::describeSkillSections($skillSectionsJson), 'no prefix by default');
        self::assertSame('', AIConversationRunReportVariantsTimeline::describeSkillSections('null'));
        self::assertSame('', AIConversationRunReportVariantsTimeline::describeSkillSections(null));
    }

    public function testTheTimeWindowRunsFromTheFirstRowToTwoMinutesAfterTheLast(): void
    {
        $timeWindow = AIConversationRunReportTimeWindow::fromRows(AIConversationRunReportMessageRows::fromRowArrays([
            ['c' => '2026-10-04 10:05:00.123456'],
            ['c' => '2026-10-04 10:00:00'],
            ['c' => ''],
            ['c' => '2026-10-04 10:59:30'],
        ]));

        self::assertNotNull($timeWindow);
        self::assertSame('2026-10-04 10:00:00', $timeWindow->from);
        self::assertSame('2026-10-04 11:01:30', $timeWindow->to);
        self::assertNull(AIConversationRunReportTimeWindow::fromRows(AIConversationRunReportMessageRows::fromRowArrays([['c' => ''], []])));
    }

    public function testTheDecisionModelCostIsTakenOverAsIs(): void
    {
        $runReport = AIConversationRunReport::buildFromRows(AIConversationRunReportMessageRows::fromRowArrays([$this->row(1, 'USER', 'TEXT', '2026-10-04 10:00:00')]));
        self::assertNotNull($runReport);

        $runReport->applyDecisionModelCost(AIConversationRunReportModelCost::forCostAndCalls(0.0421, 4));

        self::assertSame(0.0421, $runReport->decisionModelCostInUSD);
        self::assertSame(4, $runReport->decisionModelCalls);
        self::assertSame(0.0, AIConversationRunReportModelCost::none()->costInUSD);
        self::assertSame(0, AIConversationRunReportModelCost::none()->calls);
    }

    /** @return array<string, mixed> */
    protected function row(int $index, string $role, string $type, string $created, ?string $tool = null, float $dur = 0.0, ?string $startedAt = null, string $args = '', int $snap = 0, int $egress = 0, int $refusedFlag = 0, string $head = '', ?string $variants = null, ?string $skillSections = null): array
    {
        return ['i' => $index, 'role' => $role, 't' => $type, 'c' => $created, 'u' => $created, 'snap' => $snap, 'dur' => $dur, 'startedAt' => $startedAt, 'egress' => $egress, 'cost' => $snap ? 0.01 : 0.0, 'tool' => $tool, 'args' => $args, 'refusedFlag' => $refusedFlag, 'resultHead' => $head, 'variants' => $variants, 'skillSections' => $skillSections];
    }
}
