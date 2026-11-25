<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TaskPlanRecord;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentToolCallWorkRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanModuleItemTestCase;

/**
 * The two plan tools as the record service applies them ({@see AIConversationTaskPlanRecordService::applySetTaskStatusCall()},
 * {@see AIConversationTaskPlanRecordService::applyUpdateTaskPlanCall()}): the result text the model reads, the call's
 * status and recorded outcome, its refusal flags, the forced tool-less answer step and the two turn stamps of the plan.
 * The conversations carry no purpose and no space, so no record verdict gates a tick, except where a case puts a
 * purpose double in place. What the plan itself decides per gate (the feedback line of each gate, the fields it
 * writes) is the gate tables' ({@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\SetTaskStatusGateTableCharacterizationTest},
 * {@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\UpdateTaskPlanResendGateTableCharacterizationTest}).
 */
class AIConversationTaskPlanRecordServicePlanToolsTest extends TaskPlanModuleItemTestCase
{
    protected const string MAINTENANCE_TAIL = ' Never resend the whole plan for a status change (update_task_plan is for structure only). If the user changes'
        . ' the task mid-run, revise the plan FIRST (add the new items, drop cancelled items with "remove": true), then continue.';

    protected const string CREATION_KEY_SENTENCE = ' Each item\'s number is its key: in a later update_task_plan send an existing item as {"key": "2"} and a new item as {"text": "…"}.';

    protected const string ALREADY_LECTURE = "Task '1' is ALREADY done — no change. Nothing failed and nothing is"
        . " unavailable; NEVER tell the user you lack access. Do NOT re-tick. Look at the plan: if the '← next' open"
        . " item needs NO owner input, CONTINUE WORKING IT in THIS turn — ending your turn with an announcement of that"
        . " work ('next I will …') is a defect; a status line belongs in a {preCallMessage} before the call that does"
        . ' the work, never in your final text. Write your final answer (including any owed widget fence) only when'
        . ' every remaining open item genuinely needs the owner, or none is left.';

    protected const string TOOL_GATE_NOTED = "Task '%s': done not applied — this item closes from the record of a"
        . ' completed `recordGoal` call; the platform ticks it then. Never run that write only to tick it: the write'
        . " follows its own preconditions (the owner's approval where the item is a line of the draft).";

    protected const string UNCHANGED_RESEND = 'Task plan NOT updated: it is ALREADY in exactly this state — resending it does no work. Nothing failed and nothing is unavailable.'
        . ' Go on from the plan as it stands: the work of the next open item — or your answer, when the owner asked to be asked first'
        . ' or to stop, or the open items wait for them. If a tool you need is missing from your tools list, call load_tool with its'
        . ' name (its schema lists every available tool). For status changes use set_task_status.';

    protected static function maintenance(): string
    {
        return ' MAINTENANCE: ' . TaskPlan::buildTickRules() . self::MAINTENANCE_TAIL;
    }

    // --- builders -------------------------------------------------------------------------------------------------------

    /** @param TaskPlanItem[] $taskPlanItemList */
    protected function conversation(array $messageList, ?TaskPlan $taskPlan = null): AIConversation
    {
        $messages = new AIConversationMessages();
        foreach ($messageList as $message) {
            $messages->add($message);
        }
        $conversation = new AIConversation();
        $conversation->messages = $messages;
        if ($taskPlan !== null) {
            $agentWorkingState = new AgentWorkingState();
            $agentWorkingState->taskPlan = $taskPlan;
            $conversation->agentWorkingState = $agentWorkingState;
        }
        return $conversation;
    }

