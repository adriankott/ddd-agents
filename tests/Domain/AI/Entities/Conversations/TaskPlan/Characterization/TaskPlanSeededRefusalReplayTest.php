<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Probes\PurposeStubbingConversation;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization\TaskPlanHolidayPlanFixture as Holiday;

/**
 * The replay of a REFUSED rewrite of a seeded plan ({@see AIConversation::buildTaskPlanAsOfMessageIndex()}): a purpose
 * that seeds its plan refuses every `update_task_plan` before any parse ({@see UpdateTaskPlanTool::answerSeededPlanRewriteRefusal()})
 * and the call is stored COMPLETED with no flag — the live plan stays the seed. The replay goes by the recorded outcome:
 * a row stamped `refused`, and a row recorded before the field whose result is the platform's fixed refusal text
 * ({@see ToolCall::readTaskPlanCallOutcome()}), is skipped. Only a keyed call recorded applied is replayed: a full replace
 * recorded before keys is skipped as well, so the refused rewrite and an accepted one leave the same plan.
 */
class TaskPlanSeededRefusalReplayTest extends TaskPlanModuleItemTestCase
{
    /** The seeded plan of these tests: three steps, the middle one gated by `recordGoal`. */
    protected const array SEED = [
        ['text' => 'Identify the business'],
        ['text' => 'Record the goal', 'doneRequiresToolCall' => 'recordGoal'],
        ['text' => 'Deliver the handover'],
    ];

    protected const array SEED_TREE = [
        '1' => '[open] Identify the business',
        '2' => '[open] Record the goal {gate:recordGoal}',
        '3' => '[open] Deliver the handover',
    ];

    protected const array REWORDED_ITEMS = [
        ['text' => 'Identify the business and confirm its address', 'status' => 'done', 'statusNote' => 'confirmed'],
        ['text' => 'Record the goal', 'status' => 'open'],
        ['text' => 'Deliver the handover', 'status' => 'open'],
    ];

    protected function seededPurpose(): AIConversationPurpose
    {
        $aIConversationPurpose = self::instantiateWithoutConstructor(AIConversationPurpose::class);
        $aIConversationPurpose->seedTaskPlanItems = self::SEED;
        return $aIConversationPurpose;
    }

    /**
     * The stored row of an `update_task_plan` call the live loop refused on a seeded purpose: COMPLETED, the refusal
     * text as its result, no flag.
     *
     * @param array<int, array<string, mixed>> $items
     */
    protected function refusedSeededRewrite(int $index, array $items): AIConversationMessage
    {
        $refusal = UpdateTaskPlanTool::answerSeededPlanRewriteRefusal();
        self::assertFalse($refusal->failed, 'the seeded refusal is not a failure');
        $message = $this->completedCall($index, UpdateTaskPlanTool::NAME, ['items' => $items]);
        $message->toolCall->result = $refusal->text;
        return $message;
    }

    /** @param AIConversationMessage[] $messageList */
    protected function seededConversation(array $messageList): PurposeStubbingConversation
    {
        $conversation = self::instantiateWithoutConstructor(PurposeStubbingConversation::class);
        $conversation->messages = $this->messages($messageList);
        $conversation->stubbedPurpose = $this->seededPurpose();
        return $conversation;
    }

    /** @param array<int, array<string, mixed>> $refusedItems */
    protected function conversationWithARefusedRewrite(array $refusedItems): PurposeStubbingConversation
    {
        return $this->seededConversation([$this->ownerText(0, 'Hello, set up my profile.'), $this->refusedSeededRewrite(1, $refusedItems)]);
    }

