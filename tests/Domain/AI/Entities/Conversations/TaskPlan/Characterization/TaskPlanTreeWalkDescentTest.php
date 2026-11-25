<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItemStatusCounts;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanLeafClosings;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteriaWithFixedVerdicts;

/**
 * The descent rules of every walk over the plan tree, each walk run over the SAME six fixture trees:
 *
 *  - `openLeafUnderBlockedContainer`        1 Container [blocked]          > 1.1 Probe leaf [open]
 *  - `openLeafUnderNotApplicableContainer`  1 Container [not_applicable]   > 1.1 Probe leaf [open]
 *  - `openLeafUnderDoneContainer`           1 Container [done]             > 1.1 Probe leaf [open]
 *  - `awaitingOffer`                        1 Awaiting offer [open, its owner-answered widget rendered @3]
 *  - `emptySubtaskLevel`                    1 Probe leaf [open] with `subtasks` = an EMPTY set (not null)
 *  - `criterionOnContainer`                 1 Container [open, doneCriterion] > 1.1 Probe leaf [open]
 *
 * A walk's own fields go onto the probe leaf (and onto the awaiting offer before its offer fields, so it stays an
 * awaiting offer), the container fields onto the container of the four container fixtures. Every count, walk over open
 * or owed work and the `← next` marker prune a blocked or not-applicable container's subtree, exactly as
 * {@see TaskPlanItems::hasOpenItems()} does; some walks skip what is not owed; the renders, the lookups and the walks
 * over stored fields judge every node. A change of one walk's rule shows
 * as the fixtures whose row changes. A last case runs the order-dependent walks over one tree that holds every kind
 * of container side by side.
 */
class TaskPlanTreeWalkDescentTest extends TaskPlanModuleItemTestCase
{
    protected const string BLOCKED = 'openLeafUnderBlockedContainer';
    protected const string NOT_APPLICABLE = 'openLeafUnderNotApplicableContainer';
    protected const string DONE = 'openLeafUnderDoneContainer';
    protected const string AWAITING = 'awaitingOffer';
    protected const string EMPTY_LEVEL = 'emptySubtaskLevel';
    protected const string CRITERION_CONTAINER = 'criterionOnContainer';

    protected const array FIXTURE_NAMES = [
        self::BLOCKED,
        self::NOT_APPLICABLE,
        self::DONE,
        self::AWAITING,
        self::EMPTY_LEVEL,
        self::CRITERION_CONTAINER,
    ];

    // --- fixtures -----------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $leafFields
     * @param array<string, mixed> $containerFields
     */
    protected function buildFixture(string $fixtureName, array $leafFields = [], array $containerFields = []): TaskPlanItems
    {
        if ($fixtureName === self::AWAITING) {
            $awaitingOffer = $this->itemWithFields('Awaiting offer', $leafFields);
            $awaitingOffer->status = TaskPlanItem::STATUS_OPEN;
            $awaitingOffer->doneRequiresWidgetAnswered = true;
            $awaitingOffer->doneRequiresWidget = 'OfferWidget';
            $awaitingOffer->offerRenderedAtMessageIndex = 3;
            return $this->itemSet([$awaitingOffer]);
        }
        $probeLeaf = $this->itemWithFields('Probe leaf', $leafFields);
        if ($fixtureName === self::EMPTY_LEVEL) {
            $probeLeaf->subtasks = new TaskPlanItems();
            return $this->itemSet([$probeLeaf]);
        }
        $container = $this->itemWithFields('Container', $containerFields);
        $container->subtasks = $this->itemSet([$probeLeaf]);
        if ($fixtureName === self::BLOCKED) {
            $container->status = TaskPlanItem::STATUS_BLOCKED;
            $container->statusNote = 'blocked by model';
        } elseif ($fixtureName === self::NOT_APPLICABLE) {
            $container->status = TaskPlanItem::STATUS_NOT_APPLICABLE;
            $container->statusNote = 'not applicable';
        } elseif ($fixtureName === self::DONE) {
            $container->status = TaskPlanItem::STATUS_DONE;
            $container->statusNote = 'container done';
        } else {
            $container->doneCriterion = $container->doneCriterion ?? 'container.criterion';
        }
        return $this->itemSet([$container]);
    }