    protected function toolCallMessage(int $index, string $toolName, string $status): AIConversationMessage
    {
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = [];
        $toolCall->status = $status;
        $toolCall->result = '{"content":[]}';
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function recordService(): AIConversationTaskPlanRecordService
    {
        return self::instantiateWithoutConstructor(AIConversationTaskPlanRecordService::class);
    }

    /**
     * Runs `set_task_status` and returns [result text, call status, recorded outcome, forced tool-less step]. The tick's
     * evidence carries an empty list of entitlement-locked tools, so no catalog is read.
     *
     * @param array<string, mixed> $arguments
     * @return array{0: string, 1: ?string, 2: ?string, 3: bool}
     */
    protected function applySetTaskStatus(AIConversation $conversation, array $arguments, ?AIConversationTaskPlanRecordService $aIConversationTaskPlanRecordService = null): array
    {
        $toolCall = new ToolCall();
        $toolCall->name = SetTaskStatusTool::NAME;
        $taskPlanEvidence = TaskPlanEvidence::forTurn($conversation->messages, $conversation->messages->getNewestInitiatorMessageIndex() ?? -1, criteriaDecideTicks: false, entitlementLockedToolNames: []);
        $setTaskStatusCallAnswer = ($aIConversationTaskPlanRecordService ?? $this->recordService())
            ->applySetTaskStatusCall($conversation, $toolCall, AgentToolCallWorkRequest::applyTaskStatusUpdates($arguments), $taskPlanEvidence);
        return [$setTaskStatusCallAnswer->text, $toolCall->status ?? null, $toolCall->taskPlanCallOutcome ?? null, $setTaskStatusCallAnswer->forcesToolLessAnswerStep];
    }

    /**
     * Runs `update_task_plan` and returns [result text, the call].
     *
     * @param array<string, mixed> $arguments
     * @return array{0: string, 1: ToolCall}
     */
    protected function applyUpdateTaskPlan(AIConversation $conversation, array $arguments, ?AIConversationTaskPlanRecordService $aIConversationTaskPlanRecordService = null): array
    {
        $toolCall = new ToolCall();
        $toolCall->name = UpdateTaskPlanTool::NAME;
        $resultText = ($aIConversationTaskPlanRecordService ?? $this->recordService())
            ->applyUpdateTaskPlanCall($conversation, $toolCall, AgentToolCallWorkRequest::applyTaskPlanUpdate($arguments));
        return [$resultText, $toolCall];
    }

    /** A plan of `$taskPlanItemList` that this turn (initiator index 0) wrote already. */
    protected function planWrittenInTurnZero(array $taskPlanItemList): TaskPlan
    {
        $taskPlan = $this->taskPlan($taskPlanItemList);
        $taskPlan->updatedAtTurnInitiatorMessageIndex = 0;
        return $taskPlan;
    }

    // --- set_task_status ------------------------------------------------------------------------------------------------

    public function testATickWithoutAPlanFailsTheCallAndIsRecordedRefused(): void
    {
        $conversation = $this->conversation([$this->ownerText(0, 'Hello')]);

        self::assertSame(
            ['No task plan exists yet — create it first with update_task_plan (the complete plan as items: [{text, status}, …]).', AIConversationMessage::STATUS_FAILED, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'done']]])
        );
    }

    /** @return array<string, array{0: bool}> */
    public static function provideWhoMadeTheItem(): array
    {
        return [
            'an item the model made' => [false],
            'an item the purpose seeded, without a record gate' => [true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideWhoMadeTheItem')]
    public function testAnAppliedTickCompletesTheCallAndStampsBothTurnMarkers(bool $seededByPurpose): void
    {
        $taskPlan = $this->taskPlan([$this->itemWithFields('First', ['seededByPurpose' => $seededByPurpose]), $this->item('Second')]);
        $conversation = $this->conversation([$this->ownerText(0, 'Hello')], $taskPlan);

        self::assertSame(
            ['Updated 1 item(s). Plan now: 1 done, 1 open, 0 blocked.', AIConversationMessage::STATUS_COMPLETED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'stored']]])
        );
        self::assertSame(TaskPlanItem::STATUS_DONE, $taskPlan->items->getItemByKey('1')->status);
        self::assertSame(0, $taskPlan->updatedAtTurnInitiatorMessageIndex);
        self::assertSame(0, $taskPlan->resolvedAtTurnInitiatorMessageIndex);
    }

    public function testAnAppliedTickThatClosesTheLastOpenItemNeverForcesTheToolLessStep(): void
    {
        $conversation = $this->conversation([$this->ownerText(0, 'Hello')], $this->taskPlan([$this->item('First', TaskPlanItem::STATUS_DONE, 'stored'), $this->item('Second')]));

        self::assertSame(
            ['Updated 1 item(s). Plan now: 2 done, 0 open, 0 blocked.', AIConversationMessage::STATUS_COMPLETED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '2', 'status' => 'done', 'statusNote' => 'stored']]])
        );
    }

    public function testThePlanNowCountsANotApplicableContainerDoneAndPrunesItsOpenSubtree(): void
    {
        $container = $this->itemWithFields('Shop', ['subtasks' => $this->itemSet([$this->item('Opening hours'), $this->item('Service areas')])]);
        $conversation = $this->conversation([$this->ownerText(0, 'Hello')], $this->taskPlan([$container]));

        // the counts prune a not_applicable container as hasOpenItems() does: nothing open is counted open
        self::assertSame(
            ['Updated 1 item(s). Plan now: 1 done, 0 open, 0 blocked.', AIConversationMessage::STATUS_COMPLETED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'not_applicable', 'statusNote' => 'a pure online shop']]])
        );
        self::assertFalse($conversation->getTaskPlan()->hasOpenItems());
    }

    public function testATickTheRecordCannotConfirmIsNotedCompletedAndStampsOnlyTheTouchMarker(): void
    {
        $taskPlan = $this->taskPlan([$this->itemWithFields('Goal', ['doneRequiresToolCall' => 'recordGoal'])]);
        $conversation = $this->conversation([$this->ownerText(0, 'More customers')], $taskPlan);

        self::assertSame(
            ['1 tick(s) not applied — the record closes these items; continue with the open items. ' . sprintf(self::TOOL_GATE_NOTED, '1'),
                AIConversationMessage::STATUS_COMPLETED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'goal noted']]])
        );
        self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlan->items->getItemByKey('1')->status);
        self::assertSame(0, $taskPlan->updatedAtTurnInitiatorMessageIndex);
        self::assertNull($taskPlan->resolvedAtTurnInitiatorMessageIndex);
    }

    public function testATransientObstacleOnALeafTheRecordClosesIsNotedUnderTheSameHeader(): void
    {
        $conversation = $this->conversation([$this->ownerText(0, 'More customers')], $this->taskPlan([$this->itemWithFields('Goal', ['doneRequiresToolCall' => 'recordGoal'])]));

        self::assertSame(
            ['1 tick(s) not applied — the record closes these items; continue with the open items.'
                . " Task '1': blocked not applied — a transient obstacle never closes this item: it stays OPEN (the platform closes it from the"
                . ' record once its write lands). Retry it in THIS turn as soon as the obstacle is gone (after the save it waited for);'
                . ' do not mark it blocked again.',
                AIConversationMessage::STATUS_COMPLETED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => 'rate limit', 'retryable' => true]]])
        );
    }

    public function testAnAppliedUpdateWinsTheResultHeaderOverANotedOneInTheSameCall(): void
    {
        $conversation = $this->conversation([$this->ownerText(0, 'Hello')], $this->taskPlan([$this->item('First'), $this->itemWithFields('Goal', ['doneRequiresToolCall' => 'recordGoal'])]));

        self::assertSame(
            ['Updated 1 item(s). Plan now: 1 done, 1 open, 0 blocked. ' . sprintf(self::TOOL_GATE_NOTED, '2'), AIConversationMessage::STATUS_COMPLETED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [
                ['task' => '1', 'status' => 'done', 'statusNote' => 'stored'],
                ['task' => '2', 'status' => 'done', 'statusNote' => 'goal noted'],
            ]])
        );
    }

    public function testATickOfAnItemThePlatformClosedInThisTurnIsANoChangeNeededSuccessWithoutStamps(): void
    {
        $taskPlan = $this->taskPlan([$this->itemWithFields('Profile', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'closed by the platform', 'platformResolvedAtMessageIndex' => 0])]);
        $conversation = $this->conversation([$this->ownerText(0, 'Yes')], $taskPlan);

        self::assertSame(
            [SetTaskStatusTool::RESULT_PREFIX_NO_CHANGE_NEEDED . " Task '1' was closed by the platform in this turn — it is done, nothing to do.", AIConversationMessage::STATUS_COMPLETED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'done']]])
        );
        self::assertNull($taskPlan->updatedAtTurnInitiatorMessageIndex);
    }

    public function testAnAllNoOpTickWithOpenItemsLeftFailsWithoutForcingAndRefreshesTheNote(): void
    {
        $taskPlan = $this->taskPlan([$this->item('First', TaskPlanItem::STATUS_DONE, 'stored'), $this->item('Second')]);
        $conversation = $this->conversation([$this->ownerText(0, 'Hello')], $taskPlan);

        self::assertSame(
            ['No item updated: ' . self::ALREADY_LECTURE, AIConversationMessage::STATUS_FAILED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'stored again']]])
        );
        self::assertSame('stored again', $taskPlan->items->getItemByKey('1')->statusNote, 'the no-op still overwrites the note');
        self::assertNull($taskPlan->updatedAtTurnInitiatorMessageIndex, 'a no-op tick is no plan touch');
    }

    public function testAnAllNoOpTickForcesTheToolLessStepWhenNothingIsOpen(): void
    {
        $conversation = $this->conversation([$this->ownerText(0, 'Hello')], $this->taskPlan([$this->item('First', TaskPlanItem::STATUS_DONE, 'stored')]));

        self::assertSame(
            ['No item updated: ' . self::ALREADY_LECTURE, AIConversationMessage::STATUS_FAILED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, true],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'stored']]])
        );
    }

    public function testAnAllNoOpTickAfterAnEarlierFailedTickOfThisTurnKeepsTheToolsWhileANextItemIsWorkable(): void
    {
        $conversation = $this->conversation(
            [$this->ownerText(0, 'Hello'), $this->toolCallMessage(1, SetTaskStatusTool::NAME, AIConversationMessage::STATUS_FAILED)],
            $this->taskPlan([$this->item('First', TaskPlanItem::STATUS_DONE, 'stored'), $this->item('Second')])
        );

        self::assertSame(
            ['No item updated: ' . self::ALREADY_LECTURE, AIConversationMessage::STATUS_FAILED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'stored']]])
        );
    }

    public function testAnAllNoOpTickAfterAnEarlierFailedTickForcesTheStepWhenNoNextItemIsWorkable(): void
    {
        $awaitingOffer = $this->itemWithFields('Offer', ['doneRequiresWidgetAnswered' => true, 'doneRequiresWidget' => 'OfferWidget', 'offerRenderedAtMessageIndex' => 0]);
        $conversation = $this->conversation(
            [$this->ownerText(0, 'Hello'), $this->toolCallMessage(1, SetTaskStatusTool::NAME, AIConversationMessage::STATUS_FAILED)],
            $this->taskPlan([$this->item('First', TaskPlanItem::STATUS_DONE, 'stored'), $awaitingOffer])
        );

        [, $callStatus, , $forcesToolLessAnswerStep] = $this->applySetTaskStatus($conversation, ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'stored']]]);

        self::assertSame(AIConversationMessage::STATUS_FAILED, $callStatus);
        self::assertTrue($forcesToolLessAnswerStep);
    }

    public function testAnAddressThatIsNoKeyOrAMissingUpdatesListFailsTheCallWithoutAStamp(): void
    {
        $taskPlan = $this->taskPlan([$this->item('First')]);
        $conversation = $this->conversation([$this->ownerText(0, 'Hello')], $taskPlan);

        self::assertSame(
            ["Task status NOT updated — nothing changed. Fix every line below and send the call again:\n- Task '9' does not exist in your plan.\nYour open items: 1 'First'", AIConversationMessage::STATUS_FAILED, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED, false],
            $this->applySetTaskStatus($conversation, ['updates' => [['task' => '9', 'status' => 'done', 'statusNote' => 'x']]])
        );
        self::assertSame(
            ["No item updated: 'updates' is missing or not an array — send updates: [{task, status}, …].", AIConversationMessage::STATUS_FAILED, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED, false],
            $this->applySetTaskStatus($conversation, [])
        );
        self::assertNull($taskPlan->updatedAtTurnInitiatorMessageIndex);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $taskPlan->items->getItemByKey('1')->status);
    }

    // --- update_task_plan -----------------------------------------------------------------------------------------------

    public function testThePlanCreationResultCarriesTheCountsTheFeedbackTheMaintenanceRulesAndTheKeySentence(): void
    {
        $conversation = $this->conversation([$this->ownerText(0, 'Help me with the profile')]);

        [$resultText, $toolCall] = $this->applyUpdateTaskPlan($conversation, ['items' => [
            ['text' => 'First', 'status' => 'open'],
            ['text' => 'Second', 'status' => 'completed', 'statusNote' => 'done it'],
        ]]);

        self::assertSame(
            "Task plan updated: 2 items (1 done, 1 open, 0 blocked). Task '2': status 'completed' normalized to 'done'."
                . self::maintenance() . self::CREATION_KEY_SENTENCE,
            $resultText
        );
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $toolCall->status);
        self::assertSame(ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, $toolCall->taskPlanCallOutcome);
        $createdPlan = $conversation->getTaskPlan();
        self::assertSame(0, $createdPlan->createdAtMessageIndex);
        self::assertSame(0, $createdPlan->updatedAtTurnInitiatorMessageIndex);
        self::assertNull($createdPlan->resolvedAtTurnInitiatorMessageIndex, 'an item CREATED done resolves nothing');
        self::assertNull($createdPlan->title);
    }

    public function testAChangeInTheTurnThatWroteThePlanKeepsCreationAndTitleAndCarriesNoMaintenance(): void
    {
        $priorPlan = $this->planWrittenInTurnZero([$this->item('First'), $this->item('Second')]);
        $priorPlan->createdAtMessageIndex = 0;
        $priorPlan->title = 'Owner request';
        $conversation = $this->conversation([$this->ownerText(0, 'Help me')], $priorPlan);

        [$resultText, $toolCall] = $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1'], ['key' => '2', 'remove' => true], ['text' => 'Third']]]);

        self::assertSame("Task plan updated: 2 items (0 done, 2 open, 0 blocked). Added: 3 'Third' (at the end). Dropped: 2 'Second'. The owner's newest message: «Help me». A dropped item is gone from the plan and its work is not delivered: if the owner's message did not cancel one of these, add it back now and work it.", $resultText);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $toolCall->status);
        self::assertSame(ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, $toolCall->taskPlanCallOutcome);
        $changedPlan = $conversation->getTaskPlan();
        self::assertNotSame($priorPlan, $changedPlan);
        self::assertSame(0, $changedPlan->createdAtMessageIndex);
        self::assertSame('Owner request', $changedPlan->title);
    }

    public function testAChangeInALaterTurnKeepsTheCreationIndexAndCarriesTheMaintenanceRules(): void
    {
        $priorPlan = $this->planWrittenInTurnZero([$this->item('First'), $this->item('Second')]);
        $priorPlan->createdAtMessageIndex = 0;
        $priorPlan->title = 'Owner request';
        $conversation = $this->conversation([$this->ownerText(0, 'Help me'), $this->assistantText(1, 'Gladly.'), $this->ownerText(2, 'One more thing')], $priorPlan);

        [$resultText] = $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1'], ['key' => '2', 'remove' => true], ['text' => 'Third']]]);

        self::assertSame("Task plan updated: 2 items (0 done, 2 open, 0 blocked). Added: 3 'Third' (at the end). Dropped: 2 'Second'. The owner's newest message: «One more thing». A dropped item is gone from the plan and its work is not delivered: if the owner's message did not cancel one of these, add it back now and work it." . self::maintenance(), $resultText);
        self::assertSame(0, $conversation->getTaskPlan()->createdAtMessageIndex);
        self::assertSame('Owner request', $conversation->getTaskPlan()->title);
    }

    public function testAnIdenticalResendIsRefusedAsADuplicateCompletedAndKeepsThePlan(): void
    {
        $priorPlan = $this->planWrittenInTurnZero([$this->item('First'), $this->item('Second')]);
        $conversation = $this->conversation([$this->ownerText(0, 'Help me')], $priorPlan);

        [$resultText, $toolCall] = $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1'], ['key' => '2']]]);

        self::assertSame(self::UNCHANGED_RESEND, $resultText);
        self::assertTrue($toolCall->wasRefusedAsDuplicate);
        self::assertFalse($toolCall->wasRefusedByGate);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $toolCall->status);
        self::assertSame(ToolCall::TASK_PLAN_CALL_OUTCOME_UNCHANGED, $toolCall->taskPlanCallOutcome);
        self::assertSame($priorPlan, $conversation->getTaskPlan());
    }

    public function testAnIdenticalResendInALaterTurnAppendsTheMaintenanceRulesAndStampsTheTouch(): void
    {
        $priorPlan = $this->planWrittenInTurnZero([$this->itemWithFields('First', ['seededByPurpose' => true])]);
        $conversation = $this->conversation([$this->ownerText(0, 'Help me'), $this->assistantText(1, 'Gladly.'), $this->ownerText(2, 'Go on')], $priorPlan);

        [$resultText] = $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1']]]);

        self::assertSame(self::UNCHANGED_RESEND . self::maintenance(), $resultText);
        self::assertSame(2, $priorPlan->updatedAtTurnInitiatorMessageIndex);
    }

    public function testAnUnchangedCallWhoseStatusAGateRefusedSaysSoAndIsNoDuplicate(): void
    {
        $priorPlan = $this->planWrittenInTurnZero([$this->itemWithFields('Connect offer', ['blockedRequiresWidget' => 'OfferWidget']), $this->item('Second')]);
        $conversation = $this->conversation([$this->ownerText(0, 'Help me')], $priorPlan);

        [$resultText, $toolCall] = $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1', 'status' => 'blocked', 'statusNote' => 'owner deferred'], ['key' => '2']]]);

        self::assertStringStartsWith(
            'Task plan NOT updated: a status you sent was NOT applied (the reason follows) and nothing else changed. Do what the reason names first,'
                . ' then send the status again. Task \'1\': blocked not applied — blocked here means the owner DEFERRED an offer,',
            $resultText
        );
        self::assertStringNotContainsString('MAINTENANCE', $resultText);
        self::assertSame(ToolCall::TASK_PLAN_CALL_OUTCOME_UNCHANGED, $toolCall->taskPlanCallOutcome);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $toolCall->status);
        self::assertTrue($toolCall->wasRefusedByGate);
        self::assertFalse($toolCall->wasRefusedAsDuplicate);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $conversation->getTaskPlan()->items->getItemByKey('1')->status);
    }

    public function testAnAppliedCallWhoseStatusAGateRefusedWarnsInItsHeader(): void
    {
        $priorPlan = $this->planWrittenInTurnZero([$this->itemWithFields('Connect offer', ['blockedRequiresWidget' => 'OfferWidget']), $this->item('Second')]);
        $conversation = $this->conversation([$this->ownerText(0, 'Help me')], $priorPlan);

        [$resultText, $toolCall] = $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1', 'status' => 'blocked', 'statusNote' => 'owner deferred'], ['key' => '2'], ['text' => 'Third']]]);

        self::assertStringStartsWith(
            'Task plan updated: 3 items (0 done, 3 open, 0 blocked). (A status you sent was NOT applied — see the lines below.) Added: 3 \'Third\' (at the end).'
                . ' Task \'1\': blocked not applied —',
            $resultText
        );
        self::assertSame(ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, $toolCall->taskPlanCallOutcome);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $conversation->getTaskPlan()->items->getItemByKey('1')->status);
    }

    public function testTheTickJudgementIsReadOnlyWhenACallSendsAStatusTransition(): void
    {
        $priorPlan = $this->planWrittenInTurnZero([$this->item('First', TaskPlanItem::STATUS_DONE), $this->item('Second')]);
        $conversation = $this->conversation([$this->ownerText(0, 'Help me')], $priorPlan);
        $countingRecordService = self::instantiateWithoutConstructor(TaskPlanRecordServiceCountingJudgementReadsDouble::class);

        $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1', 'status' => 'open']]], $countingRecordService);
        $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1'], ['key' => '2'], ['text' => 'Third']]], $countingRecordService);
        $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1', 'status' => 'done'], ['key' => '2'], ['key' => '3']]], $countingRecordService);
        self::assertSame(0, $countingRecordService->judgementReadCount, 'rejected, no status, the stored status again: nothing read');

        $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1'], ['key' => '2', 'status' => 'done', 'statusNote' => 'done it'], ['key' => '3']]], $countingRecordService);
        self::assertSame(1, $countingRecordService->judgementReadCount, 'a transition reads the judgement once');
        self::assertSame(TaskPlanItem::STATUS_DONE, $conversation->getTaskPlan()->items->getItemByKey('2')->status);
    }

    public function testTheConnectedChannelOfAKeyedUpdateIsReadOncePerChannelThroughThePurposeOfTheSpace(): void
    {
        // the module's own item declares no channel (a declared name is dropped on every read): an application's item does
        StaticRegistry::$frameworkToAppClassNames[TaskPlanItem::class] = TaskPlanItemWithACalendarChannel::class;
        $priorPlan = $this->planWrittenInTurnZero([
            $this->itemWithFields('Connect the calendar', ['doneRequiresConnectedChannel' => 'calendar']),
            $this->itemWithFields('Confirm the calendar', ['doneRequiresConnectedChannel' => 'calendar']),
        ]);
        $conversation = $this->conversation([$this->ownerText(0, 'Help me')], $priorPlan);
        $agentSpace = new AgentSpace();
        $agentSpace->id = 31;
        $conversation->agentSpace = $agentSpace;
        $channelReadingPurpose = self::instantiateWithoutConstructor(PurposeRecordingTheChannelsItReadsDouble::class);
        $channelReadingPurpose->connectionStateByChannel = ['calendar' => false];
        $channelReadingRecordService = self::instantiateWithoutConstructor(TaskPlanRecordServiceWithAGivenPurposeDouble::class);
        $channelReadingRecordService->purposeJudgingTicks = $channelReadingPurpose;

        [$resultText] = $this->applyUpdateTaskPlan($conversation, ['items' => [['key' => '1', 'status' => 'done', 'statusNote' => 'connected'], ['key' => '2']]], $channelReadingRecordService);

        self::assertSame(['calendar'], $channelReadingPurpose->readChannels, 'one read per declared channel; item 2 sends no status');
        self::assertSame([31], $channelReadingPurpose->readAgentSpaceIds, 'the channels of the conversation\'s space');
        self::assertStringContainsString("Task '1': done not applied — the calendar is NOT connected.", $resultText);
        self::assertSame(TaskPlanItem::STATUS_OPEN, $conversation->getTaskPlan()->items->getItemByKey('1')->status);
    }

    public function testARejectedCallFailsAndStillStampsTheStandingPlanAsTouched(): void
    {
        $priorPlan = $this->planWrittenInTurnZero([$this->item('First')]);
        $conversation = $this->conversation([$this->ownerText(0, 'Help me'), $this->assistantText(1, 'Gladly.'), $this->ownerText(2, 'Go on')], $priorPlan);

        [$resultText, $toolCall] = $this->applyUpdateTaskPlan($conversation, []);

        self::assertSame(
            "Task plan NOT updated — nothing changed. Fix every line below and send the whole plan again:\n"
                . "- 'items' is missing or not a list — send the plan as items: [...].\n"
                . "Your plan's keys: 1 'First'\n"
                . 'Send an existing item as {"key": "2.1"} (its text is fixed), a new item as {"text": "…"} without a key, a dropped item as {"key": "3", "remove": true}; an existing item sent without "subtasks" keeps its subtasks.',
            $resultText
        );
        self::assertSame(AIConversationMessage::STATUS_FAILED, $toolCall->status);
        self::assertSame(ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED, $toolCall->taskPlanCallOutcome);
        self::assertSame(2, $priorPlan->updatedAtTurnInitiatorMessageIndex);
        self::assertSame($priorPlan, $conversation->getTaskPlan());
    }
}
