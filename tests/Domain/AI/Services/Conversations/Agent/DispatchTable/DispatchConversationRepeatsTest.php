<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The branches of {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService::dispatchToolCall()} that
 * answer a call from the conversation's earlier calls instead of running it: a read that already failed twice, a read the
 * turn already answered (the notice with and without a task plan), the identical write (the tools stay after the first
 * refusal of the turn), and a tool whose skill is not loaded. A write whose identical repeat does new work runs again.
 */
class DispatchConversationRepeatsTest extends UnitTestCase
{
    use DispatchTableFixtures;

    protected const string READ_NOTICE = 'YOU ALREADY CALLED `getNotes` WITH EXACTLY THESE ARGUMENTS A MOMENT AGO — here is the same result '
        . 'again (nothing changed it; it was not fetched anew). Act on it now; do not call it a third '
        . 'time. Only a write that changes this data makes a re-read worthwhile.';

    protected const string IDENTICAL_WRITE_REFUSAL = 'NOT executed: you already persisted exactly this with `addNote` earlier in this conversation '
        . '(message %d) — repeating a write with identical arguments does no new work. Nothing failed '
        . 'and nothing is unavailable — the work is ALREADY DONE; NEVER tell the user you lack access '
        . 'or could not do it. If the owner '
        . 'asked for a CHANGE, this repeat did NOT apply it: make a fresh call whose arguments contain '
        . 'the CHANGED content. If nothing was to change, the work IS done: the platform closes the '
        . 'matching plan item from the record; '
        . 'continue with the NEXT open item — or ask the owner if you are missing input for it.';

    /** A tool of the package's skill `Agent.Mandates`, called while that skill is not loaded */
    protected const string TOOL_OF_AN_UNLOADED_SKILL = 'listMandates';

    protected function setUp(): void
    {
        parent::setUp();
        $this->addNeutralToolEffectsToTheCatalog();
    }

    protected function tearDown(): void
    {
        $this->restoreTheEffectCatalog();
        parent::tearDown();
    }