    /** The probe item of a fixture: the leaf under the container, else the single top item. */
    protected function probeItemOf(TaskPlanItems $fixture): TaskPlanItem
    {
        $topItem = $fixture->getElements()[0];
        if ($topItem->subtasks !== null && $topItem->subtasks->count() > 0) {
            return $topItem->subtasks->getElements()[0];
        }
        return $topItem;
    }

    /** The owner's hello @0, a HandoverWidget @2, an OfferWidget @3, a completed `recordGoal` @9. */
    protected function evidenceMessages(): AIConversationMessages
    {
        return $this->messages([
            $this->ownerText(0, 'Hello'),
            $this->assistantText(2, "Here it is:\n" . $this->widgetFence('HandoverWidget')),
            $this->assistantText(3, "Connect here:\n" . $this->widgetFence('OfferWidget')),
            $this->completedCall(9, 'recordGoal'),
        ]);
    }

    /**
     * Runs `$walkMethod` on a freshly built copy of every fixture and returns the described output per fixture.
     *
     * @param array<int, mixed> $walkArguments
     * @param array<string, mixed> $leafFields
     * @param array<string, mixed> $containerFields
     * @return array<string, mixed>
     */
    protected function runWalkOverFixtures(string $walkMethod, array $walkArguments = [], array $leafFields = [], array $containerFields = []): array
    {
        $outputsByFixture = [];
        foreach (self::FIXTURE_NAMES as $fixtureName) {
            $fixture = $this->buildFixture($fixtureName, $leafFields, $containerFields);
            $outputsByFixture[$fixtureName] = $this->describe($fixture->{$walkMethod}(...$walkArguments));
        }
        return $outputsByFixture;
    }

    /** A walk's output as a comparable value: an item becomes its text, a list of items their texts, closings their status by key. */
    protected function describe(mixed $walkOutput): mixed
    {
        if ($walkOutput instanceof TaskPlanLeafClosings) {
            return $walkOutput->getStatusByNumberPath();
        }
        if ($walkOutput instanceof TaskPlanItem) {
            return $walkOutput->text;
        }
        if ($walkOutput instanceof TaskPlanItemStatusCounts) {
            return ['done' => $walkOutput->done, 'open' => $walkOutput->open, 'blocked' => $walkOutput->blocked];
        }
        if (is_array($walkOutput) && $walkOutput !== [] && array_is_list($walkOutput) && $walkOutput[0] instanceof TaskPlanItem) {
            return array_map(static fn (TaskPlanItem $taskPlanItem): string => $taskPlanItem->text, $walkOutput);
        }
        return $walkOutput;
    }

    /** @return array<string, mixed> */
    protected static function expectedPerFixture(mixed $blocked, mixed $notApplicable, mixed $done, mixed $awaiting, mixed $emptyLevel, mixed $criterionContainer): array
    {
        return [
            self::BLOCKED => $blocked,
            self::NOT_APPLICABLE => $notApplicable,
            self::DONE => $done,
            self::AWAITING => $awaiting,
            self::EMPTY_LEVEL => $emptyLevel,
            self::CRITERION_CONTAINER => $criterionContainer,
        ];
    }

    // --- the reference predicate --------------------------------------------------------------------------------------

    public function testHasOpenItemsPrunesBlockedAndNotApplicableContainersButNotAnAwaitingLeaf(): void
    {
        self::assertSame(self::expectedPerFixture(false, false, true, true, true, true), $this->runWalkOverFixtures('hasOpenItems'));
    }

    // --- open and owed walks: an open leaf UNDER a blocked / n/a container is pruned, as hasOpenItems() prunes it --------

    public function testHasOwedOpenItemsPrunesBlockedAndNotApplicableContainers(): void
    {
        self::assertSame(self::expectedPerFixture(false, false, true, false, true, true), $this->runWalkOverFixtures('hasOwedOpenItems'));
    }

    public function testGetLeafByDoneCriterionDescendsEverywhereAndNeverMatchesAContainer(): void
    {
        self::assertSame(
            self::expectedPerFixture('Probe leaf', 'Probe leaf', 'Probe leaf', 'Awaiting offer', 'Probe leaf', 'Probe leaf'),
            $this->runWalkOverFixtures('getLeafByDoneCriterion', ['probe.criterion'], ['doneCriterion' => 'probe.criterion'])
        );
        self::assertNull($this->buildFixture(self::CRITERION_CONTAINER)->getLeafByDoneCriterion('container.criterion'), 'a container criterion is never found');
    }

