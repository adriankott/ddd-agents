<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TaskPlanRecord;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanModuleItemTestCase;

/**
 * The turn-end closing of the record service ({@see AIConversationTaskPlanRecordService::closePlanLeavesAtTurnEnd()}):
 * what it persists (once, or not at all), the two turn stamps, and the note the next model step reads — merged with a
 * note an earlier turn end left, worded for the turn start when the closing observes the owner's answer. The
 * conversations service is a double that records the slices it is asked to persist.
 */
class AIConversationTaskPlanRecordServiceTurnEndClosingTest extends TaskPlanModuleItemTestCase
{
    protected const string CLOSED_LINE = '- 1 → done (closed by the platform: HandoverWidget widget rendered)';

    protected const string NOTE_TAIL = "\nThey show as terminal in your task plan — do NOT tick them again; continue with the next open item.";

    protected ConversationsServiceRecordingTaskPlanSlicesDouble $conversationsService;

    /** @var array<string, mixed> */
    protected array $entityServicesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityServicesBefore = StaticRegistry::$entityServices;
        $this->conversationsService = self::instantiateWithoutConstructor(ConversationsServiceRecordingTaskPlanSlicesDouble::class);
        StaticRegistry::$entityServices[AIConversations::class] = $this->conversationsService;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$entityServices = $this->entityServicesBefore;
        parent::tearDown();
    }

    protected function conversationWithPlan(TaskPlan $taskPlan, bool $handoverRendered): AIConversation
    {
        $messages = new AIConversationMessages();
        // ObjectSet::add() is BY-REFERENCE — pass a variable, never a method return
        $ownerMessage = $this->ownerText(10, 'go on');
        $messages->add($ownerMessage);
        $answerMessage = $this->assistantText(11, $handoverRendered ? "All set.\n" . $this->widgetFence('HandoverWidget') : 'All set.');
        $messages->add($answerMessage);
        $conversation = new AIConversation();
        $conversation->messages = $messages;
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->taskPlan = $taskPlan;
        $conversation->agentWorkingState = $agentWorkingState;
        return $conversation;
    }

    protected function handoverPlan(): TaskPlan
    {
        return $this->taskPlan([$this->itemWithFields('Handover delivered', ['doneRequiresWidget' => 'HandoverWidget']), $this->item('Next step')]);
    }

    protected function closeAtTurnEnd(AIConversation $conversation, bool $stampsTurnProgress = true): void
    {
        /** @var AIConversationTaskPlanRecordService $aIConversationTaskPlanRecordService */
        $aIConversationTaskPlanRecordService = self::instantiateWithoutConstructor(AIConversationTaskPlanRecordService::class);
        $aIConversationTaskPlanRecordService->closePlanLeavesAtTurnEnd($conversation, AgentTransientMessageIds::startingAt(0), null, false, $stampsTurnProgress);
    }

    public function testATurnEndWithoutEvidencePersistsNothingAndStampsNothing(): void
    {
        $taskPlan = $this->handoverPlan();

        $this->closeAtTurnEnd($this->conversationWithPlan($taskPlan, false));

        self::assertSame([], $this->conversationsService->persistedTaskPlanSlices);
        self::assertNull($taskPlan->updatedAtTurnInitiatorMessageIndex);
        self::assertNull($taskPlan->resolvedAtTurnInitiatorMessageIndex);
        self::assertNull($taskPlan->platformClosedAtTurnEndNote);
    }

    public function testAPlanWithNothingOpenIsNotReadAtTheTurnEnd(): void
    {
        $taskPlan = $this->taskPlan([$this->itemWithFields('Handover delivered', ['doneRequiresWidget' => 'HandoverWidget', 'status' => TaskPlanItem::STATUS_NOT_APPLICABLE])]);

        $this->closeAtTurnEnd($this->conversationWithPlan($taskPlan, true));

        self::assertSame([], $this->conversationsService->persistedTaskPlanSlices);
        self::assertSame(TaskPlanItem::STATUS_NOT_APPLICABLE, $taskPlan->items->getItemByKey('1')->status);
    }

    public function testATurnEndClosingStampsBothMarkersWritesTheNoteAndPersistsTheSliceOnce(): void
    {
        $taskPlan = $this->handoverPlan();
        $conversation = $this->conversationWithPlan($taskPlan, true);

        $this->closeAtTurnEnd($conversation);

        self::assertSame(TaskPlanItem::STATUS_DONE, $taskPlan->items->getItemByKey('1')->status);
        self::assertSame(10, $taskPlan->updatedAtTurnInitiatorMessageIndex);
        self::assertSame(10, $taskPlan->resolvedAtTurnInitiatorMessageIndex);
        self::assertSame("PLAN: at the end of your last turn the platform closed these plan items from the record:\n" . self::CLOSED_LINE . self::NOTE_TAIL, $taskPlan->platformClosedAtTurnEndNote);
        self::assertCount(1, $this->conversationsService->persistedTaskPlanSlices);
        self::assertSame($conversation, $this->conversationsService->persistedTaskPlanSlices[0]['conversation']);
        self::assertSame($taskPlan, $this->conversationsService->persistedTaskPlanSlices[0]['taskPlan']);
        self::assertNull($this->conversationsService->persistedTaskPlanSlices[0]['runToken'], 'a conversation this process holds no claim on');
    }

    public function testANoteAnEarlierTurnEndLeftIsKeptAboveTheNewOne(): void
    {
        $taskPlan = $this->handoverPlan();
        $taskPlan->platformClosedAtTurnEndNote = "  PLAN: an earlier closing.\n";

        $this->closeAtTurnEnd($this->conversationWithPlan($taskPlan, true));

        self::assertSame(
            "PLAN: an earlier closing.\nPLAN: at the end of your last turn the platform closed these plan items from the record:\n" . self::CLOSED_LINE . self::NOTE_TAIL,
            $taskPlan->platformClosedAtTurnEndNote
        );
    }

    public function testAClosingAtTheTurnStartIsWordedAsTheOwnersAnswerAndGrantsNoProgressStamp(): void
    {
        $taskPlan = $this->handoverPlan();

        $this->closeAtTurnEnd($this->conversationWithPlan($taskPlan, true), false);

        self::assertNull($taskPlan->updatedAtTurnInitiatorMessageIndex);
        self::assertNull($taskPlan->resolvedAtTurnInitiatorMessageIndex);
        self::assertSame("PLAN: the owner's answer just closed these plan items from the record:\n" . self::CLOSED_LINE . self::NOTE_TAIL, $taskPlan->platformClosedAtTurnEndNote);
        self::assertCount(1, $this->conversationsService->persistedTaskPlanSlices);
    }
}
