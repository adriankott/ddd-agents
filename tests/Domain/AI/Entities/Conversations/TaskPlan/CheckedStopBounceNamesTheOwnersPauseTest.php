<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentCheckedStopPolicy;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The checked-stop bounce names the way out for an owner who asked to stop after a part and check back: report that
 * part and end with the one question, the later items stay open. It also names the stop the owner ordered outright
 * (stop, wait, not now): no item is worked, the answer is repeated and names what stays open. Both sentences are in the
 * text with and without the sub-agent prefix.
 */
class CheckedStopBounceNamesTheOwnersPauseTest extends UnitTestCase
{
    protected const string PAUSE_SENTENCE = 'When the owner asked you to stop after a part and check back with them: report that part and end with that ONE question — the items after it stay open.';

    protected const string OWNER_ORDERED_STOP_SENTENCE = 'When the owner said stop, wait or not now: work no item — your final answer repeats what you said and names the items that stay open.';

    protected function buildTaskPlanWithOpenItems(): TaskPlan
    {
        return TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Do part 1', 'key' => '1', 'status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'done'],
            ['text' => 'Do part 2', 'key' => '2', 'status' => TaskPlanItem::STATUS_OPEN],
        ], 'highestItemKeyNumber' => 2]);
    }

    public function testTheBounceNamesTheOwnersPause(): void
    {
        $bounceText = AgentCheckedStopPolicy::buildCheckedStopBounceText($this->buildTaskPlanWithOpenItems());

        self::assertStringContainsString(self::PAUSE_SENTENCE . ' ' . self::OWNER_ORDERED_STOP_SENTENCE, $bounceText);
        self::assertStringEndsWith(self::OWNER_ORDERED_STOP_SENTENCE . ' Otherwise give your final answer. The owner never saw the discarded answer: write its content again in full — '
            . 'the results themselves (the list, the figures, the drafts), not a line saying that they exist — and report the '
            . 'result of every item; this answer is accepted as it is.', $bounceText);
    }

    public function testTheBounceOfASubAgentFoldNamesTheOwnersPauseToo(): void
    {
        $bounceText = AgentCheckedStopPolicy::buildCheckedStopBounceText($this->buildTaskPlanWithOpenItems(), true);

        self::assertStringStartsWith('Your sub-agents\' results are above', $bounceText);
        self::assertStringContainsString(self::PAUSE_SENTENCE . ' ' . self::OWNER_ORDERED_STOP_SENTENCE, $bounceText);
    }
}
