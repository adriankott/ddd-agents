<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An `update_task_plan` call that drops items: the result quotes the owner's newest message beside the dropped items,
 * so the model sees whether the owner cancelled them.
 */
class TaskPlanDropWarningDispatchTest extends UnitTestCase
{
    public function testTheDropWarningQuotesTheOwnersNewestMessage(): void
    {
        $conversation = $this->conversation(
            $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Set the holiday hours and answer the reviews.', 0),
            $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'On it.', 1),
            $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, "Forget the reviews,\n just the hours.", 2)
        );
        $conversation->agentWorkingState = new AgentWorkingState();
        $conversation->agentWorkingState->taskPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['key' => '1', 'text' => 'Set the holiday hours', 'status' => 'open'],
            ['key' => '2', 'text' => 'Answer the reviews', 'status' => 'open'],
        ]]);
        $callRow = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', 3);
        $callRow->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_3';
        $toolCall->name = UpdateTaskPlanTool::NAME;
        $toolCall->arguments = ['items' => [['key' => '1'], ['key' => '2', 'remove' => true]]];
        $callRow->toolCall = $toolCall;
        $conversation->messages->add($callRow);

        (new AgentToolCallDispatchService())->dispatchToolCall(
            AgentRun::forTurnEntry(),
            $this->createStub(AgentChildTurnRunnerInterface::class),
            $conversation,
            $toolCall,
            $callRow,
            false,
            null,
            AgentTransientMessageIds::startingAt(0),
            null,
            false
        );

        self::assertStringContainsString(
            "Dropped: 2 'Answer the reviews'. The owner's newest message: «Forget the reviews, just the hours.». A dropped item is gone from the plan",
            (string)$toolCall->result
        );
    }

    protected function row(string $role, string $authorType, string $text, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->indexInConversation = $index;
        return $message;
    }

    protected function conversation(AIConversationMessage ...$rows): AIConversation
    {
        $messages = new AIConversationMessages();
        foreach ($rows as $row) {
            $messages->add($row);
        }
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->messages = $messages;
        return $conversation;
    }
}