    public function testStampRenderedNonBlockingOffersStampsAnOfferLeafUnderABlockedOrNotApplicableContainer(): void
    {
        $offerFields = ['doneRequiresWidgetAnswered' => true, 'doneRequiresWidget' => 'OfferWidget'];
        self::assertSame(
            self::expectedPerFixture(1, 1, 1, 0, 1, 1),
            $this->runWalkOverFixtures('stampRenderedNonBlockingOffers', [$this->evidenceMessages()], $offerFields)
        );
        $blockedFixture = $this->buildFixture(self::BLOCKED, $offerFields);
        $blockedFixture->stampRenderedNonBlockingOffers($this->evidenceMessages());
        self::assertSame(3, $this->probeItemOf($blockedFixture)->offerRenderedAtMessageIndex);
    }

    public function testGetItemNumberPathsTickableAfterToolCallPrunesBlockedAndNotApplicableContainers(): void
    {
        self::assertSame(
            self::expectedPerFixture([], [], ['1.1'], [], ['1'], ['1.1']),
            $this->runWalkOverFixtures('getItemNumberPathsTickableAfterToolCall', ['recordGoal', TaskPlanEvidence::forTurn($this->evidenceMessages(), criteriaDecideTicks: false)], ['doneRequiresToolCall' => 'recordGoal'])
        );
    }

    public function testCloseLeavesProvenByCompletedCallNeverClosesALeafUnderABlockedOrNotApplicableContainer(): void
    {
        self::assertSame(
            self::expectedPerFixture([], [], ['1.1' => 'done'], [], ['1' => 'done'], ['1.1' => 'done']),
            $this->runWalkOverFixtures('closeLeavesProvenByCompletedCall', ['recordGoal', 9, TaskPlanEvidence::forTurn($this->evidenceMessages(), criteriaDecideTicks: false)], ['doneRequiresToolCall' => 'recordGoal'])
        );
    }

    public function testCloseLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEndNeverClosesALeafUnderABlockedOrNotApplicableContainer(): void
    {
        self::assertSame(
            self::expectedPerFixture([], [], ['1.1' => 'done'], [], ['1' => 'done'], ['1.1' => 'done']),
            $this->runWalkOverFixtures('closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd', [TaskPlanEvidence::forTurn($this->evidenceMessages(), 0, criteriaDecideTicks: false)], ['doneRequiresWidget' => 'HandoverWidget'])
        );
    }

    public function testGetOwedOpenLeafTextsByNumberPathNeverNamesALeafUnderABlockedOrNotApplicableContainer(): void
    {
        self::assertSame(
            self::expectedPerFixture([], [], ['1.1' => 'Probe leaf'], [], ['1' => 'Probe leaf'], ['1.1' => 'Probe leaf']),
            $this->runWalkOverFixtures('getOwedOpenLeafTextsByNumberPath', [5])
        );
    }

    public function testCollectOwedOpenLeafSkillNamesByNumberPathInheritsTheContainerSkillAndPrunesABlockedContainer(): void
    {
        self::assertSame(
            self::expectedPerFixture([], [], ['1.1' => ['Skill.Act']], [], ['1' => []], ['1.1' => ['Skill.Act']]),
            $this->runWalkOverFixtures('collectOwedOpenLeafSkillNamesByNumberPath', [], [], ['skillNames' => ['Skill.Act']])
        );
    }

    public function testTheSkillLookaheadWalksInheritTheContainerSkillAndPruneABlockedContainer(): void
    {
        $expected = self::expectedPerFixture([], [], ['Skill.Act'], [], [], ['Skill.Act']);
        self::assertSame($expected, $this->runWalkOverFixtures('collectSkillNamesOfNextOwedOpenItems', [3], [], ['skillNames' => ['Skill.Act']]));
        self::assertSame($expected, $this->runWalkOverFixtures('collectSkillNamesDueNow', [3, []], [], ['skillNames' => ['Skill.Act']]));
    }

    // --- prune-open walks: a node that is not isOpen() is skipped with its subtree --------------------------------------

