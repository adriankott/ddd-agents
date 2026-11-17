<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanRenderTurnState;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `askOwner` names the plan item whose open question it is (`planItemKey`): the plan render treats that item as waiting
 * for the owner for the rest of the turn — a later answer-text item takes the plain mark instead of "after the items
 * before it" — and the call's result says "item <key> waits for this answer". Without the key nothing changes; a key
 * that names no item neither fails the call nor adds a line.
 */
class AskOwnerPlanItemKeyTest extends UnitTestCase
{
    protected const array QUESTION_ARGUMENTS = ['question' => 'Which channel?', 'options' => ['Email', 'Phone']];

    public function testThePlanItemKeyIsAnOptionalArgumentOfTheSchemaAndTheDescriptionsAskForIt(): void
    {
        $askOwnerFunction = AskOwnerTool::create()->toOpenAiToolSchema()['function'];
        $planItemKeyProperty = $askOwnerFunction['parameters']['properties']['planItemKey'] ?? null;

        self::assertSame('string', $planItemKeyProperty['type'] ?? null);
        self::assertStringContainsString('Name the plan item your question belongs to.', $planItemKeyProperty['description']);
        self::assertNotContains('planItemKey', $askOwnerFunction['parameters']['required']);
        self::assertStringContainsString('Name the plan item your question belongs to.', $askOwnerFunction['description']);
        self::assertStringContainsString('Name the plan item your question belongs to.', SetTaskStatusTool::create()->toOpenAiToolSchema()['function']['description']);
        self::assertStringContainsString('Name the plan item your question belongs to.', UpdateTaskPlanTool::create()->toOpenAiToolSchema()['function']['description']);
    }

    public function testTheKeyIsRecordedOnTheQuestionAndOmittingItRecordsNone(): void
    {
        self::assertSame('2.1', AskOwnerTool::checkArguments(self::QUESTION_ARGUMENTS + ['planItemKey' => ' 2.1 '])->question?->planItemKey);
        self::assertSame('3', AskOwnerTool::checkArguments(self::QUESTION_ARGUMENTS + ['planItemKey' => 3])->question?->planItemKey);
        self::assertNull(AskOwnerTool::checkArguments(self::QUESTION_ARGUMENTS)->question?->planItemKey);
        self::assertNull(AskOwnerTool::checkArguments(self::QUESTION_ARGUMENTS + ['planItemKey' => ''])->question?->planItemKey);
    }

    public function testANamedItemWaitsForTheOwnerAndALaterAnswerTextItemTakesThePlainMark(): void
    {
        $taskPlan = $this->planWithAnOwedLeafBeforeADraftItem();
        $taskPlanRenderTurnState = TaskPlanRenderTurnState::ofTurn($this->askOwnerTurn(['planItemKey' => '1'])->buildCurrentTurn());

        self::assertNotNull($taskPlanRenderTurnState);
        $lines = $taskPlan->items->buildCompactTailHintLines($taskPlanRenderTurnState);
        self::assertStringContainsString(TaskPlanItem::ANSWER_TEXT_MARK, end($lines));
        self::assertStringNotContainsString(TaskPlanItem::ANSWER_TEXT_AFTER_OPEN_ITEMS_MARK, end($lines));

        $agentModelInput = new AgentModelInput();
        $agentModelInput->taskPlan = $taskPlan;
        $agentModelInput->taskPlanRenderTurnState = $taskPlanRenderTurnState;
        self::assertStringNotContainsString(TaskPlanItem::ANSWER_TEXT_AFTER_OPEN_ITEMS_MARK, (string)$agentModelInput->taskPlanTailHint(), 'the model input renders the turn state');
    }

    public function testWithoutTheKeyTheAfterMarkStays(): void
    {
        $taskPlan = $this->planWithAnOwedLeafBeforeADraftItem();
        $taskPlanRenderTurnState = TaskPlanRenderTurnState::ofTurn($this->askOwnerTurn([])->buildCurrentTurn());

        self::assertNull($taskPlanRenderTurnState);
        $lines = $taskPlan->items->buildCompactTailHintLines($taskPlanRenderTurnState);
        self::assertStringEndsWith(TaskPlanItem::ANSWER_TEXT_AFTER_OPEN_ITEMS_MARK, end($lines));
        self::assertSame($taskPlan->items->buildCompactTailHintLines(), $lines, 'unchanged against the render without turn state');
    }

