<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The predicate families of the plan that exist in several members, each member asked with the same fixtures:
 * "the item is gated" (three item predicates), "the answer came after the evidence" (the tick and the turn-end
 * closing), "the completed call proves done" (the list of items the model may tick after a call, and the closing of
 * the leaves the call proves), and "the purpose seeds a plan". A change that merges two members, or moves a field
 * from one member to another, shows here as the rows that change. The tick gates of `set_task_status` are the table
 * of {@see SetTaskStatusGateTableCharacterizationTest}; they are not repeated here.
 */
class TaskPlanPredicateFamiliesTest extends TaskPlanModuleItemTestCase
{
    /** @return array<string, mixed> one non-default value per declaration and platform record of the module's item */
    protected static function singleFieldValues(): array
    {
        return [
            'ownerFacingTitle' => 'Owner title',
            'doneCriterion' => 'crit.key',
            'doneRequiresToolCall' => 'recordGoal',
            'doneRequiresToolCallArgumentsAbsent' => ['confirmed' => [false]],
            'doneRequiresToolCallArgumentsPresent' => ['confirmed' => [true]],
            'notApplicableRequiresToolCall' => 'saveReach',
            'notApplicableRequiresToolCallArgumentsAbsent' => ['topics' => ['privacy']],
            'notApplicableRequiresToolCallArgumentsPresent' => ['kind' => ['DECLINE']],
            'notApplicableForbiddenAfterToolCall' => 'saveLinks',
            'doneRequiresWidget' => 'HandoverWidget',
            'doneRequiresConnectedChannel' => 'calendar',
            'closedQuestionOptions' => ['en' => ['Yes', 'No']],
            'minReasoningEffort' => 'medium',
            'doneRequiresWidgetFilledFields' => ['promise' => ['date' => '<YYYY-MM-DD>']],
            'doneRequiresWidgetAnswered' => true,
            'doneRequiresOwnerVisibleAnswerInSameTurn' => true,
            'doneRequiresOwnerQuestionKey' => 'approval',
            'doneAlsoOnOwnerAnswerOfWidget' => 'ChoiceWidget',
            'doneOwnerQuestionMayFollowToolCall' => 'proposeChoices',
            'previousTurnDeliveryEvidenceToolNames' => ['countNotes'],
            'blockedRequiresWidget' => 'OfferWidget',
            'blockedRequiresToolCall' => 'saveStatement',
            'blockedRequiresToolCallArgumentsPresent' => ['topics' => ['entry']],
            'triggersSupervisorMilestoneCheckpoint' => true,
            'customerProgressStepKey' => 'step.one',
            'skillNames' => ['Skill.One'],
            'evidenceMessageIndex' => 14,
            'offerRenderedAtMessageIndex' => 9,
            'preResolvedAtSeed' => true,
            'platformResolvedAtMessageIndex' => 12,
            'answerTextHeldOpenAtMessageIndex' => 8,
        ];
    }

    protected function itemWithOnlyField(string $text, string $fieldName): TaskPlanItem
    {
        return $this->itemWithFields($text, [$fieldName => self::singleFieldValues()[$fieldName]]);
    }

    /** @return array<string, array{0: string, 1: string[]}> */
    public static function provideItemPredicateMembers(): array
    {
        return [
            'carriesEvidenceGate: the checked stop counts the item as gated' => ['carriesEvidenceGate', [
                'doneRequiresToolCall', 'notApplicableRequiresToolCall', 'notApplicableForbiddenAfterToolCall',
                'doneRequiresWidget', 'doneRequiresConnectedChannel', 'doneRequiresOwnerVisibleAnswerInSameTurn',
                'previousTurnDeliveryEvidenceToolNames', 'blockedRequiresWidget', 'blockedRequiresToolCall', 'preResolvedAtSeed',
            ]],
            'carriesRecordEvidenceGate: the record can prove the item' => ['carriesRecordEvidenceGate', [
                'doneCriterion', 'doneRequiresToolCall', 'notApplicableRequiresToolCall', 'doneRequiresWidget',
                'doneRequiresConnectedChannel', 'doneRequiresOwnerVisibleAnswerInSameTurn',
                'previousTurnDeliveryEvidenceToolNames', 'blockedRequiresWidget', 'blockedRequiresToolCall',
            ]],
            'isClosedByThePlatformFromTheRecord: the platform closes the leaf at done' => ['isClosedByThePlatformFromTheRecord', [
                'doneCriterion', 'doneRequiresToolCall', 'doneRequiresWidget', 'doneRequiresConnectedChannel',
            ]],
        ];
    }