    public function testGetFirstOpenDoneGateLeafPrunesBlockedContainersButNotTheAwaitingOffer(): void
    {
        $toolGate = ['doneRequiresToolCall' => 'recordGoal'];
        self::assertSame(
            self::expectedPerFixture(null, null, 'Probe leaf', 'Awaiting offer', 'Probe leaf', 'Probe leaf'),
            $this->runWalkOverFixtures('getFirstOpenDoneGateLeaf', [], $toolGate)
        );
        self::assertSame(
            self::expectedPerFixture(null, null, 'recordGoal', 'recordGoal', 'recordGoal', 'recordGoal'),
            $this->runWalkOverFixtures('getFirstOpenDoneGateToolName', [], $toolGate)
        );
        self::assertSame(
            self::expectedPerFixture(null, null, 'recordGoal', 'recordGoal', 'recordGoal', 'recordGoal'),
            $this->runWalkOverFixtures('getNextOwedDoneGateToolName', [$this->messages([])], $toolGate)
        );
    }

    public function testGetOpenItemNumberPathsPrunesBlockedContainersAndListsAwaitingLeaves(): void
    {
        self::assertSame(self::expectedPerFixture([], [], ['1.1'], ['1'], ['1'], ['1.1']), $this->runWalkOverFixtures('getOpenItemNumberPaths'));
    }

    // --- prune-owed walks: a node that is not owed is skipped -----------------------------------------------------------

    public function testGetOpenItemPathTextsPrunesNodesThatAreNotOwed(): void
    {
        self::assertSame(
            self::expectedPerFixture([], [], ['Container > Probe leaf'], [], ['Probe leaf'], ['Container > Probe leaf']),
            $this->runWalkOverFixtures('getOpenItemPathTexts')
        );
    }

    public function testGetMinReasoningEffortOfFirstOpenItemTakesTheMaximumAlongTheOwedChain(): void
    {
        self::assertSame(
            self::expectedPerFixture(null, null, 'high', null, 'high', 'high'),
            $this->runWalkOverFixtures('getMinReasoningEffortOfFirstOpenItem', [], ['minReasoningEffort' => 'high'], ['minReasoningEffort' => 'medium'])
        );
        self::assertSame(
            self::expectedPerFixture(null, null, 'medium', null, null, 'medium'),
            $this->runWalkOverFixtures('getMinReasoningEffortOfFirstOpenItem', [], [], ['minReasoningEffort' => 'medium']),
            'a container value covers its subtasks'
        );
    }

    public function testHasOpenItemsRequiringDoneEvidencePrunesNodesThatAreNotOwedAndJudgesAContainersOwnGate(): void
    {
        self::assertSame(
            self::expectedPerFixture(false, false, true, false, true, true),
            $this->runWalkOverFixtures('hasOpenItemsRequiringDoneEvidence', [], ['doneRequiresToolCall' => 'recordGoal'])
        );
        self::assertSame(
            self::expectedPerFixture(false, false, true, false, false, true),
            $this->runWalkOverFixtures('hasOpenItemsRequiringDoneEvidence', [], [], ['doneRequiresWidget' => 'HandoverWidget']),
            'a gate on the CONTAINER counts'
        );
    }

    // --- node walks: containers are judged on their own fields, every subtree is visited ---------------------------------

    public function testCollectCustomerProgressStepMemberStatesCountsABlockedContainerAndItsLeafAsProgress(): void
    {
        $outputsByFixture = [];
        foreach (self::FIXTURE_NAMES as $fixtureName) {
            $memberStatesByStepKey = [];
            $this->buildFixture($fixtureName, ['customerProgressStepKey' => 'step.one'], ['customerProgressStepKey' => 'step.one'])
                ->collectCustomerProgressStepMemberStates($memberStatesByStepKey);
            $memberCountsByStepKey = [];
            foreach ($memberStatesByStepKey as $stepKey => $memberState) {
                $memberCountsByStepKey[$stepKey] = [
                    'memberCount' => $memberState->memberCount,
                    'openMemberCount' => $memberState->openMemberCount,
                    'progressMemberCount' => $memberState->progressMemberCount,
                ];
            }
            $outputsByFixture[$fixtureName] = $memberCountsByStepKey;
        }
        // the leaf under a blocked / n/a container is covered by its verdict: terminal, so progress
        $twoMembersOneOpenOneProgress = ['step.one' => ['memberCount' => 2, 'openMemberCount' => 0, 'progressMemberCount' => 2]];
        $twoMembersBothOpen = ['step.one' => ['memberCount' => 2, 'openMemberCount' => 2, 'progressMemberCount' => 0]];
        $oneOpenMember = ['step.one' => ['memberCount' => 1, 'openMemberCount' => 1, 'progressMemberCount' => 0]];
        self::assertSame(
            self::expectedPerFixture($twoMembersOneOpenOneProgress, $twoMembersOneOpenOneProgress, $twoMembersBothOpen, $oneOpenMember, $oneOpenMember, $twoMembersBothOpen),
            $outputsByFixture
        );
    }

