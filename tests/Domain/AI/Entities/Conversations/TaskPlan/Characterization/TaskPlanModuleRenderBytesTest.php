<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanHolidayPlanFixture as Holiday;

/**
 * The render bytes the model reads of a plan of the module's own items ({@see TaskPlan::toTailHintText()}, the "Your
 * task plan" tail) under the package's default wording: the plain plan P0, its state S1 (one header for a seeded and a
 * model-made plan), the gated state S1 and a plan in its middle whose done items collapse into one summary line of keys
 * with ranges. An application's seeded plan and its wording are the application's to pin.
 */
class TaskPlanModuleRenderBytesTest extends TaskPlanModuleItemTestCase
{
    /** The one header of every plan: the tick rule set ({@see TaskPlan::buildTickRules()}) in the package's default words. */
    protected const string DEFAULT_HEADER = 'Your task plan (the keys below address the items). Tick an item via set_task_status (its key, a statusNote) '
        . 'once its result is in your context — together with the call for the next item or with your answer text, never '
        . 'before its call has returned and never as a step of its own; done: what you produced — an item whose read or '
        . 'call returned nothing usable is not done, it is blocked; blocked: the obstacle (requiresUserAction when only '
        . 'the owner can lift it; once it is lifted, work the item and tick it done); not_applicable: why it does not '
        . 'apply to this workspace. An item that waits for the owner\'s answer stays open and your answer asks for it — '
        . 'never done before the answer is in your context, never blocked in place of asking. An item the platform closes '
        . 'from the record (a completed call it names, a widget, a delivered answer, the owner\'s recorded statement) '
        . 'closes itself when done — its line says so; never tick it done, and mark it blocked or not_applicable only for '
        . 'an obstacle or a reason its record cannot show; never tick an item listed under ✓ done again. An item whose '
        . 'result is text in your answer (a draft) has no call: mark it "inAnswer": true when you create it and the '
        . 'platform closes it from the answer that carries that text, and that answer comes after the items before it are '
        . 'done — an answer that ends on your question to the owner (askOwner) closes none unless you tick it done in '
        . 'that turn (its text is in that answer); never tick it otherwise — except when an earlier answer of yours '
        . 'already gave the owner that text: then tick it done and do not write it again. A question to the owner is '
        . 'never such an item: the item it gates stays open and your answer asks. Before your final answer every item is '
        . 'done, blocked, not_applicable or waiting for the answer your text asks for, and the answer reports each '
        . 'outcome to the owner (statusNotes are internal bookkeeping the owner never sees). A stop the owner ordered '
        . 'comes first: when the owner says stop, wait or not now, end the turn there; the open items stay open and are '
        . 'not worked off in this turn.'
        . "\n";

    protected const string S1_LINES = "✓ done: 1, 2.1\n"
        . "[ ] 2 Answer the newest reviews\n"
        . "  [ ] 2.2 Draft and post the replies ← next\n"
        . "[ ] 3 Write the holiday post\n"
        . "  [ ] 3.1 Draft the post\n"
        . "  [ ] 3.2 Publish it after the owner's approval";

    public function testThePlainPlanP0RendersEveryItemOpenWithTheNextMarkerOnItemOne(): void
    {
        self::assertSame(
            self::DEFAULT_HEADER
                . "[ ] 1 Set the holiday hours (24–26 Dec closed) ← next\n"
                . "[ ] 2 Answer the newest reviews\n"
                . "  [ ] 2.1 Read the newest reviews\n"
                . "  [ ] 2.2 Draft and post the replies\n"
                . "[ ] 3 Write the holiday post\n"
                . "  [ ] 3.1 Draft the post\n"
                . "  [ ] 3.2 Publish it after the owner's approval",
            Holiday::p0()->toTailHintText()
        );
    }

    public function testTheStateS1RendersTheDoneSummaryAndTheNextMarkerOnTheFirstOpenLeafForASeededPlanToo(): void
    {
        self::assertSame(self::DEFAULT_HEADER . self::S1_LINES, Holiday::s1()->toTailHintText());
        $seededS1 = Holiday::s1();
        foreach ($seededS1->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItemAddress->item->seededByPurpose = true;
        }
        self::assertSame(self::DEFAULT_HEADER . self::S1_LINES, $seededS1->toTailHintText(), 'a seeded plan reads the same header');
    }

