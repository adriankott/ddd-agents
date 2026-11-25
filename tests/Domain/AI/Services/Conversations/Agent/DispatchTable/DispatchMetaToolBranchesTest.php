<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\AIConversationOrigin;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationStatusReport;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Widgets\QuickRepliesConversationWidget;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * The branches of {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService::dispatchToolCall()} that
 * answer a meta tool: `delegate` (the refusal under a seeded plan, the spawn, its catch arm, the fall-through without
 * threads), `check_delegations`, `get_task_plans`, `load_tool`, the two plan tools (the refusal under a seeded plan, the
 * application, the plan outcome of a call that threw), `askOwner` and `send_owner_report`. Each case asserts what the
 * dispatch stored on the call, its status, the flags for the loop and which service behind the dispatch it reached.
 */
class DispatchMetaToolBranchesTest extends UnitTestCase
{
    use DispatchTableFixtures;

    protected const string DELEGATE_REFUSED_UNDER_A_SEEDED_PLAN = 'Not delegated: in this conversation the platform already runs the background work and the '
        . 'acts are your own mid-dialogue work. Continue with the next plan item yourself; delegate only a self-contained package '
        . 'of the closing act.';

    protected const string PLAN_REWRITE_REFUSED_UNDER_A_SEEDED_PLAN = 'The task plan of this conversation is SEEDED by its purpose and is never rewritten: every item you '
        . 'need is already in "Your task plan" — work it top-down and report status with set_task_status (done / '
        . 'blocked / not_applicable with a note). Nothing was changed.';

    protected const string BUDGET_EXCEEDED_RESULT = 'AI_BUDGET_REACHED: the account\'s monthly AI budget is used up — this tool cannot run. Do NOT retry this or any '
        . 'other AI-consuming tool. Tell the user plainly that the monthly AI budget is reached and that it '
        . 'resets at the start of next month.';

    protected const string CLOSING_STEP_KEY = 'handover';

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