    public function testGetDoneGateToolNamesReadsContainersFirstThenTheirSubtree(): void
    {
        $containerThenLeaf = ['addRivals', 'updateRivals', 'recordGoal'];
        self::assertSame(
            self::expectedPerFixture($containerThenLeaf, $containerThenLeaf, $containerThenLeaf, ['recordGoal'], ['recordGoal'], $containerThenLeaf),
            $this->runWalkOverFixtures('getDoneGateToolNames', [], ['doneRequiresToolCall' => 'recordGoal'], ['doneRequiresToolCall' => 'addRivals|updateRivals'])
        );
    }

    public function testGetItemsWithStatusReadsTheStoredStatusOfEveryNode(): void
    {
        self::assertSame(
            self::expectedPerFixture(['Probe leaf'], ['Probe leaf'], ['Probe leaf'], ['Awaiting offer'], ['Probe leaf'], ['Container', 'Probe leaf']),
            $this->runWalkOverFixtures('getItemsWithStatus', [TaskPlanItem::STATUS_OPEN])
        );
    }

    public function testHasItemsForProgressStepKeyVisitsEveryNode(): void
    {
        self::assertSame(
            self::expectedPerFixture(true, true, true, true, true, true),
            $this->runWalkOverFixtures('hasItemsForProgressStepKey', ['step.one'], ['customerProgressStepKey' => 'step.one'])
        );
    }

    public function testHasPlatformResolvedItemsForProgressStepKeyReadsALeafWithAnEmptySubtaskLevel(): void
    {
        // one leaf definition (TaskPlanItem::isLeaf()): an empty subtask level is a leaf
        self::assertSame(
            self::expectedPerFixture(true, true, true, true, true, true),
            $this->runWalkOverFixtures('hasPlatformResolvedItemsForProgressStepKey', ['step.one'], ['customerProgressStepKey' => 'step.one', 'platformResolvedAtMessageIndex' => 7])
        );
    }

    public function testHasOwedOpenItemsForProgressStepKeyReadsALeafWithAnEmptySubtaskLevelAndPrunesBlockedContainers(): void
    {
        // an empty subtask level is a leaf; the open leaf under a blocked / n/a container is covered by its verdict
        self::assertSame(
            self::expectedPerFixture(false, false, true, false, true, true),
            $this->runWalkOverFixtures('hasOwedOpenItemsForProgressStepKey', ['step.one'], ['customerProgressStepKey' => 'step.one'])
        );
    }

    public function testTheBlockedItemPredicatesVisitEveryNode(): void
    {
        self::assertSame(
            self::expectedPerFixture(true, false, false, false, false, false),
            $this->runWalkOverFixtures('hasBlockedItemWithReason'),
            'the blocked container itself counts'
        );
        $blockedLeaf = ['status' => TaskPlanItem::STATUS_BLOCKED, 'statusNote' => 'leaf blocked', 'requiresUserAction' => true, 'retryable' => true];
        $everywhereButTheOffer = self::expectedPerFixture(true, true, true, false, true, true);
        self::assertSame($everywhereButTheOffer, $this->runWalkOverFixtures('hasBlockedItemWithReason', [], $blockedLeaf));
        self::assertSame($everywhereButTheOffer, $this->runWalkOverFixtures('hasBlockedItemRequiringUserAction', [], $blockedLeaf));
        self::assertSame($everywhereButTheOffer, $this->runWalkOverFixtures('hasRetryableBlockedItems', [], $blockedLeaf));
    }

    public function testCountAllItemsAndCountLeafItems(): void
    {
        self::assertSame(self::expectedPerFixture(2, 2, 2, 1, 1, 2), $this->runWalkOverFixtures('countAllItems'));
        self::assertSame(self::expectedPerFixture(1, 1, 1, 1, 1, 1), $this->runWalkOverFixtures('countLeafItems'), 'an empty subtask level counts as a leaf');
    }

