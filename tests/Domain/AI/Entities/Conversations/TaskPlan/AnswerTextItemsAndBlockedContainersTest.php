<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItemPlatformCloser;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Two plan rules: an item whose result is text in the answer (`inAnswer`, a draft) carries the delivery gate and the
 * platform closes it from the turn's owner-visible answer — no call proves it; and a container whose subtasks are all
 * closed and none of them done takes its blocker's state: it renders and counts blocked, never ✓.
 */
class AnswerTextItemsAndBlockedContainersTest extends UnitTestCase
{
    public function testTheSchemaOffersInAnswer(): void
    {
        $itemProperties = (new UpdateTaskPlanTool())->toOpenAiToolSchema()['function']['parameters']['properties']['items']['items']['properties'];

        self::assertSame(
            'true for an item whose result is text you write in your answer (a draft, a summary): the platform closes it when your answer carries that text; never tick it.',
            $itemProperties['inAnswer']['description']
        );
    }

    public function testAModelLeafMarkedInAnswerClosesFromTheTurnsAnswerAndOnlyThen(): void
    {
        $draftItem = new TaskPlanItem();
        $draftItem->text = 'Draft the Instagram post';
        self::assertTrue($draftItem->markResultIsTextInTheAnswer());
        self::assertTrue($draftItem->isResultTextInTheAnswer());

        $withoutAnswer = TaskPlanEvidence::forTurn($this->messageSet([$this->message(10, AIConversationMessage::ROLE_USER, 'write me a post')]), 10);
        self::assertNull(TaskPlanItemPlatformCloser::forItem($draftItem)->applyTurnEndEvidenceClosing($withoutAnswer), 'no answer yet: open');

        $withAnswer = TaskPlanEvidence::forTurn(
            $this->messageSet([$this->message(10, AIConversationMessage::ROLE_USER, 'write me a post')]),
            10,
            'Here is the post: Sunny Saturday at the bakery — fresh rolls from 7 am. Shall I publish it?'
        );
        self::assertSame(TaskPlanItem::STATUS_DONE, TaskPlanItemPlatformCloser::forItem($draftItem)->applyTurnEndEvidenceClosing($withAnswer));
    }

    public function testASeededItemOrAContainerIsNotMarked(): void
    {
        $seededItem = new TaskPlanItem();
        $seededItem->seededByPurpose = true;
        self::assertFalse($seededItem->markResultIsTextInTheAnswer());
        self::assertFalse($seededItem->doneRequiresOwnerVisibleAnswerInSameTurn);

        $containerItem = new TaskPlanItem();
        $containerItem->subtasks = new TaskPlanItems();
        $subtaskItem = new TaskPlanItem();
        $containerItem->subtasks->add($subtaskItem);
        self::assertFalse($containerItem->markResultIsTextInTheAnswer());
    }

    public function testAContainerWithOnlyBlockedSubtasksRendersAndCountsBlocked(): void
    {
        $partItem = new TaskPlanItem();
        $partItem->text = 'Rankings report';
        $partItem->subtasks = new TaskPlanItems();
        $loadSkillItem = new TaskPlanItem();
        $loadSkillItem->text = 'Load the skill';
        $loadSkillItem->status = TaskPlanItem::STATUS_BLOCKED;
        $loadSkillItem->statusNote = 'the skill is not available';
        $partItem->subtasks->add($loadSkillItem);
        $taskPlanItems = new TaskPlanItems();
        $taskPlanItems->add($partItem);

        self::assertFalse($partItem->isOpen(), 'the container is closed: no open subtask');
        self::assertFalse($partItem->rendersAsDoneOnPlanLine());
        self::assertTrue($partItem->rendersAsBlockedOnPlanLine());
        self::assertSame('[✗ blocked: the skill is not available] 1 Rankings report', $partItem->toCompactTailHintLine('1', false));
        $statusCounts = $taskPlanItems->countItemsByStatus();
        self::assertSame([0, 0, 2], [$statusCounts->done, $statusCounts->open, $statusCounts->blocked]);

        $reportItem = new TaskPlanItem();
        $reportItem->text = 'Write the report';
        $reportItem->status = TaskPlanItem::STATUS_DONE;
        $partItem->subtasks->add($reportItem);
        self::assertTrue($partItem->rendersAsDoneOnPlanLine(), 'with a done subtask the container is done');
        self::assertFalse($partItem->rendersAsBlockedOnPlanLine());
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

    /** @param AIConversationMessage[] $messages */
    protected function messageSet(array $messages): AIConversationMessages
    {
        $messageSet = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($messageSet, 'elements', $messages);
        self::setPropertyValue($messageSet, 'elementCount', count($messages));
        return $messageSet;
    }
}
