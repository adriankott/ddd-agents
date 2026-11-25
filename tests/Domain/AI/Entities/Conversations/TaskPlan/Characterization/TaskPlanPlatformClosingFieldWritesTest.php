<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteriaWithFixedVerdicts;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;

/**
 * The fields every platform closing and re-open of one item writes, and the ones it leaves as they were. Each item
 * starts from {@see moduleSentinelItem()}: every field a writer may touch carries a recognisable value, so a field a
 * closing leaves alone keeps that value in the expected row. The closings: the criterion's pre-resolution (done,
 * blocked), a completed call (done, blocked, not_applicable), the turn end (a rendered widget, a delivered answer, a
 * widget the owner declined), the record deciding a tick (confirmed, overridden, parked) and the re-open of a leaf the
 * platform closes on a transient obstacle. The feedback lines and the counters of the ticks are the gate table's
 * ({@see SetTaskStatusGateTableCharacterizationTest}); here only the item's fields are read.
 */
class TaskPlanPlatformClosingFieldWritesTest extends TaskPlanModuleItemTestCase
{
    // ── the criterion's pre-resolution ──

    public function testAPreResolutionToDoneWritesTheNoteTheStampAndTheSeedMarkAndLeavesTheBlockedFlags(): void
    {
        $leaf = $this->moduleSentinelItem('Notes sorted');
        $leaf->doneCriterion = 'notes.sorted';
        $doneCriteria = TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey(['notes.sorted' => TaskPlanDoneCriterionVerdict::done('already satisfied: 38/5 notes')]);

        $resolvedLeafCount = $this->itemSet([$leaf])->applyPreResolutions($doneCriteria, TaskPlanDoneCriteriaWithFixedVerdicts::inputsFor(null), $this->messages([$this->ownerText(10, 'hi'), $this->assistantText(11, 'hello')]));

        self::assertSame(1, $resolvedLeafCount);
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_DONE, [
            'statusNote' => 'already satisfied: 38/5 notes',
            'platformResolvedAtMessageIndex' => 11,
            'preResolvedAtSeed' => true,
        ]), $this->moduleMutableFieldsOf($leaf), 'requiresUserAction and retryable survive a pre-resolution to done');
    }

    public function testAPreResolutionToBlockedWritesTheVerdictsUserActionClearsRetryableAndStampsZeroWithoutMessages(): void
    {
        $leaf = $this->moduleSentinelItem('Connect offer');
        $leaf->doneCriterion = 'channel.connected';
        $leaf->requiresUserAction = false;
        $leaf->preResolvedAtSeed = false;
        $doneCriteria = TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey(['channel.connected' => TaskPlanDoneCriterionVerdict::blockedByOwnerStatement('the owner declined the connect')]);

        $this->itemSet([$leaf])->applyPreResolutions($doneCriteria, TaskPlanDoneCriteriaWithFixedVerdicts::inputsFor(null));

        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_BLOCKED, [
            'statusNote' => 'the owner declined the connect',
            'requiresUserAction' => true,
            'retryable' => false,
            'platformResolvedAtMessageIndex' => 0,
            'preResolvedAtSeed' => true,
        ]), $this->moduleMutableFieldsOf($leaf));
    }

    // ── a completed call ──

    public function testACompletedCallClosingToDoneClearsTheBlockedFlagsAndStampsTheCallsIndex(): void
    {
        $leaf = $this->moduleSentinelItem('Goals captured');
        $leaf->doneRequiresToolCall = 'recordGoal';
        $messages = $this->messages([$this->ownerText(10, 'more visits'), $this->completedCall(12, 'recordGoal')]);

        $closings = $this->itemSet([$leaf])->closeLeavesProvenByCompletedCall('recordGoal', 12, TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: false))->getStatusByNumberPath();

        self::assertSame(['1' => TaskPlanItem::STATUS_DONE], $closings);
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_DONE, [
            'statusNote' => 'closed by the platform: recordGoal COMPLETED @12',
            'requiresUserAction' => false,
            'retryable' => false,
            'platformResolvedAtMessageIndex' => 12,
        ]), $this->moduleMutableFieldsOf($leaf), 'evidenceMessageIndex and preResolvedAtSeed stay as they were');
    }

    public function testACompletedCallClosingToBlockedSetsUserActionAndToNotApplicableClearsIt(): void
    {
        $blockedLeaf = $this->moduleSentinelItem('Connect offer');
        $blockedLeaf->blockedRequiresToolCall = 'saveStatement';
        $blockedLeaf->requiresUserAction = false;
        $notApplicableLeaf = $this->moduleSentinelItem('Service areas');
        $notApplicableLeaf->notApplicableRequiresToolCall = 'saveReach';
        $messages = $this->messages([$this->ownerText(10, 'no'), $this->completedCall(12, 'saveStatement'), $this->completedCall(13, 'saveReach')]);

        self::assertSame(['1' => TaskPlanItem::STATUS_BLOCKED], $this->itemSet([$blockedLeaf])->closeLeavesProvenByCompletedCall('saveStatement', 12, TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: false))->getStatusByNumberPath());
        self::assertSame(['1' => TaskPlanItem::STATUS_NOT_APPLICABLE], $this->itemSet([$notApplicableLeaf])->closeLeavesProvenByCompletedCall('saveReach', 13, TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: false))->getStatusByNumberPath());

        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_BLOCKED, [
            'statusNote' => 'closed by the platform: saveStatement COMPLETED @12',
            'requiresUserAction' => true,
            'retryable' => false,
            'platformResolvedAtMessageIndex' => 12,
        ]), $this->moduleMutableFieldsOf($blockedLeaf));
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_NOT_APPLICABLE, [
            'statusNote' => 'closed by the platform: saveReach COMPLETED @13',
            'requiresUserAction' => false,
            'retryable' => false,
            'platformResolvedAtMessageIndex' => 13,
        ]), $this->moduleMutableFieldsOf($notApplicableLeaf));
    }

    // ── the turn end ──

    public function testAWidgetTheOwnerDeclinedClosesNotApplicableWithBothIndexesAndLeavesTheBlockedFlags(): void
    {
        $leaf = $this->moduleSentinelItem('Pick your entry');
        $leaf->doneRequiresWidget = 'ChoiceWidget';
        $leaf->doneRequiresWidgetAnswered = true;
        $messages = $this->messages([
            $this->ownerText(10, 'The bakery on the corner'),
            $this->toolWrittenWidget(11, $this->widgetFence('ChoiceWidget', ['options' => [['id' => 'one']]])),
            $this->widgetClick(12, 'User declined on the ChoiceWidget card: none of the shown options is theirs.'),
        ]);

        $closings = $this->itemSet([$leaf])->closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd(TaskPlanEvidence::forTurn($messages, 12, criteriaDecideTicks: false))->getStatusByNumberPath();

        self::assertSame(['1' => TaskPlanItem::STATUS_NOT_APPLICABLE], $closings);
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_NOT_APPLICABLE, [
            'statusNote' => 'closed by the platform: the owner declined on the ChoiceWidget widget',
            'platformResolvedAtMessageIndex' => 12,
            'evidenceMessageIndex' => 12,
        ]), $this->moduleMutableFieldsOf($leaf), 'requiresUserAction and retryable survive the decline');
    }

    public function testARenderedWidgetClosesDoneWithBothIndexes(): void
    {
        $leaf = $this->moduleSentinelItem('Handover delivered');
        $leaf->doneRequiresWidget = 'HandoverWidget';
        $messages = $this->messages([$this->ownerText(10, 'go on'), $this->assistantText(11, "All set.\n" . $this->widgetFence('HandoverWidget'))]);

        $closings = $this->itemSet([$leaf])->closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd(TaskPlanEvidence::forTurn($messages, 10, criteriaDecideTicks: false))->getStatusByNumberPath();

        self::assertSame(['1' => TaskPlanItem::STATUS_DONE], $closings);
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_DONE, [
            'statusNote' => 'closed by the platform: HandoverWidget widget rendered',
            'requiresUserAction' => false,
            'retryable' => false,
            'platformResolvedAtMessageIndex' => 11,
            'evidenceMessageIndex' => 11,
        ]), $this->moduleMutableFieldsOf($leaf));
    }

    public function testADeliveredAnswerClosesDoneWithTheNewestIndexAsBothStamps(): void
    {
        $leaf = $this->moduleSentinelItem('Note count delivered');
        $leaf->doneRequiresOwnerVisibleAnswerInSameTurn = true;
        $leaf->doneRequiresToolCall = 'countNotes';
        $messages = $this->messages([$this->ownerText(10, 'how many notes do I have?'), $this->completedCall(12, 'countNotes'), $this->assistantText(14, 'You have 12 notes.')]);

        $closings = $this->itemSet([$leaf])->closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd(TaskPlanEvidence::forTurn($messages, 10, criteriaDecideTicks: false))->getStatusByNumberPath();

        self::assertSame(['1' => TaskPlanItem::STATUS_DONE], $closings);
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_DONE, [
            'statusNote' => 'closed by the platform: delivered to the owner after countNotes @12',
            'requiresUserAction' => false,
            'retryable' => false,
            'platformResolvedAtMessageIndex' => 14,
            'evidenceMessageIndex' => 14,
        ]), $this->moduleMutableFieldsOf($leaf));
    }

    // ── the record decides a tick ──

    public function testTheRecordDecidingATickWritesItsStatusNoteAndTheInitiatorIndexAndKeepsTheSeedMark(): void
    {
        $confirmedLeaf = $this->moduleSentinelItem('Contact reachable');
        $confirmedLeaf->doneCriterion = 'contact.reachable';
        $overriddenLeaf = $this->moduleSentinelItem('Opening hours');
        $overriddenLeaf->doneCriterion = 'hours.set';
        $doneCriteria = TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey([
            'contact.reachable' => TaskPlanDoneCriterionVerdict::done('phone saved on the profile'),
            'hours.set' => TaskPlanDoneCriterionVerdict::done('hours stored: Mo–Fr 9–17'),
        ]);

        $tickResult = SetTaskStatusTestTick::apply(
            ['updates' => [
                ['task' => '1', 'status' => 'done', 'statusNote' => 'model note'],
                ['task' => '2', 'status' => 'not_applicable', 'statusNote' => 'model thinks n/a'],
            ]],
            $this->taskPlan([$confirmedLeaf, $overriddenLeaf]),
            10,
            $this->messages([$this->ownerText(10, 'call us')]),
            null,
            [],
            $doneCriteria
        );

        self::assertSame(2, $tickResult['updatedCount']);
        $recordWrites = ['requiresUserAction' => false, 'retryable' => false, 'platformResolvedAtMessageIndex' => 10];
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_DONE, ['statusNote' => 'phone saved on the profile'] + $recordWrites), $this->moduleMutableFieldsOf($confirmedLeaf), 'preResolvedAtSeed stays true on a tick the record confirms');
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_DONE, ['statusNote' => 'hours stored: Mo–Fr 9–17'] + $recordWrites), $this->moduleMutableFieldsOf($overriddenLeaf), 'preResolvedAtSeed stays true on a tick the record overrides');
    }

    public function testTheRecordParkingADoneTickWritesBlockedWithTheVerdictsUserActionAndTheInitiatorIndex(): void
    {
        $leaf = $this->moduleSentinelItem('Contact reachable');
        $leaf->doneCriterion = 'contact.reachable';
        $leaf->requiresUserAction = false;
        $doneCriteria = TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey([
            'contact.reachable' => TaskPlanDoneCriterionVerdict::blockedByOwnerStatement('the owner has no phone line'),
        ]);

        SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'model note']]],
            $this->taskPlan([$leaf]),
            10,
            $this->messages([$this->ownerText(10, 'call us')]),
            null,
            [],
            $doneCriteria
        );

        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_BLOCKED, [
            'statusNote' => 'the owner has no phone line',
            'requiresUserAction' => true,
            'retryable' => false,
            'platformResolvedAtMessageIndex' => 10,
        ]), $this->moduleMutableFieldsOf($leaf));
    }

    public function testTheRecordConfirmingAnAppliedTickWritesItsNoteAndTheInitiatorIndexOverTheTicksOwnWrites(): void
    {
        $leaf = $this->moduleSentinelItem('Round closed');
        $leaf->doneCriterion = LeafReadingItsOwnStatusDoneCriterion::KEY;

        $tickResult = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'model note']]],
            $this->taskPlan([$leaf]),
            10,
            $this->messages([$this->ownerText(10, 'done with it')]),
            null,
            [],
            TaskPlanDoneCriteria::fromClasses(LeafReadingItsOwnStatusDoneCriterion::class)
        );

        self::assertSame(1, $tickResult['updatedCount']);
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_DONE, [
            'statusNote' => 'the plan shows the round closed',
            'requiresUserAction' => false,
            'retryable' => false,
            'platformResolvedAtMessageIndex' => 10,
            'preResolvedAtSeed' => false,
        ]), $this->moduleMutableFieldsOf($leaf), 'the applied tick cleared the flags and the seed mark; the record read after it keeps the evidence and offer stamps');
    }

    // ── the re-open on a transient obstacle ──

    public function testATransientObstacleReopensWithTheObstacleAsTheNoteAndKeepsTheRecordStamps(): void
    {
        $leaf = $this->moduleSentinelItem('Rivals identified', TaskPlanItem::STATUS_OPEN, null);
        $leaf->doneRequiresToolCall = 'addRivals|updateRivals';
        $taskPlan = $this->taskPlan([$leaf]);

        $tickResult = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => 'discovery read refused', 'retryable' => true, 'requiresUserAction' => false]]],
            $taskPlan,
            10,
            $this->messages([$this->ownerText(10, 'go')])
        );

        self::assertSame(1, $tickResult['notedObstacleCount']);
        self::assertSame($this->moduleSentinelFieldsWith(TaskPlanItem::STATUS_OPEN, [
            'statusNote' => 'discovery read refused',
            'requiresUserAction' => false,
            'retryable' => false,
        ], null), $this->moduleMutableFieldsOf($leaf));
    }
}