    public function testCountItemsByStatusPrunesTheSubtreeOfABlockedAndOfANotApplicableContainer(): void
    {
        // `blocked` counts blocked, `not_applicable` counts done, and both prune their subtree, as TaskPlanItem::isOpen() does
        self::assertSame(
            self::expectedPerFixture(
                ['done' => 0, 'open' => 0, 'blocked' => 1],
                ['done' => 1, 'open' => 0, 'blocked' => 0],
                ['done' => 0, 'open' => 2, 'blocked' => 0],
                ['done' => 0, 'open' => 1, 'blocked' => 0],
                ['done' => 0, 'open' => 1, 'blocked' => 0],
                ['done' => 0, 'open' => 2, 'blocked' => 0]
            ),
            $this->runWalkOverFixtures('countItemsByStatus')
        );
    }

    public function testGetDoneLeafTextWithoutStatusNoteDescendsEverywhere(): void
    {
        self::assertSame(
            self::expectedPerFixture('Probe leaf', 'Probe leaf', 'Probe leaf', null, 'Probe leaf', 'Probe leaf'),
            $this->runWalkOverFixtures('getDoneLeafTextWithoutStatusNote', [], ['status' => TaskPlanItem::STATUS_DONE])
        );
    }

    public function testGetNextMarkedItemNeverPutsTheMarkerInsideABlockedOrNotApplicableContainer(): void
    {
        self::assertSame(
            self::expectedPerFixture(null, null, 'Probe leaf', null, 'Probe leaf', 'Probe leaf'),
            $this->runWalkOverFixtures('getNextMarkedItem')
        );
    }

    public function testTheTailHintRenderVisitsEveryNodeMarksNoLeafOfAPrunedSubtreeAndKeepsADoneContainersNoteOffItsOpenLine(): void
    {
        $linesByFixture = [];
        foreach (self::FIXTURE_NAMES as $fixtureName) {
            $linesByFixture[$fixtureName] = $this->buildFixture($fixtureName)->buildTailHintLines();
        }
        self::assertSame(
            self::expectedPerFixture(
                ['[✗ blocked: blocked by model] 1 Container', '  [ ] 1.1 Probe leaf'],
                ['[– n/a: not applicable] 1 Container', '  [ ] 1.1 Probe leaf'],
                ['[ ] 1 Container', '  [ ] 1.1 Probe leaf ← next'],
                ['[⏳ awaiting the owner\'s answer on the widget — END YOUR TURN; nothing to do here] 1 Awaiting offer'],
                ['[ ] 1 Probe leaf ← next'],
                ['[ ] 1 Container', '  [ ] 1.1 Probe leaf ← next']
            ),
            $linesByFixture
        );
    }

    public function testTheJudgeRenderVisitsEveryNode(): void
    {
        $linesByFixture = [];
        foreach (self::FIXTURE_NAMES as $fixtureName) {
            $linesByFixture[$fixtureName] = $this->buildFixture($fixtureName)->buildJudgeLines();
        }
        self::assertSame(
            self::expectedPerFixture(
                ['[✗ blocked: blocked by model] 1 Container', '  [ ] 1.1 Probe leaf'],
                ['[– n/a: not applicable] 1 Container', '  [ ] 1.1 Probe leaf'],
                ['[ ] 1 Container', '  [ ] 1.1 Probe leaf'],
                ['[ ] (awaiting the owner\'s answer, widget rendered @3) 1 Awaiting offer'],
                ['[ ] 1 Probe leaf'],
                ['[ ] 1 Container', '  [ ] 1.1 Probe leaf']
            ),
            $linesByFixture
        );
    }

    public function testApplyPreResolutionsIsPostOrderAndResolvesModelBlockedAndNotApplicableContainers(): void
    {
        $outputsByFixture = [];
        foreach (self::FIXTURE_NAMES as $fixtureName) {
            $doneCriteria = TaskPlanDoneCriteriaWithFixedVerdicts::answeringByKey([
                'leaf.criterion' => TaskPlanDoneCriterionVerdict::done('resolved: leaf.criterion'),
                'container.criterion' => TaskPlanDoneCriterionVerdict::done('resolved: container.criterion'),
            ]);
            $fixture = $this->buildFixture($fixtureName, ['doneCriterion' => 'leaf.criterion'], ['doneCriterion' => 'container.criterion']);
            $resolvedLeafCount = $fixture->applyPreResolutions($doneCriteria, TaskPlanDoneCriteriaWithFixedVerdicts::inputsFor($fixture));
            $outputsByFixture[$fixtureName] = [$resolvedLeafCount, $doneCriteria->askedDoneCriterionKeys, $fixture->getElements()[0]->status];
        }
        self::assertSame(
            self::expectedPerFixture(
                // a container the MODEL blocked or set n/a, with a criterion, is resolved done like a leaf
                [2, ['leaf.criterion', 'container.criterion'], TaskPlanItem::STATUS_DONE],
                [2, ['leaf.criterion', 'container.criterion'], TaskPlanItem::STATUS_DONE],
                [1, ['leaf.criterion'], TaskPlanItem::STATUS_DONE],
                [1, ['leaf.criterion'], TaskPlanItem::STATUS_DONE],
                [1, ['leaf.criterion'], TaskPlanItem::STATUS_DONE],
                // post-order: the leaf's criterion is asked before the container's
                [2, ['leaf.criterion', 'container.criterion'], TaskPlanItem::STATUS_DONE]
            ),
            $outputsByFixture
        );
    }