    /** A conversation under a purpose that seeds its task plan and closes with the step {@see CLOSING_STEP_KEY}. */
    protected function conversationUnderASeededPlanPurpose(): DispatchTableConversation
    {
        $purpose = self::instantiateWithoutConstructor(DispatchTablePurposeDouble::class);
        $purpose->seedsItsTaskPlan = true;
        $purpose->closingProgressStepKey = self::CLOSING_STEP_KEY;
        $conversation = $this->conversation();
        $conversation->stubbedPurpose = $purpose;
        return $conversation;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // delegate
    // ---------------------------------------------------------------------------------------------------------------

    public function testDelegateUnderASeededPlanIsRefusedAndTheRefusalIsStored(): void
    {
        $arguments = ['task' => 'research the competitors'];
        $conversation = $this->conversationUnderASeededPlanPurpose();
        $callRow = $this->appendCallRow($conversation, 'delegate', $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow, allowThreads: true);

        self::assertCallOutcome($callRow, $loopFlags, self::DELEGATE_REFUSED_UNDER_A_SEEDED_PLAN, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
        self::assertNull($callRow->toolCall->taskPlanCallOutcome, 'a delegate call records no plan outcome');
        self::assertSame($arguments, $callRow->toolCall->arguments);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    public function testDelegateUnderASeededPlanWhoseNextItemIsInTheClosingActRunsTheSpawn(): void
    {
        $arguments = ['task' => 'prepare the handover summary'];
        $conversation = $this->conversationUnderASeededPlanPurpose();
        $this->attachTaskPlan($conversation, [
            ['key' => '1', 'text' => 'Hand the work over', 'status' => 'open', 'customerProgressStepKey' => self::CLOSING_STEP_KEY],
        ]);
        $callRow = $this->appendCallRow($conversation, 'delegate', $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow, allowThreads: true);

        self::assertSame([['arguments' => $arguments, 'async' => false, 'modelNameOverride' => null]], $dispatchService->delegationSpawnServiceDouble->calls);
        self::assertCallOutcome($callRow, $loopFlags, DispatchTableDelegationSpawnServiceDouble::SPAWN_ANSWER, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
    }

    public function testDelegateUnderASeededPlanWithoutThreadsReachesTheExecution(): void
    {
        $conversation = $this->conversationUnderASeededPlanPurpose();
        $callRow = $this->appendCallRow($conversation, 'delegate', ['task' => 'a'], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->nextToolResult = new ToolResult("Tool 'delegate' not found in this domain", true);

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertSame(['delegate'], $dispatchService->executedToolNames);
        self::assertSame([], $dispatchService->delegationSpawnServiceDouble->calls);
        self::assertCallOutcome($callRow, $loopFlags, "Tool 'delegate' not found in this domain", AIConversationMessage::STATUS_FAILED, self::NO_LOOP_FLAGS);
    }

    /** @return array<string, array{0: bool, 1: string|null, 2: string}> async, the status the spawn leaves, the expected status */
    public static function spawnedDelegations(): array
    {
        return [
            'sync, started' => [false, AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_COMPLETED],
            'async, started' => [true, AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_COMPLETED],
            'async, refused by the spawn' => [true, AIConversationMessage::STATUS_FAILED, AIConversationMessage::STATUS_FAILED],
        ];
    }

    #[DataProvider('spawnedDelegations')]
    public function testADelegationStoresTheAnswerOfTheSpawnAndNeverParksTheLoop(bool $async, ?string $statusLeftBySpawn, string $expectedStatus): void
    {
        $arguments = ['task' => 'research the competitors', 'skill_names' => ['Agent.Web.Search']];
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'delegate', $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->delegationSpawnServiceDouble->statusLeftOnTheCall = $statusLeftBySpawn;

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow, allowThreads: true, asyncDelegations: $async);

        self::assertSame([['arguments' => $arguments, 'async' => $async, 'modelNameOverride' => null]], $dispatchService->delegationSpawnServiceDouble->calls);
        self::assertSame([], $dispatchService->executedToolNames);
        self::assertCallOutcome($callRow, $loopFlags, DispatchTableDelegationSpawnServiceDouble::SPAWN_ANSWER, $expectedStatus, self::NO_LOOP_FLAGS);
    }

    public function testAThrowingSpawnIsAFailedCallWhoseErrorIsStoredAndLogged(): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'delegate', ['task' => 'a'], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->delegationSpawnServiceDouble->throwOnSpawn = new RuntimeException('spawn failed');

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow, allowThreads: true);

        self::assertCallOutcome($callRow, $loopFlags, 'Tool error: spawn failed', AIConversationMessage::STATUS_FAILED, self::NO_LOOP_FLAGS);
        self::assertSame([['toolName' => 'delegate', 'error' => 'Tool error: spawn failed', 'exceptionClass' => RuntimeException::class]], $dispatchService->loggedFailures);
        self::assertNull($callRow->toolCall->taskPlanCallOutcome);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // check_delegations, get_task_plans, load_tool
    // ---------------------------------------------------------------------------------------------------------------

    public function testCheckDelegationsAnswersTheReportOfTheLoadedRows(): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'check_delegations', [], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);
        self::assertCallOutcome($callRow, $loopFlags, 'No sub-agents in this conversation yet.', AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);

        $conversationWithADelegation = $this->conversation();
        $this->appendEarlierCall($conversationWithADelegation, 'delegate', ['tasks' => [['task' => 'research'], ['task' => 'write']]], AIConversationMessage::STATUS_RUNNING, null, 'delegate-1');
        $pollRow = $this->appendCallRow($conversationWithADelegation, 'check_delegations', [], 'call-current');
        $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversationWithADelegation, $pollRow);
        self::assertSame(
            "Earlier delegations (before sub-agent numbers): 2 sub-agents, 0 with a result.\n" . DelegationStatusReport::CLOSING_LINE,
            $pollRow->toolCall->result
        );
    }

