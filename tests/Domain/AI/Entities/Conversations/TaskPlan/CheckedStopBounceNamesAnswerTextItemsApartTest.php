<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentCheckedStopPolicy;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The checked-stop bounce never tells the model to tick an item whose result is text in the answer (`inAnswer`): such
 * an item is named in its own group — write that text now, the platform closes it, never tick it — and is no longer
 * listed under "Items you tick". The closing sentence then says the answer is accepted and such an item stays open when
 * its text is missing.
 */
class CheckedStopBounceNamesAnswerTextItemsApartTest extends UnitTestCase
{
    public function testAnAnswerTextItemIsNamedInItsOwnGroupAndNotAmongTheItemsTheModelTicks(): void
    {
        $taskPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Read the posts', 'key' => '1', 'status' => TaskPlanItem::STATUS_OPEN],
            ['text' => 'Draft the post', 'key' => '2', 'status' => TaskPlanItem::STATUS_OPEN],
        ], 'highestItemKeyNumber' => 2]);
        self::assertTrue($taskPlan->items->getElements()[1]->markResultIsTextInTheAnswer());

        $bounceText = AgentCheckedStopPolicy::buildCheckedStopBounceText($taskPlan);

        self::assertStringContainsString(" Items you tick: 1 'Read the posts'. For each, use set_task_status", $bounceText);
        self::assertStringContainsString(
            " Items whose result is text in your answer: 2 'Draft the post'. Write that text in the answer you give now: an answer that ends without a question to the owner closes these items itself — do not tick them; an answer that ends with askOwner closes them only when you tick each one done in the same turn.",
            $bounceText
        );
        self::assertStringEndsWith(
            ' and report the result of every item. This answer is accepted as it is: an item whose result is text in your answer closes only when that text stands in it — when it is missing, the item stays open and the owner has not received it.',
            $bounceText
        );
        self::assertSame(["1 'Read the posts'", "2 'Draft the post'"], $taskPlan->items->getOwedOpenItemTitleLines(false), 'the default list still names both');
        self::assertSame(["1 'Read the posts'"], $taskPlan->items->getOwedOpenItemTitleLines(false, false));
        self::assertSame(["2 'Draft the post'"], $taskPlan->items->getOwedOpenAnswerTextItemTitleLines());
    }

    public function testWithoutAnAnswerTextItemTheBounceHasNoSuchGroup(): void
    {
        $taskPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Read the posts', 'key' => '1', 'status' => TaskPlanItem::STATUS_OPEN],
        ], 'highestItemKeyNumber' => 1]);

        $bounceText = AgentCheckedStopPolicy::buildCheckedStopBounceText($taskPlan);

        self::assertStringNotContainsString('Items whose result is text in your answer', $bounceText);
        self::assertStringEndsWith(' and report the result of every item; this answer is accepted as it is.', $bounceText);
    }
}