    // --- lookups and the outcome line -----------------------------------------------------------------------------------

    public function testTheTextAndTitleLookupsVisitEveryNode(): void
    {
        self::assertSame(
            self::expectedPerFixture('Probe leaf', 'Probe leaf', 'Probe leaf', null, 'Probe leaf', 'Probe leaf'),
            $this->runWalkOverFixtures('getSingleItemByText', ['probe LEAF'])
        );
        self::assertSame(
            self::expectedPerFixture('Probe leaf', 'Probe leaf', 'Probe leaf', 'Awaiting offer', 'Probe leaf', 'Probe leaf'),
            $this->runWalkOverFixtures('getSingleItemByOwnerFacingTitle', ['Your business'], ['ownerFacingTitle' => 'Your business'])
        );
    }

    public function testTheOutcomeSummaryListsLeavesAndABlockedOrNotApplicableContainerInPlaceOfItsSubtree(): void
    {
        $summariesByFixture = [];
        foreach (self::FIXTURE_NAMES as $fixtureName) {
            $taskPlan = new TaskPlan();
            $taskPlan->items = $this->buildFixture($fixtureName);
            $summariesByFixture[$fixtureName] = $taskPlan->buildOutcomeSummary();
        }
        // a blocked / n/a container stands for its subtree with its own verdict and note
        self::assertSame(
            self::expectedPerFixture('blocked: Container — blocked by model', 'n/a: Container — not applicable', 'open: Probe leaf', 'open: Awaiting offer', 'open: Probe leaf', 'open: Probe leaf'),
            $summariesByFixture
        );
    }

    // --- what a walk leaves out besides its descent ---------------------------------------------------------------------

    public function testTheOwedLeafTextsLeaveOutALeafOnlyItsCriterionCloses(): void
    {
        $criterionOnlyLeaf = $this->itemWithFields('Criterion only', ['doneCriterion' => 'leaf.criterion']);
        $criterionAndToolLeaf = $this->itemWithFields('Criterion and tool', ['doneCriterion' => 'leaf.criterion', 'doneRequiresToolCall' => 'recordGoal']);
        $criterionAndWidgetLeaf = $this->itemWithFields('Criterion and widget', ['doneCriterion' => 'leaf.criterion', 'doneRequiresWidget' => 'HandoverWidget']);

        self::assertSame(
            ['2' => 'Criterion and tool', '3' => 'Criterion and widget'],
            $this->itemSet([$criterionOnlyLeaf, $criterionAndToolLeaf, $criterionAndWidgetLeaf])->getOwedOpenLeafTextsByNumberPath(5)
        );
    }

    public function testABlockedItemWithoutANoteHasNoReason(): void
    {
        self::assertFalse($this->itemSet([$this->item('Blocked', TaskPlanItem::STATUS_BLOCKED, '  ')])->hasBlockedItemWithReason());
        self::assertFalse($this->itemSet([$this->item('Blocked', TaskPlanItem::STATUS_BLOCKED)])->hasBlockedItemWithReason());
    }

    public function testTheDoneLeafWithoutANoteIsNeverAContainer(): void
    {
        $doneContainerWithoutNote = $this->itemWithFields('Done container', [
            'status' => TaskPlanItem::STATUS_DONE,
            'subtasks' => $this->itemSet([$this->item('Done leaf', TaskPlanItem::STATUS_DONE, 'noted')]),
        ]);

        self::assertNull($this->itemSet([$doneContainerWithoutNote])->getDoneLeafTextWithoutStatusNote());
    }

