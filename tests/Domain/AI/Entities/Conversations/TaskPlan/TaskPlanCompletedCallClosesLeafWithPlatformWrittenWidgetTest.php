<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanCharacterizationTestCase;

/**
 * A leaf that asks for a tool call AND a rendered widget closes in the completed-call pass when the tool made the
 * platform write that widget while the call ran; every other widget leaf stays open until the turn end.
 */
class TaskPlanCompletedCallClosesLeafWithPlatformWrittenWidgetTest extends TaskPlanCharacterizationTestCase
{
    protected function summaryLeaf(): TaskPlanItem
    {
        $summaryLeaf = $this->item('Summary delivered');
        $summaryLeaf->doneRequiresToolCall = 'deliverSummary';
        $summaryLeaf->doneRequiresWidget = 'Summary';
        $summaryLeaf->doneRequiresWidgetFilledFields = ['promise' => ['topic' => '<what you deliver>']];
        return $summaryLeaf;
    }

    public function testACompletedCallClosesTheLeafWhoseWidgetThePlatformWroteDuringThatCall(): void
    {
        $summaryLeaf = $this->summaryLeaf();
        $otherLeaf = $this->item('Note recorded');
        $taskPlanItems = $this->itemSet([$otherLeaf, $summaryLeaf]);
        $messages = $this->messages([
            $this->ownerText(10, 'go ahead'),
            $this->completedCall(12, 'deliverSummary'),
            $this->toolWrittenWidget(13, $this->widgetFence('Summary', ['promise' => ['topic' => 'the first report']])),
        ]);

        $closings = $taskPlanItems->closeLeavesProvenByCompletedCall('deliverSummary', 12, TaskPlanEvidence::forTurn($messages, 10, criteriaDecideTicks: true));

        self::assertSame(['2' => TaskPlanItem::STATUS_DONE], $closings->getStatusByNumberPath());
        self::assertSame('closed by the platform: Summary widget rendered after deliverSummary @12', $summaryLeaf->statusNote);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $otherLeaf->status);
    }

    public function testTheLeafStaysOpenWithoutTheWidgetRow(): void
    {
        $summaryLeaf = $this->summaryLeaf();
        $messages = $this->messages([$this->ownerText(10, 'go ahead'), $this->completedCall(12, 'deliverSummary')]);

        $closings = $this->itemSet([$summaryLeaf])->closeLeavesProvenByCompletedCall('deliverSummary', 12, TaskPlanEvidence::forTurn($messages, 10));

        self::assertFalse($closings->hasClosings());
        self::assertSame(TaskPlanItem::STATUS_OPEN, $summaryLeaf->status);
    }

    public function testTheLeafStaysOpenWhileTheWidgetMissesItsRequiredField(): void
    {
        $summaryLeaf = $this->summaryLeaf();
        $messages = $this->messages([
            $this->ownerText(10, 'go ahead'),
            $this->completedCall(12, 'deliverSummary'),
            $this->toolWrittenWidget(13, $this->widgetFence('Summary')),
        ]);

        $closings = $this->itemSet([$summaryLeaf])->closeLeavesProvenByCompletedCall('deliverSummary', 12, TaskPlanEvidence::forTurn($messages, 10));

        self::assertFalse($closings->hasClosings());
    }

    public function testALeafWhoseWidgetTheOwnerAnswersOrThatWaitsForAnAnswerTextStaysOpen(): void
    {
        $answeredLeaf = $this->summaryLeaf();
        $answeredLeaf->doneRequiresWidgetAnswered = true;
        $deliveryLeaf = $this->summaryLeaf();
        $deliveryLeaf->doneRequiresOwnerVisibleAnswerInSameTurn = true;
        $messages = $this->messages([
            $this->ownerText(10, 'go ahead'),
            $this->completedCall(12, 'deliverSummary'),
            $this->toolWrittenWidget(13, $this->widgetFence('Summary', ['promise' => ['topic' => 'the first report']])),
        ]);

        $closings = $this->itemSet([$answeredLeaf, $deliveryLeaf])->closeLeavesProvenByCompletedCall('deliverSummary', 12, TaskPlanEvidence::forTurn($messages, 10));

        self::assertFalse($closings->hasClosings());
        self::assertSame(TaskPlanItem::STATUS_OPEN, $answeredLeaf->status);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $deliveryLeaf->status);
    }

    public function testACallOfAnotherToolDoesNotCloseTheLeaf(): void
    {
        $summaryLeaf = $this->summaryLeaf();
        $messages = $this->messages([
            $this->ownerText(10, 'go ahead'),
            $this->completedCall(11, 'deliverSummary'),
            $this->toolWrittenWidget(12, $this->widgetFence('Summary', ['promise' => ['topic' => 'the first report']])),
            $this->completedCall(13, 'recordNote'),
        ]);

        $closings = $this->itemSet([$summaryLeaf])->closeLeavesProvenByCompletedCall('recordNote', 13, TaskPlanEvidence::forTurn($messages, 10));

        self::assertFalse($closings->hasClosings());
    }

    public function testTheEvidenceWithoutEntitlementLocksReportsNoToolLocked(): void
    {
        $taskPlanEvidence = TaskPlanEvidence::forTurn($this->messages([]), entitlementLockedToolNames: ['deliverSummary']);

        self::assertTrue($taskPlanEvidence->locksAnyOfTools(['deliverSummary']));
        self::assertFalse($taskPlanEvidence->withoutEntitlementLocks()->locksAnyOfTools(['deliverSummary']));
        self::assertTrue($taskPlanEvidence->locksAnyOfTools(['deliverSummary']));
    }
}
