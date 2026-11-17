<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItemPlatformCloser;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An answer that ends on the model's question to the owner (askOwner) closes no answer-text item by itself; the model's
 * explicit tick of such an item in that turn closes it ("closed: its text is in this answer"). Every other item kind
 * keeps its gates.
 */
class AnswerTextItemTickedInAnOwnerQuestionTurnTest extends UnitTestCase
{
    public function testATickOfAnAnswerTextItemInAnAskOwnerTurnClosesIt(): void
    {
        $taskPlan = $this->planWithDraftItem();
        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '2', 'status' => 'done', 'statusNote' => 'two drafts in the answer']]],
            $taskPlan,
            10,
            $this->askOwnerTurn(AIConversationMessage::STATUS_PENDING)
        );

        self::assertSame(TaskPlanItem::STATUS_DONE, $taskPlan->items->getElements()[1]->status);
        self::assertContains("Task '2': closed: its text is in this answer.", $result['feedback']);
    }

    public function testWithoutATickAnAskOwnerTurnClosesNothing(): void
    {
        $taskPlan = $this->planWithDraftItem();
        $draftItem = $taskPlan->items->getElements()[1];
        $questionTurn = TaskPlanEvidence::forTurn($this->askOwnerTurn(AIConversationMessage::STATUS_COMPLETED), 10, 'Here are the drafts. Shall I publish one?');

        self::assertNull(TaskPlanItemPlatformCloser::forItem($draftItem)->applyTurnEndEvidenceClosing($questionTurn));
        self::assertTrue($draftItem->isOpen());
    }

    public function testATickOfAnotherItemKindInAnAskOwnerTurnKeepsTodaysGate(): void
    {
        $taskPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Deliver the report', 'key' => '1', 'status' => TaskPlanItem::STATUS_OPEN, 'seededByPurpose' => true, 'doneRequiresOwnerVisibleAnswerInSameTurn' => true],
        ], 'highestItemKeyNumber' => 1]);
        $deliveryItem = $taskPlan->items->getElements()[0];
        self::assertFalse($deliveryItem->isResultTextInTheAnswer());

        $result = SetTaskStatusTestTick::apply(
            ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'report delivered']]],
            $taskPlan,
            10,
            $this->askOwnerTurn(AIConversationMessage::STATUS_PENDING)
        );

        self::assertTrue($deliveryItem->isOpen(), 'the delivery gate still applies');
        self::assertSame(1, $result['notedCount']);
        self::assertStringContainsString('done not applied', implode("\n", $result['feedback']));
        self::assertStringNotContainsString('closed: its text is in this answer', implode("\n", $result['feedback']));
    }

    protected function planWithDraftItem(): TaskPlan
    {
        $taskPlan = TaskPlan::fromRepositoryArray(['items' => [
            ['text' => 'Read the posts', 'key' => '1', 'status' => TaskPlanItem::STATUS_DONE],
            ['text' => 'Two post drafts', 'key' => '2', 'status' => TaskPlanItem::STATUS_OPEN],
        ], 'highestItemKeyNumber' => 2]);
        self::assertTrue($taskPlan->items->getElements()[1]->markResultIsTextInTheAnswer());
        return $taskPlan;
    }

    protected function askOwnerTurn(string $askOwnerCallStatus): AIConversationMessages
    {
        $ownerMessage = $this->message(10, AIConversationMessage::ROLE_USER, 'draft two posts, then ask me');
        $askOwnerMessage = $this->message(12, AIConversationMessage::ROLE_ASSISTANT, '');
        $askOwnerMessage->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $askOwnerToolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $askOwnerToolCall->name = AskOwnerTool::NAME;
        $askOwnerToolCall->arguments = [];
        $askOwnerToolCall->status = $askOwnerCallStatus;
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