    public function testAReadThatFailedTwiceIsNotRunAgain(): void
    {
        $arguments = ['folder' => 7];
        $conversation = $this->conversation();
        $this->appendEarlierCall($conversation, self::REUSABLE_READ_TOOL, $arguments, AIConversationMessage::STATUS_FAILED, 'first failure', 'call-a', true);
        $this->appendOwnerRow($conversation, 'Try again.');
        $this->appendEarlierCall($conversation, self::REUSABLE_READ_TOOL, $arguments, AIConversationMessage::STATUS_FAILED, ' storage timeout ', 'call-b', true);
        $callRow = $this->appendCallRow($conversation, self::REUSABLE_READ_TOOL, $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            'NOT executed: `getNotes` with these exact arguments already FAILED 2 times in this conversation — an identical attempt fails '
                . 'the same way. Last failure: storage timeout — Continue with the facts already on file (never tell the owner the system '
                . 'is broken); only DIFFERENT arguments can succeed.',
            AIConversationMessage::STATUS_FAILED,
            self::NO_LOOP_FLAGS,
            true
        );
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    public function testAReadTheTurnAnsweredGetsTheNoticeAndTheEarlierResultAgain(): void
    {
        $arguments = ['folder' => 7];
        $conversation = $this->conversation();
        $this->appendEarlierCall($conversation, self::REUSABLE_READ_TOOL, $arguments, AIConversationMessage::STATUS_COMPLETED, "  note list \n", 'call-a', true);
        $callRow = $this->appendCallRow($conversation, self::REUSABLE_READ_TOOL, $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, self::READ_NOTICE . "\n\nnote list", AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS, true);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    public function testWithATaskPlanTheNoticeNamesTheNextOwedItemAndItsGate(): void
    {
        $arguments = ['folder' => 7];
        $conversation = $this->conversation();
        $this->attachTaskPlan($conversation, [
            ['key' => '1', 'text' => 'Save the note the owner confirmed', 'status' => 'open', 'doneRequiresToolCall' => self::WRITE_TOOL],
        ]);
        $this->appendEarlierCall($conversation, self::REUSABLE_READ_TOOL, $arguments, AIConversationMessage::STATUS_COMPLETED, 'note list', 'call-a', true);
        $callRow = $this->appendCallRow($conversation, self::REUSABLE_READ_TOOL, $arguments, 'call-current');

        $loopFlags = $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            self::READ_NOTICE
                . ' The plan\'s next owed item is 1 "Save the note the owner confirmed" — do what it says now.'
                . ' Its later gate is `addNote`: run that write only once the owner has given what it needs (their nod, their answer) — never before they did.'
                . "\n\nnote list",
            AIConversationMessage::STATUS_COMPLETED,
            self::NO_LOOP_FLAGS,
            true
        );
    }

    /** The write ran in an earlier turn: the first refusal of this turn keeps the tools, the second answers without them. */
    public function testTheSecondRefusedIdenticalWriteOfTheTurnAnswersWithoutTools(): void
    {
        $arguments = ['text' => 'Call the supplier on Monday'];
        $conversation = $this->conversation();
        $earlierWriteRow = $this->appendEarlierCall($conversation, self::WRITE_TOOL, $arguments, AIConversationMessage::STATUS_COMPLETED, 'saved', 'call-a', true);
        $this->appendOwnerRow($conversation, 'And again.');
        $firstCallRow = $this->appendCallRow($conversation, self::WRITE_TOOL, $arguments, 'call-first');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $firstLoopFlags = $this->dispatch($dispatchService, $conversation, $firstCallRow);
        $secondCallRow = $this->appendCallRow($conversation, self::WRITE_TOOL, $arguments, 'call-second');
        $secondLoopFlags = $this->dispatch($dispatchService, $conversation, $secondCallRow);

        $expectedRefusal = sprintf(self::IDENTICAL_WRITE_REFUSAL, $earlierWriteRow->indexInConversation);
        self::assertCallOutcome($firstCallRow, $firstLoopFlags, $expectedRefusal, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS, true);
        self::assertCallOutcome($secondCallRow, $secondLoopFlags, $expectedRefusal, AIConversationMessage::STATUS_COMPLETED, self::TOOL_LESS_STEP_ONLY, true);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    /** The write the call repeats ran in this same turn: its first refusal keeps the tools too. */
    public function testTheFirstRefusalKeepsTheToolsAlsoWhenTheWriteRanInThisTurn(): void
    {
        $arguments = ['text' => 'Call the supplier on Monday'];
        $conversation = $this->conversation();
        $earlierWriteRow = $this->appendEarlierCall($conversation, self::WRITE_TOOL, $arguments, AIConversationMessage::STATUS_COMPLETED, 'saved', 'call-a', true);
        $callRow = $this->appendCallRow($conversation, self::WRITE_TOOL, $arguments, 'call-current');

        $loopFlags = $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, sprintf(self::IDENTICAL_WRITE_REFUSAL, $earlierWriteRow->indexInConversation), AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS, true);
    }

    /** A write whose identical arguments act on what is current now (the call running now) runs again in a later turn. */
    public function testAWriteWhoseIdenticalRepeatDoesNewWorkRunsAgain(): void
    {
        $conversation = $this->conversation();
        $this->appendEarlierCall($conversation, self::WRITE_DOING_NEW_WORK_ON_REPEAT_TOOL, [], AIConversationMessage::STATUS_COMPLETED, 'ends after goodbye', 'call-a', true);
        $this->appendOwnerRow($conversation, 'Just close the call.');
        $callRow = $this->appendCallRow($conversation, self::WRITE_DOING_NEW_WORK_ON_REPEAT_TOOL, [], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertSame([self::WRITE_DOING_NEW_WORK_ON_REPEAT_TOOL], $dispatchService->executedToolNames);
        self::assertCallOutcome($callRow, $loopFlags, 'result:' . self::WRITE_DOING_NEW_WORK_ON_REPEAT_TOOL, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
    }

    /** Loading by system-prompt swap: the tool is not listed until its skill is loaded. */
    public function testAToolWhoseSkillIsNotLoadedIsRefusedByAGateThatNamesTheSkill(): void
    {
        $conversation = $this->conversation();
        $contextRetentionSettings = new ContextRetentionSettings();
        $contextRetentionSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP;
        $conversation->contextRetentionSettings = $contextRetentionSettings;
        $callRow = $this->appendCallRow($conversation, self::TOOL_OF_AN_UNLOADED_SKILL, [], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            'Tool `listMandates` is not available yet — it belongs to the skill `Agent.Mandates`, which is NOT loaded. Call `load_skills` '
                . 'with [\'Agent.Mandates\'] FIRST, then call `listMandates` again. Do NOT retry `listMandates` before loading the skill '
                . '(without the skill the tool cannot run — this is NOT an argument-shape problem).',
            AIConversationMessage::STATUS_FAILED,
            self::NO_LOOP_FLAGS,
            false,
            true
        );
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    /** Append-only loading lists the tool from the start: the refusal says the tool is listed and its skill is not loaded. */
    public function testUnderAppendOnlyLoadingTheRefusalSaysTheToolIsListed(): void
    {
        $conversation = $this->conversation();
        $contextRetentionSettings = new ContextRetentionSettings();
        $contextRetentionSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $conversation->contextRetentionSettings = $contextRetentionSettings;
        $callRow = $this->appendCallRow($conversation, self::TOOL_OF_AN_UNLOADED_SKILL, [], 'call-current');

        $loopFlags = $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            'Not executed: `listMandates` is in your tool list, but its skill `Agent.Mandates` is not loaded yet — the skill\'s instructions '
                . 'say when and how to use the tool. Call `load_skills` with [\'Agent.Mandates\'] first, then call `listMandates` again with '
                . 'the same arguments (nothing is wrong with them).',
            AIConversationMessage::STATUS_FAILED,
            self::NO_LOOP_FLAGS,
            false,
            true
        );
    }
}
