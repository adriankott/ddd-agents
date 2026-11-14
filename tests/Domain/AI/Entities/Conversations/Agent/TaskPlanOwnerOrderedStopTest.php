<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The plan the model reads every call ends its rules on the owner's stop: right after the rule that every item is
 * settled before the final answer, a stop the owner ordered ends the turn and leaves the open items open.
 */
class TaskPlanOwnerOrderedStopTest extends UnitTestCase
{
    protected const string OWNER_ORDERED_STOP_SENTENCE = 'A stop the owner ordered comes first: when the owner says stop, wait or not now, end the turn there; '
        . 'the open items stay open and are not worked off in this turn.';

    public function testTheTickRulesEndWithTheOwnersStopRightAfterTheRuleToSettleEveryItem(): void
    {
        self::assertStringEndsWith(
            'Before your final answer every item is done, blocked, not_applicable or waiting for the answer your text asks for, and the '
                . 'answer reports each outcome to the owner (statusNotes are internal bookkeeping the owner never sees). '
                . self::OWNER_ORDERED_STOP_SENTENCE,
            TaskPlan::buildTickRules()
        );
    }

    public function testThePlanHeaderAndTheTickToolCarryTheSentence(): void
    {
        $item = new TaskPlanItem();
        $item->text = 'Publish the opening hours';
        $item->status = TaskPlanItem::STATUS_OPEN;
        $items = new TaskPlanItems();
        $items->add($item);
        $taskPlan = new TaskPlan();
        $taskPlan->items = $items;

        $planHeader = explode("\n", $taskPlan->toTailHintText())[0];
        self::assertStringStartsWith('Your task plan (the keys below address the items). Tick an item via set_task_status', $planHeader);
        self::assertStringEndsWith(self::OWNER_ORDERED_STOP_SENTENCE, $planHeader);

        $setTaskStatusDescription = (string)(SetTaskStatusTool::create()->toOpenAiToolSchema()['function']['description'] ?? '');
        self::assertStringContainsString(self::OWNER_ORDERED_STOP_SENTENCE, $setTaskStatusDescription);
    }
}