    public function testTheStoredRefusalIsACompletedCallWithoutAFlagAndTheFixedText(): void
    {
        $storedCall = $this->refusedSeededRewrite(1, [['text' => 'x', 'status' => 'open']])->toolCall;

        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $storedCall->status);
        self::assertFalse($storedCall->wasRefusedAsDuplicate);
        self::assertFalse($storedCall->wasRefusedByGate);
        self::assertSame(
            'The task plan of this conversation is SEEDED by its purpose and is never rewritten: every item you need is'
                . ' already in "Your task plan" — work it top-down and report status with set_task_status (done / blocked /'
                . ' not_applicable with a note). Nothing was changed.',
            $storedCall->result
        );
    }

    public function testTheReplayBeforeTheRefusedCallIsTheSeed(): void
    {
        $conversation = $this->conversationWithARefusedRewrite([['text' => 'Something else', 'status' => 'open']]);

        self::assertSame(self::SEED_TREE, Holiday::describeTree($conversation->buildTaskPlanAsOfMessageIndex(0)));
    }

    /** @return array<string, array{0: array<int, array<string, mixed>>}> */
    public static function provideRefusedRewrites(): array
    {
        return [
            'a rewording of the same shape with a done the live loop never stored' => [self::REWORDED_ITEMS],
            'a restructure that keeps the gated step and drops the two others' => [[
                ['text' => 'Record the goal', 'status' => 'open'],
                ['text' => 'Find three new keywords', 'status' => 'open'],
            ]],
            'a rewrite that drops the gated step' => [[
                ['text' => 'Identify the business', 'status' => 'open'],
                ['text' => 'Deliver the handover', 'status' => 'open'],
            ]],
        ];
    }

    /** @param array<int, array<string, mixed>> $refusedItems */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideRefusedRewrites')]
    public function testTheReplaySkipsARefusedRewriteRecordedBeforeTheOutcomeField(array $refusedItems): void
    {
        $conversation = $this->conversationWithARefusedRewrite($refusedItems);

        self::assertSame(self::SEED_TREE, Holiday::describeTree($conversation->buildTaskPlanAsOfMessageIndex(1)));
    }

    protected const array KEYED_DONE_OF_THE_FIRST_STEP = [['key' => '1', 'status' => 'done', 'statusNote' => 'confirmed'], ['key' => '2'], ['key' => '3']];

    public function testAKeyedCallRecordedAppliedIsReplayed(): void
    {
        $appliedKeyedCall = $this->completedCall(1, UpdateTaskPlanTool::NAME, ['items' => self::KEYED_DONE_OF_THE_FIRST_STEP]);
        $appliedKeyedCall->toolCall->result = 'Task plan updated: 3 items (1 done, 2 open, 0 blocked).';
        $appliedKeyedCall->toolCall->taskPlanCallOutcome = ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED;
        $conversation = $this->seededConversation([$this->ownerText(0, 'Hello, set up my profile.'), $appliedKeyedCall]);

        self::assertSame(
            ['1' => '[done] Identify the business — confirmed', '2' => '[open] Record the goal {gate:recordGoal}', '3' => '[open] Deliver the handover'],
            Holiday::describeTree($conversation->buildTaskPlanAsOfMessageIndex(1))
        );
    }

    public function testTheSameKeyedCallStampedRefusedByTheLiveDispatchIsSkipped(): void
    {
        $refusedKeyedCall = $this->completedCall(1, UpdateTaskPlanTool::NAME, ['items' => self::KEYED_DONE_OF_THE_FIRST_STEP]);
        $refusedKeyedCall->toolCall->result = UpdateTaskPlanTool::SEEDED_PLAN_REWRITE_REFUSAL_TEXT;
        $refusedKeyedCall->toolCall->taskPlanCallOutcome = ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED;
        $conversation = $this->seededConversation([$this->ownerText(0, 'Hello, set up my profile.'), $refusedKeyedCall]);

        self::assertSame(self::SEED_TREE, Holiday::describeTree($conversation->buildTaskPlanAsOfMessageIndex(1)));
    }

    public function testAnAcceptedFullReplaceRecordedBeforeKeysIsNotReplayedEither(): void
    {
        // the replay no longer re-parses a full replace at all: the plan as of such a row is the plan before it
        $acceptedFullReplace = $this->completedCall(1, UpdateTaskPlanTool::NAME, ['items' => self::REWORDED_ITEMS]);
        $acceptedFullReplace->toolCall->result = 'Task plan updated: 3 items (1 done, 2 open, 0 blocked).';
        $conversation = $this->seededConversation([$this->ownerText(0, 'Hello, set up my profile.'), $acceptedFullReplace]);

        self::assertSame(ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_AS_FULL_REPLACE, $acceptedFullReplace->toolCall->readTaskPlanCallOutcome());
        self::assertSame(self::SEED_TREE, Holiday::describeTree($conversation->buildTaskPlanAsOfMessageIndex(1)));
    }

    /** @return array<string, array{0: string, 1: string, 2: ?string, 3: bool, 4: ?string, 5: ?string}> */
    public static function provideStoredCallRows(): array
    {
        $refusalText = UpdateTaskPlanTool::SEEDED_PLAN_REWRITE_REFUSAL_TEXT;
        return [
            'the seeded refusal text, completed' => [UpdateTaskPlanTool::NAME, AIConversationMessage::STATUS_COMPLETED, $refusalText, false, null, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED],
            'another text, completed' => [UpdateTaskPlanTool::NAME, AIConversationMessage::STATUS_COMPLETED, 'Task plan updated: 1 items (0 done, 1 open, 0 blocked).', false, null, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_AS_FULL_REPLACE],
            'a failed call' => [UpdateTaskPlanTool::NAME, AIConversationMessage::STATUS_FAILED, 'Task plan NOT updated', false, null, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED],
            'a failed call wins over the duplicate flag' => [UpdateTaskPlanTool::NAME, AIConversationMessage::STATUS_FAILED, 'Task plan NOT updated', true, null, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED],
            'a duplicate resend' => [UpdateTaskPlanTool::NAME, AIConversationMessage::STATUS_COMPLETED, 'Task plan NOT updated', true, null, ToolCall::TASK_PLAN_CALL_OUTCOME_UNCHANGED],
            'a call that never resolved' => [UpdateTaskPlanTool::NAME, AIConversationMessage::STATUS_PENDING, null, false, null, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED],
            'a stamped outcome wins over the text' => [UpdateTaskPlanTool::NAME, AIConversationMessage::STATUS_COMPLETED, $refusalText, false, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED],
            'another tool' => ['set_task_status', AIConversationMessage::STATUS_COMPLETED, $refusalText, false, null, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideStoredCallRows')]
    public function testTheOutcomeReaderOfAStoredRow(string $toolName, string $status, ?string $result, bool $wasRefusedAsDuplicate, ?string $storedOutcome, ?string $expectedOutcome): void
    {
        $toolCall = $this->completedCall(1, $toolName)->toolCall;
        $toolCall->status = $status;
        $toolCall->result = $result;
        $toolCall->wasRefusedAsDuplicate = $wasRefusedAsDuplicate;
        $toolCall->taskPlanCallOutcome = $storedOutcome;

        self::assertSame($expectedOutcome, $toolCall->readTaskPlanCallOutcome());
    }
}