    public function testTheGatedStateS1MarksTheItemsTheRecordCloses(): void
    {
        self::assertSame(
            self::DEFAULT_HEADER
                . "✓ done: 1, 2.1\n"
                . "[ ] 2 Answer the newest reviews\n"
                . "  [ ] 2.2 Draft and post the replies (closes itself when done — do its work, never tick it done) ← next\n"
                . "[ ] 3 Write the holiday post\n"
                . "  [ ] 3.1 Draft the post (closes itself when done)\n"
                . "  [ ] 3.2 Publish it after the owner's approval (closes itself when done)",
            Holiday::s1Gated()->toTailHintText()
        );
    }

    public function testAnEmptyPlanRendersNothing(): void
    {
        $taskPlan = new TaskPlan();
        self::assertSame('', $taskPlan->toTailHintText());
        $taskPlan->items = new TaskPlanItems();
        self::assertSame('', $taskPlan->toTailHintText());
    }

    public function testTheNextLineCarriesTheFullTextAndEveryOtherLineTheTitle(): void
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Step 1 — read the entry and compare it with the website', 'ownerFacingTitle' => 'Your entry'],
            ['text' => 'Step 2 — sort the notes from the folder read', 'ownerFacingTitle' => 'Your notes'],
        ]);

        self::assertSame(
            ['[ ] 1 Step 1 — read the entry and compare it with the website ← next', '[ ] 2 Your notes'],
            array_slice(explode("\n", $taskPlan->toTailHintText()), 1)
        );
    }

    /** A plan in its middle: three closed steps (two containers), a leaf gated by its call next, a container and two leaves open. */
    protected function midPlan(): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Step one', 'subtasks' => ['Read the entry', 'Confirm the entry']],
            ['text' => 'Pick the language'],
            ['text' => 'Profile round', 'subtasks' => ['Name', 'Description', 'Categories', 'Contact', 'Hours', 'Links', 'Photos']],
            ['text' => 'Sort the notes', 'doneRequiresToolCall' => 'saveNotes'],
            ['text' => 'Rival round', 'subtasks' => [['text' => 'Rivals', 'doneRequiresToolCall' => 'addRivals'], ['text' => 'Key rivals', 'doneRequiresWidget' => 'ChoiceWidget']]],
            ['text' => 'Your goals', 'doneRequiresToolCall' => 'recordGoal'],
            ['text' => 'Wrap-up and handover', 'doneRequiresWidget' => 'HandoverWidget'],
        ]);
        foreach (['1.1', '1.2', '2', '3.1', '3.2', '3.3', '3.4', '3.5', '3.7'] as $doneKey) {
            $taskPlan->items->getItemByKey($doneKey)->status = TaskPlanItem::STATUS_DONE;
        }
        $linksItem = $taskPlan->items->getItemByKey('3.6');
        $linksItem->status = TaskPlanItem::STATUS_BLOCKED;
        $linksItem->statusNote = 'no profiles found and the owner has none yet';
        return $taskPlan;
    }

    public function testAMidPlanCollapsesItsDoneItemsIntoOneSummaryLineOfRanges(): void
    {
        $midPlan = $this->midPlan();

        $renderLines = explode("\n", $midPlan->toTailHintText());
        self::assertSame([
            '✓ done: 1–3 (3.6 blocked)',
            '[ ] 4 Sort the notes (closes itself when done — do its work, never tick it done) ← next',
            '[ ] 5 Rival round',
            '  [ ] 5.1 Rivals (closes itself when done)',
            '  [ ] 5.2 Key rivals (closes itself when done)',
            '[ ] 6 Your goals (closes itself when done)',
            '[ ] 7 Wrap-up and handover (closes itself when done)',
        ], array_slice($renderLines, 1));
        self::assertSame($midPlan->toTailHintText(), $midPlan->toTailHintText(), 'byte-stable');

        $midPlan->items->getItemByKey('3.6')->status = TaskPlanItem::STATUS_OPEN;
        $midPlan->items->getItemByKey('3.6')->statusNote = null;
        self::assertSame('✓ done: 1, 2, 3.1–3.5, 3.7', explode("\n", $midPlan->toTailHintText())[1], 'an open container keeps its line; a run of two stays a list');
    }
}