    public function testAnUnknownKeyKeepsTheAfterMark(): void
    {
        $taskPlan = $this->planWithAnOwedLeafBeforeADraftItem();
        $lines = $taskPlan->items->buildCompactTailHintLines(TaskPlanRenderTurnState::ofTurn($this->askOwnerTurn(['planItemKey' => '9'])->buildCurrentTurn()));

        self::assertStringEndsWith(TaskPlanItem::ANSWER_TEXT_AFTER_OPEN_ITEMS_MARK, end($lines));
    }

    public function testAQuestionRecordedAfterTheRebuiltCallDoesNotCount(): void
    {
        self::assertNull(TaskPlanRenderTurnState::ofTurn($this->askOwnerTurn(['planItemKey' => '1'])->buildCurrentTurn(), 12));
        self::assertNotNull(TaskPlanRenderTurnState::ofTurn($this->askOwnerTurn(['planItemKey' => '1'])->buildCurrentTurn(), 13));
    }

    public function testTheResultLineNamesTheItemAndAnUnknownKeyAddsNothing(): void
    {
        $answer = AskOwnerTool::answerCall(self::QUESTION_ARGUMENTS + ['planItemKey' => '1'], $this->conversationWithPlan());
        self::assertFalse($answer->failed);
        self::assertStringContainsString('item 1 waits for this answer', $answer->text);

        $unknownKeyAnswer = AskOwnerTool::answerCall(self::QUESTION_ARGUMENTS + ['planItemKey' => '9'], $this->conversationWithPlan());
        self::assertFalse($unknownKeyAnswer->failed, 'an unknown key does not fail the call');
        self::assertStringNotContainsString('waits for this answer', $unknownKeyAnswer->text);

        $withoutKeyAnswer = AskOwnerTool::answerCall(self::QUESTION_ARGUMENTS, $this->conversationWithPlan());
        self::assertSame(AskOwnerTool::RESULT_RECORDED, $withoutKeyAnswer->text);
    }

    protected function planWithAnOwedLeafBeforeADraftItem(): TaskPlan
    {
        $taskPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Ask the owner which channel', 'key' => '1', 'status' => TaskPlanItem::STATUS_OPEN],
            ['text' => 'Post draft', 'key' => '2', 'status' => TaskPlanItem::STATUS_OPEN],
        ], 'highestItemKeyNumber' => 2]);
        self::assertTrue($taskPlan->items->getElements()[1]->markResultIsTextInTheAnswer());
        return $taskPlan;
    }

    protected function conversationWithPlan(): AIConversation
    {
        $conversation = new AIConversation();
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->taskPlan = $this->planWithAnOwedLeafBeforeADraftItem();
        $conversation->agentWorkingState = $agentWorkingState;
        $messageSet = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($messageSet, 'elements', [$this->message(10, AIConversationMessage::ROLE_USER, 'draft a post, ask me the channel')]);
        self::setPropertyValue($messageSet, 'elementCount', 1);
        $conversation->messages = $messageSet;
        return $conversation;
    }

    /** @param array<string, mixed> $extraArguments */
    protected function askOwnerTurn(array $extraArguments): AIConversationMessages
    {
        $ownerMessage = $this->message(10, AIConversationMessage::ROLE_USER, 'draft a post, ask me the channel');
        $askOwnerMessage = $this->message(12, AIConversationMessage::ROLE_ASSISTANT, '');
        $askOwnerMessage->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $askOwnerToolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $askOwnerToolCall->name = AskOwnerTool::NAME;
        $askOwnerToolCall->arguments = self::QUESTION_ARGUMENTS + $extraArguments;
        $askOwnerToolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $askOwnerMessage->toolCall = $askOwnerToolCall;
        $messageSet = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($messageSet, 'elements', [$ownerMessage, $askOwnerMessage]);
        self::setPropertyValue($messageSet, 'elementCount', 2);
        return $messageSet;
    }

    protected function message(int $index, string $role, string $text): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = $role;
        $message->authorType = $role === AIConversationMessage::ROLE_USER ? AIConversationMessage::AUTHOR_USER : AIConversationMessage::AUTHOR_ASSISTANT;
        $message->message = $text;
        $message->toolCall = null;
        return $message;
    }
}
