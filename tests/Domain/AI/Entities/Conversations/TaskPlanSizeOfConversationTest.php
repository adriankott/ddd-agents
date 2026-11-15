<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanSize;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The size of the plans one conversation carried, fed from its messages in memory: the largest plan in force, the
 * deepest level, and the tail render of every model call (a row with a request snapshot) that ran with a plan.
 */
class TaskPlanSizeOfConversationTest extends UnitTestCase
{
    protected const int SMALL_PLAN_NODES = 7;
    protected const int LARGE_PLAN_NODES = 15;
    protected const int LARGE_PLAN_DEPTH = 3;

    public function testTheSizeOfThePlansTheConversationCarried(): void
    {
        $smallPlan = $this->smallPlan();
        $largePlan = $this->largePlan();
        $conversation = $this->conversation([
            $this->ownerMessage(1),
            $this->assistantMessage(2, modelCall: true, taskPlanSnapshot: $smallPlan),
            $this->assistantMessage(3, modelCall: true),
            $this->ownerMessage(4),
            $this->assistantMessage(5, modelCall: true, taskPlanSnapshot: $largePlan),
            $this->assistantMessage(6, modelCall: true),
        ]);

        $taskPlanSize = TaskPlanSize::fromMessagesOfConversation($conversation);

        self::assertNotNull($taskPlanSize);
        self::assertSame(self::SMALL_PLAN_NODES, $smallPlan->items->countAllItems());
        self::assertSame([
            'nodes' => self::LARGE_PLAN_NODES,
            'maxDepth' => self::LARGE_PLAN_DEPTH,
            'tailTokensMax' => TaskPlanSize::estimateTailTokens($largePlan),
            'tailTokensSum' => 2 * TaskPlanSize::estimateTailTokens($smallPlan) + TaskPlanSize::estimateTailTokens($largePlan),
        ], $taskPlanSize->toReportFields());
    }

    public function testAConversationWithoutAPlanOrWithoutMessagesCarriesNone(): void
    {
        self::assertNull(TaskPlanSize::fromMessagesOfConversation($this->conversation([$this->ownerMessage(1), $this->assistantMessage(2, modelCall: true)])));
        self::assertNull(TaskPlanSize::fromMessagesOfConversation(AIConversation::newInstance()));
    }

    /** Seven nodes over two levels. */
    protected function smallPlan(): TaskPlan
    {
        return $this->plan([
            ['text' => 'Collect the open questions', 'status' => 'open'],
            ['text' => 'Draft the answers', 'status' => 'open', 'subtasks' => [['text' => 'The short ones', 'status' => 'open'], ['text' => 'The long ones', 'status' => 'open']]],
            ['text' => 'Send the answers', 'status' => 'open', 'subtasks' => [['text' => 'By mail', 'status' => 'open'], ['text' => 'By message', 'status' => 'open']]],
        ]);
    }

    /** Fifteen nodes over three levels. */
    protected function largePlan(): TaskPlan
    {
        $leaves = [];
        for ($leafNumber = 1; $leafNumber <= 12; $leafNumber++) {
            $leaves[] = ['text' => 'Answer question ' . $leafNumber, 'status' => 'open'];
        }
        return $this->plan([
            ['text' => 'Answer every open question', 'status' => 'open', 'subtasks' => [['text' => 'The urgent ones first', 'status' => 'open', 'subtasks' => $leaves]]],
            ['text' => 'Report to the owner', 'status' => 'open'],
        ]);
    }

    /** @param array<int, array<string, mixed>> $items */
    protected function plan(array $items): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->mapFromRepository(['items' => $items]);
        $taskPlan->markUpdatedInTurn(0);
        return $taskPlan;
    }

    /** @param AIConversationMessage[] $messageList */
    protected function conversation(array $messageList): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = new AIConversationMessages();
        foreach ($messageList as $message) {
            $conversation->messages->add($message);
        }
        return $conversation;
    }

    protected function ownerMessage(int $index): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'Please help';
        return $message;
    }

    protected function assistantMessage(int $index, bool $modelCall, ?TaskPlan $taskPlanSnapshot = null): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_AGENT;
        $message->messageType = $taskPlanSnapshot !== null ? AIConversationMessage::MESSAGE_TOOL_CALL : AIConversationMessage::MESSAGE_TEXT;
        $message->message = '';
        if ($modelCall) {
            $message->requestSnapshot = new RequestSnapshot();
        }
        if ($taskPlanSnapshot !== null) {
            $message->taskPlanSnapshot = $taskPlanSnapshot;
        }
        return $message;
    }
}