    public function testGetTaskPlansWithoutAFinishedPlan(): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'get_task_plans', ['planNumber' => 1], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, 'No finished task plan exists in this conversation yet.', AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    /**
     * `load_skills` loads through the gates on the call's row; the call keeps the arguments the model sent (the gates
     * narrow a copy), and the dispatch records whether the call loaded nothing and whether it named a name that is no skill.
     */
    public function testLoadSkillsLoadsOnTheCallRowAndKeepsTheArgumentsAsSent(): void
    {
        $arguments = ['skill_names' => ['Agent.Mandates', 'Agent.Mandatez']];
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'load_skills', $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            'Skills loaded; their tools are now available. NOT loaded: Agent.Mandatez is no skill you can load here. Use a name exactly as '
                . '"Available skills" or the sub-skill list of a loaded skill states it.',
            AIConversationMessage::STATUS_COMPLETED,
            self::NO_LOOP_FLAGS
        );
        self::assertSame($arguments, $callRow->toolCall->arguments, 'the call keeps what the model sent');
        self::assertSame(['Agent.Mandates'], $callRow->getLoadedCapabilities()?->getLoadedSkillNames());
        self::assertFalse($callRow->toolCall->loadSkillsCallLoadedNothing);
        self::assertTrue($callRow->toolCall->loadSkillsCallNamedNoSkill);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);

        $repeatConversation = $this->conversation();
        $earlierLoadRow = $this->appendEarlierCall($repeatConversation, 'load_skills', ['skill_names' => ['Agent.Mandates']], AIConversationMessage::STATUS_COMPLETED, 'loaded', 'call-a');
        $earlierLoadRow->loadSkills('Agent.Mandates');
        $this->appendOwnerRow($repeatConversation, 'And the mandates again.');
        $repeatRow = $this->appendCallRow($repeatConversation, 'load_skills', ['skill_names' => ['Agent.Mandates']], 'call-current');
        $repeatRow->loadSkills('Agent.Mandates');
        $this->dispatch(new DispatchTableDispatchServiceDouble(), $repeatConversation, $repeatRow);
        self::assertSame(
            'Already loaded: Agent.Mandates — the tools are ALREADY in your tool list (grantMandate, listMandates, revokeMandate). Call them '
                . 'directly NOW; never call load_skills for an already-loaded skill.',
            $repeatRow->toolCall->result
        );
        self::assertTrue($repeatRow->toolCall->loadSkillsCallLoadedNothing, 'an already loaded skill changes nothing');
        self::assertFalse($repeatRow->toolCall->loadSkillsCallNamedNoSkill);
    }

    /** A name that differs from a skill only in its dots or its case loads that skill; the call keeps the name it sent. */
    public function testLoadSkillsCorrectsTheCaseOfANameAndKeepsTheNameAsSent(): void
    {
        $arguments = ['skill_names' => ['agent.mandates']];
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'load_skills', $arguments, 'call-current');

        $loopFlags = $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            'Loaded Agent.Mandates (you wrote "agent.mandates"). Skills loaded; their tools are now available.',
            AIConversationMessage::STATUS_COMPLETED,
            self::NO_LOOP_FLAGS
        );
        self::assertSame($arguments, $callRow->toolCall->arguments, 'the call keeps what the model sent');
        self::assertSame(['Agent.Mandates'], $callRow->getLoadedCapabilities()?->getLoadedSkillNames());
        self::assertFalse($callRow->toolCall->loadSkillsCallNamedNoSkill, 'a corrected name names a skill');
    }

    public function testLoadToolOfAnUnknownToolLoadsNothingAndKeepsItsArguments(): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'load_tool', ['tool_names' => ['noSuchTool']], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            'Nothing was loaded: noSuchTool is no tool you can load here. The tools you can load are the ones this tool\'s description lists.',
            AIConversationMessage::STATUS_COMPLETED,
            self::NO_LOOP_FLAGS
        );
        self::assertSame(['tool_names' => ['noSuchTool']], $callRow->toolCall->arguments);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // the plan tools
    // ---------------------------------------------------------------------------------------------------------------

    public function testAPlanRewriteUnderASeededPlanIsRefusedAsRefused(): void
    {
        $conversation = $this->conversationUnderASeededPlanPurpose();
        $this->attachTaskPlan($conversation, [['key' => '1', 'text' => 'Save the note the owner confirmed', 'status' => 'open']]);
        $callRow = $this->appendCallRow($conversation, 'update_task_plan', ['items' => [['text' => 'x']]], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, self::PLAN_REWRITE_REFUSED_UNDER_A_SEEDED_PLAN, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
        self::assertSame(ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED, $callRow->toolCall->taskPlanCallOutcome);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    /** The refusal needs a plan; a conversation without a seeding purpose is never refused. */
    public function testAPlanRewriteIsAppliedWithoutAPlanOrWithoutASeedingPurpose(): void
    {
        $arguments = ['items' => [['text' => 'x']]];
        $seededWithoutPlan = $this->conversationUnderASeededPlanPurpose();
        $notSeededWithPlan = $this->conversation();
        $this->attachTaskPlan($notSeededWithPlan, [['key' => '1', 'text' => 'Save the note the owner confirmed', 'status' => 'open']]);
        foreach (['seeded, no plan' => $seededWithoutPlan, 'not seeded, a plan' => $notSeededWithPlan] as $case => $conversation) {
            $callRow = $this->appendCallRow($conversation, 'update_task_plan', $arguments, 'call-current');
            $dispatchService = new DispatchTableDispatchServiceDouble();

            $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

            self::assertSame([['method' => 'applyUpdateTaskPlanCall', 'arguments' => $arguments]], $dispatchService->planRecordServiceDouble->calls, $case);
            self::assertCallOutcome($callRow, $loopFlags, DispatchTablePlanRecordServiceDouble::UPDATE_TASK_PLAN_ANSWER, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
            self::assertNull($callRow->toolCall->taskPlanCallOutcome, $case);
        }
    }

    public function testSetTaskStatusIsAppliedAndItsToolLessStepReachesTheLoop(): void
    {
        $arguments = ['updates' => [['item' => '1', 'status' => 'done']]];
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'set_task_status', $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);
        self::assertSame([['method' => 'applySetTaskStatusCall', 'arguments' => $arguments]], $dispatchService->planRecordServiceDouble->calls);
        self::assertCallOutcome($callRow, $loopFlags, DispatchTablePlanRecordServiceDouble::SET_TASK_STATUS_ANSWER, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);

        $askingConversation = $this->conversation();
        $askingCallRow = $this->appendCallRow($askingConversation, 'set_task_status', $arguments, 'call-current');
        $askingDispatchService = new DispatchTableDispatchServiceDouble();
        $askingDispatchService->planRecordServiceDouble->setTaskStatusAsksForToolLessStep = true;
        self::assertSame(self::TOOL_LESS_STEP_ONLY, $this->dispatch($askingDispatchService, $askingConversation, $askingCallRow));
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: Throwable, 3: string, 4: string|null, 5: string}>
     *         the tool, its arguments, what the application throws, the stored result, the outcome recorded before the
     *         throw, the expected outcome
     */
    public static function throwingPlanCalls(): array
    {
        $planArguments = ['items' => [['text' => 'x']]];
        $tickArguments = ['updates' => [['item' => '1', 'status' => 'done']]];
        return [
            'update_task_plan, any error' => ['update_task_plan', $planArguments, new RuntimeException('plan write failed'), 'Tool error: plan write failed', null, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED],
            'update_task_plan, the budget' => ['update_task_plan', $planArguments, new AiBudgetExceededException('budget'), self::BUDGET_EXCEEDED_RESULT, null, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED],
            'set_task_status, any error' => ['set_task_status', $tickArguments, new RuntimeException('tick failed'), 'Tool error: tick failed', null, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED],
            'set_task_status, the budget' => ['set_task_status', $tickArguments, new AiBudgetExceededException('budget'), self::BUDGET_EXCEEDED_RESULT, null, ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED],
            'update_task_plan, the outcome decided before the throw stays' => ['update_task_plan', $planArguments, new RuntimeException('persist failed'), 'Tool error: persist failed', ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED],
            'set_task_status, the outcome decided before the throw stays' => ['set_task_status', $tickArguments, new AiBudgetExceededException('budget'), self::BUDGET_EXCEEDED_RESULT, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED, ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('throwingPlanCalls')]
    public function testAPlanCallWhoseApplicationThrewIsRefusedUnlessItsOutcomeWasDecided(string $toolName, array $arguments, Throwable $thrown, string $expectedResult, ?string $outcomeBeforeThrow, string $expectedOutcome): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, $toolName, $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->planRecordServiceDouble->throwOnApply = $thrown;
        $dispatchService->planRecordServiceDouble->taskPlanCallOutcomeBeforeThrow = $outcomeBeforeThrow;

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCount(1, $dispatchService->planRecordServiceDouble->calls);
        self::assertCallOutcome($callRow, $loopFlags, $expectedResult, AIConversationMessage::STATUS_FAILED, self::NO_LOOP_FLAGS);
        self::assertSame($expectedOutcome, $callRow->toolCall->taskPlanCallOutcome);
        self::assertSame([['toolName' => $toolName, 'error' => $expectedResult, 'exceptionClass' => $thrown::class]], $dispatchService->loggedFailures);
    }

    public function testAThrowingCallOfAnotherToolRecordsNoPlanOutcome(): void
    {
        foreach ([new RuntimeException('boom'), new AiBudgetExceededException('budget')] as $thrown) {
            $conversation = $this->conversation();
            $callRow = $this->appendCallRow($conversation, self::PLAIN_TOOL, ['n' => 1], 'call-current');
            $dispatchService = new DispatchTableDispatchServiceDouble();
            $dispatchService->throwOnExecute = $thrown;

            $this->dispatch($dispatchService, $conversation, $callRow);

            self::assertSame(AIConversationMessage::STATUS_FAILED, $callRow->toolCall->status, $thrown::class);
            self::assertNull($callRow->toolCall->taskPlanCallOutcome, $thrown::class);
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // askOwner
    // ---------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> a valid question with two options */
    protected static function validQuestion(): array
    {
        return ['question' => 'Which opening hours apply?', 'options' => [['label' => 'Weekdays only'], ['label' => 'Every day']]];
    }

    public function testAMalformedQuestionIsNotRecorded(): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, AskOwnerTool::NAME, ['question' => '', 'options' => [['label' => 'Only one']]], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            "Question NOT recorded: 'question' is required — the owner-facing question in the reply language. Pass 2–4 options (got 1).",
            AIConversationMessage::STATUS_FAILED,
            self::NO_LOOP_FLAGS
        );
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    public function testASecondQuestionInTheTurnIsRefusedAndTheNextStepHasNoTools(): void
    {
        $conversation = $this->conversation();
        $this->appendEarlierCall($conversation, AskOwnerTool::NAME, ['question' => 'Earlier?', 'options' => [['label' => 'A'], ['label' => 'B']]], AIConversationMessage::STATUS_COMPLETED, AskOwnerTool::RESULT_RECORDED, 'ask-1');
        $callRow = $this->appendCallRow($conversation, AskOwnerTool::NAME, self::validQuestion(), 'call-current');

        $loopFlags = $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            'Already asked a question this turn — nothing changes. ONE askOwner per turn: end your turn on the question you asked.',
            AIConversationMessage::STATUS_FAILED,
            self::TOOL_LESS_STEP_ONLY
        );
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool, 2: string}> the arguments, the owner on the phone line, the expected result */
    public static function recordedQuestions(): array
    {
        return [
            'on a screen' => [self::validQuestion(), false, AskOwnerTool::RESULT_RECORDED],
            'on the phone line' => [self::validQuestion(), true, AskOwnerTool::RESULT_RECORDED_ON_THE_PHONE_LINE],
            'a draft approval adds its note' => [
                ['question' => 'Shall I save this draft?', 'options' => [['label' => 'Yes, save it'], ['label' => 'Change it']], 'questionKey' => 'draftApproval', 'optionMeanings' => ['approved', 'change']],
                false,
                AskOwnerTool::RESULT_RECORDED . ' ' . AskOwnerTool::RESULT_DRAFT_APPROVAL_NOTE,
            ],
            'a dropped catalogue key is named' => [
                ['question' => 'Shall I save this draft?', 'options' => [['label' => 'Yes, save it'], ['label' => 'Change it']], 'questionKey' => 'draftApproval', 'optionMeanings' => ['approved']],
                false,
                AskOwnerTool::RESULT_RECORDED . ' Recorded as a FREE question — the catalogue key draftApproval was DROPPED: \'optionMeanings\' must carry one meaning per option, in the same order (2 options, 1 meanings); the meanings of draftApproval: approved, change.'
                    . ' A choice the platform does not know (names, variants, wording) is a free question: omit questionKey and optionMeanings next time; nothing is recorded from the click, the owner\'s answer arrives as their words.',
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('recordedQuestions')]
    public function testARecordedQuestionIsStoredAndTheNextStepHasNoTools(array $arguments, bool $ownerOnThePhoneLine, string $expectedResult): void
    {
        $conversation = $this->conversation();
        if ($ownerOnThePhoneLine) {
            $conversation->simulateLiveAnsweredCall(AIConversationOrigin::LIVE_CALL_CHANNEL_PHONE);
        }
        $callRow = $this->appendCallRow($conversation, AskOwnerTool::NAME, $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, $expectedResult, AIConversationMessage::STATUS_COMPLETED, self::TOOL_LESS_STEP_ONLY);
        self::assertSame($arguments, $callRow->toolCall->arguments, 'the question stays on the call as asked');
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    public function testAQuestionAfterTheOnlyReadThatDictatesABlockNamesTheBlock(): void
    {
        $conversation = $this->conversation();
        $readRow = $this->appendEarlierCall($conversation, self::REUSABLE_READ_TOOL, ['folder' => 7], AIConversationMessage::STATUS_COMPLETED, 'Notes …', 'read-1');
        $readRow->toolCall->dictatedWidgetType = 'NotesOverview';
        $callRow = $this->appendCallRow($conversation, AskOwnerTool::NAME, self::validQuestion(), 'call-current');
        $loopFlags = $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversation, $callRow);
        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            'Recorded — the platform renders your question WITH its options as a widget under your final text. Your final text carries what is new '
                . '(results, the draft, and the `NotesOverview` block the `getNotes` result dictates — the question widget shows under it); do not '
                . 'repeat the question in it. END YOUR TURN; the owner picks an option or types. An option you offer you FOLLOW THROUGH when it is '
                . 'picked — never offer what you would refuse.',
            AIConversationMessage::STATUS_COMPLETED,
            self::TOOL_LESS_STEP_ONLY
        );

        $conversationWithTwoReads = $this->conversation();
        $dictatingReadRow = $this->appendEarlierCall($conversationWithTwoReads, self::REUSABLE_READ_TOOL, ['folder' => 7], AIConversationMessage::STATUS_COMPLETED, 'Notes …', 'read-1');
        $dictatingReadRow->toolCall->dictatedWidgetType = 'NotesOverview';
        $this->appendEarlierCall($conversationWithTwoReads, self::REUSABLE_READ_TOOL, ['folder' => 8], AIConversationMessage::STATUS_COMPLETED, 'More notes …', 'read-2');
        $secondCallRow = $this->appendCallRow($conversationWithTwoReads, AskOwnerTool::NAME, self::validQuestion(), 'call-current');
        $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversationWithTwoReads, $secondCallRow);
        self::assertSame(AskOwnerTool::RESULT_RECORDED, $secondCallRow->toolCall->result);
    }

    public function testAQuestionInADelegationChildDictatesItsWidgetIntoTheResult(): void
    {
        $conversation = $this->conversation();
        $conversation->rootConversationId = 910000;
        $callRow = $this->appendCallRow($conversation, AskOwnerTool::NAME, self::validQuestion(), 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        $quickRepliesWidget = QuickRepliesConversationWidget::fromAskOwnerQuestion(AskOwnerTool::checkArguments(self::validQuestion())->question);
        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            'Recorded. This call runs in a delegated sub-task — nothing was written into the owner\'s chat. Put the '
                . "block below into your result for the main conversation to show, after one short prose line:\n"
                . $quickRepliesWidget->buildWidgetBlock(),
            AIConversationMessage::STATUS_COMPLETED,
            self::TOOL_LESS_STEP_ONLY
        );
        self::assertStringContainsString('"type":"QuickReplies"', (string)$callRow->toolCall->result);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    public function testADraftApprovalNoteInADelegationChildStartsAfterTheBlock(): void
    {
        $conversation = $this->conversation();
        $conversation->rootConversationId = 910000;
        $callRow = $this->appendCallRow($conversation, AskOwnerTool::NAME, ['question' => 'Shall I save this draft?', 'options' => [['label' => 'Yes, save it'], ['label' => 'Change it']], 'questionKey' => 'draftApproval', 'optionMeanings' => ['approved', 'change']], 'call-current');

        $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversation, $callRow);

        self::assertStringEndsWith("\n```\n\n" . AskOwnerTool::RESULT_DRAFT_APPROVAL_NOTE, (string)$callRow->toolCall->result);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // send_owner_report
    // ---------------------------------------------------------------------------------------------------------------

    public function testAnInvalidReportCallIsNotAcceptedAndKeepsItsArguments(): void
    {
        $arguments = ['outcome' => 'REPORT', 'subject' => '', 'body' => 'x'];
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'send_owner_report', $arguments, 'call-current');

        $loopFlags = $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversation, $callRow);

        self::assertCallOutcome(
            $callRow,
            $loopFlags,
            "Not accepted: 'subject' is missing or empty — REPORT needs one short email subject line. Supply only outcome and subject. "
                . 'The report is your next message after this call; the platform selects that message and the configured recipient.',
            AIConversationMessage::STATUS_FAILED,
            self::NO_LOOP_FLAGS
        );
        self::assertSame($arguments, $callRow->toolCall->arguments);
    }

    public function testAReportWritesItsNormalisedArgumentsOntoTheCall(): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'send_owner_report', ['outcome' => ' report ', 'subject' => "  Weekly\n  notes   check "], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);
        self::assertCallOutcome($callRow, $loopFlags, 'Validated.', AIConversationMessage::STATUS_COMPLETED, self::TOOL_LESS_STEP_ONLY);
        self::assertSame(['outcome' => 'REPORT', 'subject' => 'Weekly notes check'], $callRow->toolCall->arguments);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);

        // a REPORT already bound to its text: the next step keeps its tools
        $boundConversation = $this->conversation();
        $boundCallRow = $this->appendCallRow($boundConversation, 'send_owner_report', ['outcome' => 'REPORT', 'subject' => 'Weekly'], 'call-bound');
        $boundCallRow->toolCall->ownerReportMessageId = 42;
        $boundLoopFlags = $this->dispatch(new DispatchTableDispatchServiceDouble(), $boundConversation, $boundCallRow);
        self::assertCallOutcome($boundCallRow, $boundLoopFlags, 'Validated.', AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
    }

    public function testNothingToReportRemovesTheSubjectFromTheCall(): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, 'send_owner_report', ['outcome' => 'nothing_to_report', 'subject' => '   '], 'call-current');

        $loopFlags = $this->dispatch(new DispatchTableDispatchServiceDouble(), $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, 'Validated.', AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
        self::assertSame(['outcome' => 'NOTHING_TO_REPORT'], $callRow->toolCall->arguments);
    }
}