    public function testAContainerWhoseOpenSubtasksOnlyAwaitTheOwnerClaimsTheNextMarker(): void
    {
        $awaitingOffer = $this->itemWithFields('Awaiting offer', ['doneRequiresWidgetAnswered' => true, 'doneRequiresWidget' => 'OfferWidget', 'offerRenderedAtMessageIndex' => 3]);
        $container = $this->itemWithFields('Container', ['subtasks' => $this->itemSet([$awaitingOffer])]);

        self::assertSame('Container', $this->itemSet([$container])->getNextMarkedItem()?->text);
    }

    // --- one tree with every kind of container side by side: the walks whose answer depends on the order -------------

    /**
     * 1 blocked container > 1.1 open leaf (Skill.A, effort high, seeded)
     * 2 not_applicable container > 2.1 open leaf (toolB, crit.b)
     * 3 done container > 3.1 open leaf (toolC, Skill.C, effort medium), 3.2 done leaf
     * 4 open leaf (widget W)
     */
    protected function mixedTree(): TaskPlanItems
    {
        $leafUnderBlocked = $this->itemWithFields('A1', ['skillNames' => ['Skill.A'], 'minReasoningEffort' => 'high', 'seededByPurpose' => true]);
        $blockedContainer = $this->itemWithFields('A', ['status' => TaskPlanItem::STATUS_BLOCKED, 'statusNote' => 'blocked by the owner', 'subtasks' => $this->itemSet([$leafUnderBlocked])]);
        $leafUnderNotApplicable = $this->itemWithFields('B1', ['doneRequiresToolCall' => 'toolB', 'doneCriterion' => 'crit.b']);
        $notApplicableContainer = $this->itemWithFields('B', ['status' => TaskPlanItem::STATUS_NOT_APPLICABLE, 'statusNote' => 'does not apply', 'subtasks' => $this->itemSet([$leafUnderNotApplicable])]);
        $leafUnderDone = $this->itemWithFields('C1', ['doneRequiresToolCall' => 'toolC', 'skillNames' => ['Skill.C'], 'minReasoningEffort' => 'medium']);
        $doneContainer = $this->itemWithFields('C', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'ticked early', 'subtasks' => $this->itemSet([$leafUnderDone, $this->item('C2', TaskPlanItem::STATUS_DONE, 'ok')])]);
        $widgetLeaf = $this->itemWithFields('D', ['doneRequiresWidget' => 'W']);
        return $this->itemSet([$blockedContainer, $notApplicableContainer, $doneContainer, $widgetLeaf]);
    }

    public function testTheOrderDependentWalksOverATreeWithEveryKindOfContainer(): void
    {
        $tree = $this->mixedTree();

        self::assertSame(['3.1' => 'C1', '4' => 'D'], $tree->getOwedOpenLeafTextsByNumberPath(10));
        self::assertSame(['3.1' => 'C1'], $tree->getOwedOpenLeafTextsByNumberPath(1), 'the limit cuts in document order');
        self::assertSame(['3.1' => ['Skill.C'], '4' => []], $tree->collectOwedOpenLeafSkillNamesByNumberPath());
        self::assertSame(['3.1', '4'], $tree->getOpenItemNumberPaths());
        self::assertSame(['C > C1', 'D'], $tree->getOpenItemPathTexts());
        self::assertSame('C1', $tree->getFirstOpenDoneGateLeaf()?->text);
        self::assertSame('toolC', $tree->getFirstOpenDoneGateToolName());
        self::assertSame('medium', $tree->getMinReasoningEffortOfFirstOpenItem(), 'the high effort under the blocked container is not reached');
        self::assertSame(['toolB', 'toolC'], $tree->getDoneGateToolNames());
        self::assertSame('C1', $tree->getNextMarkedItem()?->text, 'the ← next marker skips the subtrees of the blocked and the n/a container');
        $statusCounts = $tree->countItemsByStatus();
        self::assertSame(['done' => 2, 'open' => 3, 'blocked' => 1], ['done' => $statusCounts->done, 'open' => $statusCounts->open, 'blocked' => $statusCounts->blocked]);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $tree;
        self::assertStringContainsString("\n  [ ] 1.1 A1\n", $taskPlan->toTailHintText(), 'the leaf under the blocked container carries no marker');
        self::assertStringContainsString("\n  [ ] 3.1 C1 (closes itself when done — do its work, never tick it done) ← next\n", $taskPlan->toTailHintText());
    }
}