    /** @param string[] $expectedMemberFields */
    #[DataProvider('provideItemPredicateMembers')]
    public function testEachItemPredicateAnswersTrueForExactlyItsMembers(string $predicateMethodName, array $expectedMemberFields): void
    {
        $memberFields = [];
        foreach (array_keys(self::singleFieldValues()) as $fieldName) {
            if ($this->itemWithOnlyField('Gated', $fieldName)->{$predicateMethodName}()) {
                $memberFields[] = $fieldName;
            }
        }
        self::assertSame($expectedMemberFields, $memberFields);
        self::assertFalse($this->item('Plain')->{$predicateMethodName}(), 'an item without a field is in no family');
    }

    // ── "the answer came after the evidence": the tick and the turn-end closing on three orders ──

    protected function deliveryLeaf(): TaskPlanItem
    {
        return $this->itemWithFields('Note count delivered', [
            'doneRequiresOwnerVisibleAnswerInSameTurn' => true,
            'doneRequiresToolCall' => 'countNotes',
        ]);
    }

    /** @return array{tickStatus: string, tickFeedback: string[], turnEndClosings: array<string, string>, turnEndNote: ?string} */
    protected function deliveryMemberResults(AIConversationMessages $conversationMessages): array
    {
        $tickLeaf = $this->deliveryLeaf();
        $tickResult = SetTaskStatusTestTick::apply(['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'delivered']]], $this->taskPlan([$tickLeaf]), 10, $conversationMessages);

        $turnEndLeaf = $this->deliveryLeaf();
        $turnEndClosings = $this->itemSet([$turnEndLeaf])
            ->closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd(TaskPlanEvidence::forTurn($conversationMessages, 10, criteriaDecideTicks: false))
            ->getStatusByNumberPath();

        return [
            'tickStatus' => $tickLeaf->status,
            'tickFeedback' => $tickResult['feedback'],
            'turnEndClosings' => $turnEndClosings,
            'turnEndNote' => $turnEndLeaf->statusNote,
        ];
    }

    public function testTheTurnEndClosingRequiresTheEvidenceCallInThisTurnWhileTheTickAcceptsAnEarlierOne(): void
    {
        $callInThePreviousTurnAnswerInThisTurn = $this->messages([$this->completedCall(8, 'countNotes'), $this->ownerText(10, 'and?'), $this->assistantText(12, 'You have 12 notes.')]);

        self::assertSame([
            'tickStatus' => TaskPlanItem::STATUS_DONE,
            'tickFeedback' => [],
            'turnEndClosings' => [],
            'turnEndNote' => null,
        ], $this->deliveryMemberResults($callInThePreviousTurnAnswerInThisTurn));
    }

    public function testTheTurnEndClosingClosesTheLeafWhenTheAnswerFollowsTheCallOfThisTurn(): void
    {
        $callThenAnswerInThisTurn = $this->messages([$this->ownerText(10, 'how many notes do I have?'), $this->completedCall(12, 'countNotes'), $this->assistantText(14, 'You have 12 notes.')]);

        $results = $this->deliveryMemberResults($callThenAnswerInThisTurn);

        self::assertSame(['1' => TaskPlanItem::STATUS_DONE], $results['turnEndClosings']);
        self::assertSame('closed by the platform: delivered to the owner after countNotes @12', $results['turnEndNote']);
    }

    public function testTheTurnEndClosingLeavesTheLeafOpenWhenTheAnswerCameBeforeTheCall(): void
    {
        $answerBeforeTheCallInThisTurn = $this->messages([$this->ownerText(10, 'how many notes do I have?'), $this->assistantText(11, 'Let me check.'), $this->completedCall(12, 'countNotes')]);

        $results = $this->deliveryMemberResults($answerBeforeTheCallInThisTurn);

        self::assertSame([], $results['turnEndClosings']);
        self::assertNull($results['turnEndNote']);
    }

    // ── "the completed call proves done": the tickable list and the closing agree per variant ──

    /** @return array<string, array{0: ?string, 1: bool, 2: bool}> */
    public static function provideCompletedCallVariants(): array
    {
        return [
            'tool gate only' => [null, false, true],
            '+ doneRequiresWidget' => ['doneRequiresWidget', false, false],
            '+ doneRequiresOwnerVisibleAnswerInSameTurn' => ['doneRequiresOwnerVisibleAnswerInSameTurn', false, false],
            '+ doneRequiresConnectedChannel' => ['doneRequiresConnectedChannel', false, false],
            '+ doneCriterion, the criteria decide ticks' => ['doneCriterion', true, false],
            '+ doneCriterion, the criteria do not decide' => ['doneCriterion', false, true],
            '+ doneRequiresWidgetAnswered (no widget named)' => ['doneRequiresWidgetAnswered', false, true],
            '+ previousTurnDeliveryEvidenceToolNames' => ['previousTurnDeliveryEvidenceToolNames', false, true],
        ];
    }

    #[DataProvider('provideCompletedCallVariants')]
    public function testTheTickableListAndTheClosingByTheCompletedCallAgree(?string $extraFieldName, bool $criteriaDecideTicks, bool $expectedProvenByTheCall): void
    {
        $messages = $this->messages([$this->ownerText(10, 'more visits'), $this->completedCall(12, 'recordGoal')]);
        $tickableLeaf = $this->itemWithOnlyField('Goals', 'doneRequiresToolCall');
        $closingLeaf = $this->itemWithOnlyField('Goals', 'doneRequiresToolCall');
        if ($extraFieldName !== null) {
            $tickableLeaf->{$extraFieldName} = self::singleFieldValues()[$extraFieldName];
            $closingLeaf->{$extraFieldName} = self::singleFieldValues()[$extraFieldName];
        }

        $tickablePaths = $this->itemSet([$tickableLeaf])->getItemNumberPathsTickableAfterToolCall('recordGoal', TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: $criteriaDecideTicks));
        $closings = $this->itemSet([$closingLeaf])->closeLeavesProvenByCompletedCall('recordGoal', 12, TaskPlanEvidence::forTurn($messages, criteriaDecideTicks: $criteriaDecideTicks))->getStatusByNumberPath();

        self::assertSame($expectedProvenByTheCall ? ['1'] : [], $tickablePaths);
        self::assertSame($expectedProvenByTheCall ? ['1' => TaskPlanItem::STATUS_DONE] : [], $closings);
        self::assertSame($expectedProvenByTheCall ? TaskPlanItem::STATUS_DONE : TaskPlanItem::STATUS_OPEN, $closingLeaf->status);
    }

    // ── "the purpose seeds a plan" ──

    /** @return array<int, array<string, mixed>> */
    protected static function seedWithAWidgetGate(): array
    {
        return [
            ['text' => 'Identify the business', 'doneRequiresWidget' => 'ConfirmWidget'],
            ['text' => 'Record the goal', 'doneRequiresToolCall' => 'recordGoal'],
        ];
    }

    public function testThePurposeSeedsAPlanWhenItsBuiltSeedHasItems(): void
    {
        $seedingPurpose = self::instantiateWithoutConstructor(AIConversationPurpose::class);
        $seedingPurpose->seedTaskPlanItems = self::seedWithAWidgetGate();
        $plainPurpose = self::instantiateWithoutConstructor(AIConversationPurpose::class);
        $plainPurpose->seedTaskPlanItems = [];

        self::assertTrue($seedingPurpose->seedsTaskPlan());
        self::assertSame($seedingPurpose->seedTaskPlanItems, $seedingPurpose->buildSeedTaskPlanDefinition(), 'the base purpose builds its seed as configured');
        self::assertFalse($plainPurpose->seedsTaskPlan());
        self::assertSame([], $plainPurpose->buildSeedTaskPlanDefinition());
    }

    public function testAPurposeThatReshapesItsSeedIsAskedThroughItsBuiltSeed(): void
    {
        $reshapingPurpose = self::instantiateWithoutConstructor(PurposeDroppingTheWidgetGatesOfItsSeed::class);
        $reshapingPurpose->seedTaskPlanItems = self::seedWithAWidgetGate();

        self::assertTrue($reshapingPurpose->seedsTaskPlan());
        self::assertSame([
            ['text' => 'Identify the business'],
            ['text' => 'Record the goal', 'doneRequiresToolCall' => 'recordGoal'],
        ], $reshapingPurpose->buildSeedTaskPlanDefinition());
        self::assertSame(self::seedWithAWidgetGate(), $reshapingPurpose->seedTaskPlanItems, 'the configured seed stays as it is');

        $emptiedPurpose = self::instantiateWithoutConstructor(PurposeDroppingTheWidgetGatesOfItsSeed::class);
        $emptiedPurpose->seedTaskPlanItems = self::seedWithAWidgetGate();
        $emptiedPurpose->dropsEveryItem = true;
        self::assertFalse($emptiedPurpose->seedsTaskPlan(), 'a configured seed whose built seed is empty seeds no plan');
    }

    /** @return array<string, array{0: string}> */
    public static function provideThePackagesOwnPurposes(): array
    {
        return [
            AIConversationPurpose::BACKGROUND_MECHANICAL_OPERATION => [AIConversationPurpose::BACKGROUND_MECHANICAL_OPERATION],
            AIConversationPurpose::SUPERVISOR_CHECKPOINT => [AIConversationPurpose::SUPERVISOR_CHECKPOINT],
            AIConversationPurpose::SCHEDULE_RUN => [AIConversationPurpose::SCHEDULE_RUN],
        ];
    }

    #[DataProvider('provideThePackagesOwnPurposes')]
    public function testNoPurposeOfThePackageSeedsAPlan(string $purposeName): void
    {
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);

        self::assertInstanceOf(AIConversationPurpose::class, $aIConversationPurpose);
        self::assertSame([], $aIConversationPurpose->seedTaskPlanItems);
        self::assertSame([], $aIConversationPurpose->buildSeedTaskPlanDefinition());
        self::assertFalse($aIConversationPurpose->seedsTaskPlan());
    }
}
