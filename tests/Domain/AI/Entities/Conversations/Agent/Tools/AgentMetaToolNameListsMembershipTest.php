<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentPausedTurnRecord;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The members of the loop's tool-name lists and of the questions the loop asks about a completed call, each pinned on
 * its own: the lists differ on purpose or by history ({@see AgentMetaTools}), and merging two of them is a change of its
 * own. A constant list is asserted as it is, order included; a question is asked for every meta tool and three tools of
 * an application (a read reused within the turn, a write, and a read that leaves the turn's reads reusable), and the
 * names it counts are asserted. The compaction telemetry's list is pinned by {@see AgentMetaToolsCompactionWorkCountsTest}.
 */
class AgentMetaToolNameListsMembershipTest extends UnitTestCase
{
    protected const string REUSABLE_READ = 'getNotes';

    protected const string WRITE = 'addNote';

    protected const string READ_KEEPING_READS_REUSABLE = 'showNoteChoices';

    /** @var mixed The memo of the effect catalog before the test */
    protected mixed $effectsByToolNameBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $effectsMemo = new ReflectionProperty(MCPToolEffectCatalog::class, 'effectsByToolName');
        $this->effectsByToolNameBeforeTest = $effectsMemo->getValue();
        MCPToolEffectCatalog::getEffectForToolName('load_skills');
        $effectsMemo->setValue(null, [
            ...$effectsMemo->getValue(),
            self::REUSABLE_READ => new MCPToolEffect(MCPToolEffect::READ),
            self::WRITE => new MCPToolEffect(MCPToolEffect::WRITE),
            self::READ_KEEPING_READS_REUSABLE => new MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false, keepsEarlierReadsReusable: true),
        ]);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(MCPToolEffectCatalog::class, 'effectsByToolName'))->setValue(null, $this->effectsByToolNameBeforeTest);
        parent::tearDown();
    }

    /** @return string[] the meta tools in the order of {@see AgentMetaTools::TOOL_CLASSES}, then the three tools of an application */
    protected static function probeToolNames(): array
    {
        return [
            ...array_map(static fn (string $metaToolClass): string => $metaToolClass::NAME, AgentMetaTools::TOOL_CLASSES),
            self::REUSABLE_READ,
            self::WRITE,
            self::READ_KEEPING_READS_REUSABLE,
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------------------------------

    protected static function row(string $role, string $authorType, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $role === AIConversationMessage::ROLE_USER ? 'Please look after my notes.' : '';
        $message->indexInConversation = $index;
        return $message;
    }

    /** A conversation (never stored) whose first row is the owner's message that opens the turn. */
    protected static function conversation(): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->messages = new AIConversationMessages();
        $ownerRow = self::row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 0);
        $conversation->messages->add($ownerRow);
        return $conversation;
    }

    /**
     * A call of `$toolName` appended to the conversation with its status and result.
     *
     * @param array<string, mixed> $arguments
     */
    protected static function appendCall(AIConversation $conversation, string $toolName, array $arguments, string $status, ?string $result = 'ok'): AIConversationMessage
    {
        $index = count($conversation->messages->getElements());
        $callRow = self::row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, $index);
        $callRow->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call-' . $index;
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->status = $status;
        $toolCall->result = $result;
        $toolCall->executionStartedAt = '2026-10-20 10:00:00';
        $callRow->toolCall = $toolCall;
        $conversation->messages->add($callRow);
        return $callRow;
    }

    /** @param array<string, mixed> $arguments */
    protected static function detachedCall(string $toolName, array $arguments): ToolCall
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'probe';
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        return $toolCall;
    }

    protected static function conversationWithOneCompletedCall(string $toolName): AIConversation
    {
        $conversation = self::conversation();
        self::appendCall($conversation, $toolName, ['probe' => 1], AIConversationMessage::STATUS_COMPLETED);
        return $conversation;
    }

    /** The model input over the conversation's rows, without a composition. */
    protected static function modelInputOver(AIConversation $conversation): AgentModelInput
    {
        /** @var AgentModelInput $agentModelInput */
        $agentModelInput = self::instantiateWithoutConstructor(AgentModelInput::class);
        $agentModelInput->messages = array_values($conversation->messages->getElements());
        return $agentModelInput;
    }

    protected static function callModelInputMethod(AgentModelInput $agentModelInput, string $methodName, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(AgentModelInput::class, $methodName))->invoke($agentModelInput, ...$arguments);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The questions, one per method, asked for one probe name
    // ---------------------------------------------------------------------------------------------------------------

    protected static function hasCompletedWorkToolCall(string $toolName): bool
    {
        return self::conversationWithOneCompletedCall($toolName)->messages->buildCurrentTurn()->hasCompletedWorkToolCall();
    }

    /** A FAILED call of `$toolName` in the turn counts as a load of a skill or tool. */
    protected static function isSkillOrToolLoadCall(string $toolName): bool
    {
        $conversation = self::conversation();
        self::appendCall($conversation, $toolName, [], AIConversationMessage::STATUS_FAILED);
        return $conversation->messages->buildCurrentTurn()->hasSkillOrToolLoadCall();
    }

    protected static function countsAsWorkToolCall(string $toolName): bool
    {
        return self::conversationWithOneCompletedCall($toolName)->messages->buildToolCallHistory()->countWorkToolCalls() === 1;
    }

    /** A completed call of `$toolName` after two identical failed reads ends their failure streak. */
    protected static function endsTheFailedReadStreak(string $toolName): bool
    {
        $readArguments = ['folder' => 7];
        $conversation = self::conversation();
        self::appendCall($conversation, self::REUSABLE_READ, $readArguments, AIConversationMessage::STATUS_FAILED, 'timeout');
        self::appendCall($conversation, self::REUSABLE_READ, $readArguments, AIConversationMessage::STATUS_FAILED, 'timeout');
        self::appendCall($conversation, $toolName, ['probe' => 1], AIConversationMessage::STATUS_COMPLETED);
        return $conversation->messages->buildToolCallHistory()->getRepeatedFailedReadResult(self::detachedCall(self::REUSABLE_READ, $readArguments)) === null;
    }

    /** A completed call of `$toolName` after a completed read of the turn ends the reuse of that read. */
    protected static function endsTheReadReuse(string $toolName): bool
    {
        $readArguments = ['folder' => 7];
        $conversation = self::conversation();
        self::appendCall($conversation, self::REUSABLE_READ, $readArguments, AIConversationMessage::STATUS_COMPLETED, 'notes');
        self::appendCall($conversation, $toolName, ['probe' => 1], AIConversationMessage::STATUS_COMPLETED);
        return $conversation->messages->buildCurrentTurn()->buildToolCallHistory()->getReusablePriorReadCall(self::detachedCall(self::REUSABLE_READ, $readArguments)) === null;
    }

    protected static function getsASummaryActionLine(string $toolName): bool
    {
        $conversation = self::conversationWithOneCompletedCall($toolName);
        return self::callModelInputMethod(self::modelInputOver($conversation), 'buildSummaryActionLine', $conversation->messages->last()) !== null;
    }

    /** A completed call of `$toolName` after a completed read clears the hint that lists the turn's reads. */
    protected static function clearsTheHintOfTheReads(string $toolName): bool
    {
        $conversation = self::conversation();
        self::appendCall($conversation, self::REUSABLE_READ, ['folder' => 7], AIConversationMessage::STATUS_COMPLETED, 'notes');
        self::appendCall($conversation, $toolName, ['probe' => 1], AIConversationMessage::STATUS_COMPLETED);
        return self::callModelInputMethod(self::modelInputOver($conversation), 'buildRecentToolCallsHintText') === '';
    }

    protected static function isInTheAnswerContractOfCompletedWrites(string $toolName): bool
    {
        return self::modelInputOver(self::conversationWithOneCompletedCall($toolName))->completedWritesAnswerContractHint() !== null;
    }

    protected static function isOnTheWriteLedgerOfTheJudge(string $toolName): bool
    {
        /** @var AIConversationAuditRenderService $aIConversationAuditRenderService */
        $aIConversationAuditRenderService = DDDService::instance()->getService(AIConversationAuditRenderService::class);
        return str_contains($aIConversationAuditRenderService->renderWriteLedger(self::conversationWithOneCompletedCall($toolName)), ' ' . $toolName . '(');
    }

    /** A completed call of `$toolName` that wrote no widget row can have changed what the plan's criteria read. */
    protected static function canHaveChangedTheRecord(string $toolName): bool
    {
        $toolCall = self::detachedCall($toolName, ['probe' => 1]);
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        return $toolCall->canHaveChangedTheRecordWhenCompleted(false);
    }

    /**
     * The probe names `$question` (a method of this class) counts.
     *
     * @return string[]
     */
    protected static function probeToolNamesCountedBy(string $question): array
    {
        $countedToolNames = [];
        foreach (self::probeToolNames() as $toolName) {
            if (static::$question($toolName)) {
                $countedToolNames[] = $toolName;
            }
        }
        return $countedToolNames;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The constant lists
    // ---------------------------------------------------------------------------------------------------------------

    public function testTheMetaToolNamesOfTheMessageInTheirOrder(): void
    {
        self::assertSame(
            ['load_skills', 'load_tool', 'delegate', 'check_delegations', 'update_task_plan', 'set_task_status', 'get_task_plans', 'send_owner_report', 'askOwner', 'wait_for_sub_agents', 'message_sub_agent', 'findWidgets'],
            AIConversationMessage::META_TOOL_NAMES
        );
    }

    public function testTheEvalPlanningNamesTheSameLoopToolsInTheSameOrder(): void
    {
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        self::assertSame(AIConversationMessage::META_TOOL_NAMES, $agentEvalSuitePlanService->getLoopToolNames());
    }

    /** `delegate`, `set_task_status`, `askOwner`, `wait_for_sub_agents` and `message_sub_agent` declare no effect. */
    public function testTheDeclaredEffectOfEachMetaTool(): void
    {
        self::assertSame(
            [
                'load_skills' => 'READ',
                'load_tool' => 'READ',
                'check_delegations' => 'READ',
                'get_task_plans' => 'READ',
                'update_task_plan' => 'WRITE',
                'send_owner_report' => 'WRITE',
                'delegate' => null,
                'set_task_status' => null,
                'askOwner' => null,
                'wait_for_sub_agents' => null,
                'message_sub_agent' => null,
                'findWidgets' => 'READ',
            ],
            array_combine(
                array_map(static fn (string $metaToolClass): string => $metaToolClass::NAME, AgentMetaTools::TOOL_CLASSES),
                array_map(static fn (string $metaToolClass): ?string => $metaToolClass::DECLARED_EFFECT, AgentMetaTools::TOOL_CLASSES)
            )
        );
    }

    /** No meta read is reused within the turn; only the application's read declared so is. */
    public function testTheReadOnlyAndTheReusedNames(): void
    {
        self::assertSame(
            ['load_skills', 'load_tool', 'check_delegations', 'get_task_plans', 'findWidgets', self::REUSABLE_READ, self::READ_KEEPING_READS_REUSABLE],
            array_values(array_filter(self::probeToolNames(), static fn (string $toolName): bool => MCPToolEffectCatalog::isReadOnlyToolName($toolName)))
        );
        self::assertSame(
            [self::REUSABLE_READ],
            array_values(array_filter(self::probeToolNames(), static fn (string $toolName): bool => MCPToolEffectCatalog::isReusableReadToolName($toolName)))
        );
    }

    public function testThePlanToolNames(): void
    {
        self::assertSame(['update_task_plan', 'set_task_status'], TaskPlan::PLAN_TOOL_NAMES);
    }

    /** Four of the meta tools: the paused-turn record names the agent's own tools by these. */
    public function testTheAgentToolNamesOfThePausedTurnRecord(): void
    {
        self::assertSame(['load_skills', 'delegate', 'check_delegations', 'send_owner_report'], AgentPausedTurnRecord::AGENT_TOOL_NAMES);
    }

    public function testTheNamedListsOfTheLoop(): void
    {
        self::assertSame(['check_delegations', 'update_task_plan', 'set_task_status'], AgentMetaTools::REPEAT_GUARD_EXEMPT_NAMES, 'never stopped by the repeat guard');
        self::assertSame(['wait_for_sub_agents'], AgentMetaTools::REPEAT_GUARD_EXEMPT_WHILE_SUCCEEDING_NAMES, 'exempt while the calls succeed');
        self::assertSame(['load_skills', 'askOwner', 'findWidgets'], AgentMetaTools::REPEAT_ABORT_EXEMPT_NAMES, 'never abort the turn');
        self::assertSame(['update_task_plan', 'set_task_status'], AgentMetaTools::FORCED_FIRST_STEP_LEFT_OUT_NAMES, 'left out of a forced first step');
        self::assertSame(
            ['load_skills', 'delegate', 'update_task_plan', 'set_task_status', 'send_owner_report', 'wait_for_sub_agents', 'message_sub_agent', 'findWidgets'],
            AgentMetaTools::NOT_WORK_FOR_THE_FORCED_STEP_NAMES,
            'no work for the forced step'
        );
        self::assertSame(['load_tool', 'findWidgets'], AgentMetaTools::NOT_IN_THE_FORCED_REDRIVE_NAMES, 'never in the forced re-drive');
        self::assertSame(['askOwner'], AIConversationMessages::NON_MUTATING_WORK_TOOL_NAMES, 'work that changes nothing');
        self::assertSame(['wait_for_sub_agents'], AgentMetaTools::CONTROL_TOOLS_CHANGING_NOTHING_NAMES, 'control tools that change nothing');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The questions about a completed call
    // ---------------------------------------------------------------------------------------------------------------

    /** `load_tool`, `check_delegations` and `send_owner_report` count as work here. */
    public function testWhichCompletedCallIsWorkOfTheTurn(): void
    {
        self::assertSame(
            ['load_tool', 'check_delegations', 'send_owner_report', 'message_sub_agent', self::REUSABLE_READ, self::WRITE, self::READ_KEEPING_READS_REUSABLE],
            self::probeToolNamesCountedBy('hasCompletedWorkToolCall')
        );
        $conversationWithARefusedRepeat = self::conversation();
        $refusedRepeatRow = self::appendCall($conversationWithARefusedRepeat, self::WRITE, ['probe' => 1], AIConversationMessage::STATUS_COMPLETED);
        $refusedRepeatRow->toolCall->wasRefusedAsDuplicate = true;
        self::assertFalse($conversationWithARefusedRepeat->messages->buildCurrentTurn()->hasCompletedWorkToolCall(), 'a repeat the loop refused did no work');
    }

    public function testWhichCallIsALoadOfASkillOrATool(): void
    {
        self::assertSame(['load_skills', 'load_tool'], self::probeToolNamesCountedBy('isSkillOrToolLoadCall'));
    }

    /** Everything but `load_skills` and `findWidgets` counts as a work call of the history. */
    public function testWhichCallTheHistoryCountsAsWork(): void
    {
        self::assertSame(array_values(array_diff(self::probeToolNames(), ['load_skills', 'findWidgets'])), self::probeToolNamesCountedBy('countsAsWorkToolCall'));
    }

    /** `load_tool`, `get_task_plans` and `askOwner` end it; `check_delegations` and `send_owner_report` do not. */
    public function testWhichCompletedCallEndsTheFailureStreakOfARead(): void
    {
        self::assertSame(
            ['load_tool', 'get_task_plans', 'askOwner', 'wait_for_sub_agents', 'message_sub_agent', 'findWidgets', self::WRITE, self::READ_KEEPING_READS_REUSABLE],
            self::probeToolNamesCountedBy('endsTheFailedReadStreak')
        );
    }

    /** `load_tool`, `check_delegations` and `get_task_plans` end it; the read that keeps the reads reusable does not. */
    public function testWhichCompletedCallEndsTheReuseOfARead(): void
    {
        self::assertSame(
            ['load_tool', 'check_delegations', 'get_task_plans', 'wait_for_sub_agents', 'message_sub_agent', self::WRITE],
            self::probeToolNamesCountedBy('endsTheReadReuse')
        );
    }

    /** Three questions of the model input about a completed call: the summary line, the hint of the reads, the answer contract. */
    public function testWhichCompletedCallTheModelInputTreatsAsAWrite(): void
    {
        self::assertSame(
            ['get_task_plans', 'send_owner_report', 'askOwner', self::WRITE, self::READ_KEEPING_READS_REUSABLE],
            self::probeToolNamesCountedBy('getsASummaryActionLine'),
            'the summary ACTION line'
        );
        self::assertSame(
            ['load_tool', 'check_delegations', 'get_task_plans', 'send_owner_report', 'askOwner', 'wait_for_sub_agents', 'message_sub_agent', self::WRITE, self::READ_KEEPING_READS_REUSABLE],
            self::probeToolNamesCountedBy('clearsTheHintOfTheReads'),
            'the hint of the reads'
        );
        self::assertSame(
            ['load_tool', 'check_delegations', 'get_task_plans', 'send_owner_report', 'message_sub_agent', self::WRITE, self::READ_KEEPING_READS_REUSABLE],
            self::probeToolNamesCountedBy('isInTheAnswerContractOfCompletedWrites'),
            'the answer contract of completed writes'
        );
    }

    /** `wait_for_sub_agents` changes nothing and is not counted; `message_sub_agent` is, like `delegate`. */
    public function testWhichCompletedCallIsOnTheWriteLedgerOfTheJudge(): void
    {
        self::assertSame(
            ['send_owner_report', 'delegate', 'askOwner', 'message_sub_agent', self::WRITE],
            self::probeToolNamesCountedBy('isOnTheWriteLedgerOfTheJudge')
        );
    }

    /** `wait_for_sub_agents` changes nothing and is not counted; `message_sub_agent` is, like `delegate`. */
    public function testWhichCompletedCallCanHaveChangedTheRecord(): void
    {
        self::assertSame(
            ['update_task_plan', 'send_owner_report', 'delegate', 'set_task_status', 'message_sub_agent', self::WRITE],
            self::probeToolNamesCountedBy('canHaveChangedTheRecord')
        );
    }

    /** The plan-call question of the dispatch's catch arms counts exactly the two plan tools. */
    public function testThePlanCallQuestionOfTheWorkRequestCountsThePlanTools(): void
    {
        $planCallToolNames = [];
        foreach ([...self::probeToolNames(), '', 'noSuchTool'] as $toolName) {
            if (AgentMetaTools::findWorkRequest(self::detachedCall($toolName, ['items' => [['text' => 'x']]]))?->appliesTaskPlanCall() ?? false) {
                $planCallToolNames[] = $toolName;
            }
        }
        self::assertSame(['update_task_plan', 'set_task_status'], $planCallToolNames);
    }
}
