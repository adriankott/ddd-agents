<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use ReflectionProperty;

/**
 * The stage of the dispatch table: a conversation of rows built in memory (never stored), the call rows, the effect
 * catalog with the neutral tools of the table, and one dispatch of a call through {@see DispatchTableDispatchServiceDouble}.
 *
 * The neutral tools (no skill binds them; their effects are added to the catalog for the test and removed afterwards):
 * `getNotes` a READ reused within the turn, `addNote` a WRITE whose identical repeat is refused, `deleteNote` a DELETE,
 * `showNoteChoices` a READ that keeps the turn's earlier reads reusable, `endCurrentCall` a WRITE whose identical repeat
 * does new work. `probeWorkTool` is known to nothing: its call reaches the execution.
 */
trait DispatchTableFixtures
{
    protected const string PLAIN_TOOL = 'probeWorkTool';

    protected const string REUSABLE_READ_TOOL = 'getNotes';

    protected const string WRITE_TOOL = 'addNote';

    protected const string DELETE_TOOL = 'deleteNote';

    protected const string READ_KEEPING_READS_REUSABLE_TOOL = 'showNoteChoices';

    protected const string WRITE_DOING_NEW_WORK_ON_REPEAT_TOOL = 'endCurrentCall';

    protected const array NO_LOOP_FLAGS = ['repeatedToolCallAbort' => false, 'awaitingDelegates' => false, 'forceToolLessAnswerStep' => false];

    protected const array TOOL_LESS_STEP_ONLY = ['repeatedToolCallAbort' => false, 'awaitingDelegates' => false, 'forceToolLessAnswerStep' => true];

    protected const array ABORT_ONLY = ['repeatedToolCallAbort' => true, 'awaitingDelegates' => false, 'forceToolLessAnswerStep' => false];

    /** @var mixed The memo of the effect catalog before the test */
    protected mixed $effectsByToolNameBeforeTest = null;

    /** Add the neutral tools' effects to the catalog (the meta tools' and the package's own entries stay). */
    protected function addNeutralToolEffectsToTheCatalog(): void
    {
        $effectsMemo = new ReflectionProperty(MCPToolEffectCatalog::class, 'effectsByToolName');
        $this->effectsByToolNameBeforeTest = $effectsMemo->getValue();
        // builds the catalog when it is not built yet
        MCPToolEffectCatalog::getEffectForToolName('load_skills');
        $effectsMemo->setValue(null, [
            ...$effectsMemo->getValue(),
            self::REUSABLE_READ_TOOL => new MCPToolEffect(MCPToolEffect::READ),
            self::WRITE_TOOL => new MCPToolEffect(MCPToolEffect::WRITE),
            self::DELETE_TOOL => new MCPToolEffect(MCPToolEffect::DELETE),
            self::READ_KEEPING_READS_REUSABLE_TOOL => new MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false, keepsEarlierReadsReusable: true),
            self::WRITE_DOING_NEW_WORK_ON_REPEAT_TOOL => new MCPToolEffect(MCPToolEffect::WRITE, identicalRepeatDoesNewWork: true),
        ]);
    }

    protected function restoreTheEffectCatalog(): void
    {
        (new ReflectionProperty(MCPToolEffectCatalog::class, 'effectsByToolName'))->setValue(null, $this->effectsByToolNameBeforeTest);
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

    /** A conversation (never stored) whose first row is the owner's message that opens the turn. */
    protected function conversation(): DispatchTableConversation
    {
        /** @var DispatchTableConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(DispatchTableConversation::class);
        $conversation->messages = new AIConversationMessages();
        $this->appendOwnerRow($conversation, 'Please look after my notes.');
        return $conversation;
    }

    /** An owner row: it opens a new owner turn. */
    protected function appendOwnerRow(AIConversation $conversation, string $text): AIConversationMessage
    {
        $ownerRow = $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, $text, count($conversation->messages->getElements()));
        $conversation->messages->add($ownerRow);
        return $ownerRow;
    }

    /**
     * A call row of `$toolName` appended to the conversation.
     *
     * @param array<string, mixed> $arguments
     */
    protected function appendCallRow(AIConversation $conversation, string $toolName, array $arguments, string $toolCallId): AIConversationMessage
    {
        $callRow = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', count($conversation->messages->getElements()));
        $callRow->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = $toolCallId;
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $callRow->toolCall = $toolCall;
        $conversation->messages->add($callRow);
        return $callRow;
    }

    /**
     * An earlier call row with its recorded outcome.
     *
     * @param array<string, mixed> $arguments
     */
    protected function appendEarlierCall(AIConversation $conversation, string $toolName, array $arguments, ?string $status, ?string $result, string $toolCallId, bool $executed = false): AIConversationMessage
    {
        $earlierCallRow = $this->appendCallRow($conversation, $toolName, $arguments, $toolCallId);
        $earlierCallRow->toolCall->status = $status;
        $earlierCallRow->toolCall->result = $result;
        if ($executed) {
            $earlierCallRow->toolCall->executionStartedAt = '2026-10-20 10:00:00';
        }
        return $earlierCallRow;
    }

    /**
     * A task plan in the conversation's working state.
     *
     * @param array<int, array<string, mixed>> $items the plan's items in their stored form
     */
    protected function attachTaskPlan(AIConversation $conversation, array $items): void
    {
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->taskPlan = TaskPlan::fromRepositoryArray(['items' => $items]);
        $conversation->agentWorkingState = $agentWorkingState;
    }

    /**
     * Dispatch the call of `$callRow` (the conversation's newest row) through `$dispatchService`.
     *
     * @return array{repeatedToolCallAbort: bool, awaitingDelegates: bool, forceToolLessAnswerStep: bool}
     */
    protected function dispatch(
        DispatchTableDispatchServiceDouble $dispatchService,
        AIConversation $conversation,
        AIConversationMessage $callRow,
        bool $allowThreads = false,
        bool $asyncDelegations = false
    ): array {
        $agentToolCallAnswer = $dispatchService->dispatchToolCall(
            AgentRun::forTurnEntry(),
            $this->createStub(AgentChildTurnRunnerInterface::class),
            $conversation,
            $callRow->toolCall,
            $callRow,
            $allowThreads,
            null,
            AgentTransientMessageIds::startingAt(0),
            null,
            $asyncDelegations
        );
        return self::loopFlagsOf($agentToolCallAnswer);
    }

    /** @return array{repeatedToolCallAbort: bool, awaitingDelegates: bool, forceToolLessAnswerStep: bool} */
    protected static function loopFlagsOf(AgentToolCallAnswer $agentToolCallAnswer): array
    {
        return [
            'repeatedToolCallAbort' => $agentToolCallAnswer->repeatedToolCallAbort,
            'awaitingDelegates' => $agentToolCallAnswer->awaitingDelegates,
            'forceToolLessAnswerStep' => $agentToolCallAnswer->forceToolLessAnswerStep,
        ];
    }

    /** Nothing behind the dispatch was reached: no execution, no plan application, no spawn, no failure logged. */
    protected static function assertNothingBehindTheDispatchWasReached(DispatchTableDispatchServiceDouble $dispatchService): void
    {
        self::assertSame([], $dispatchService->executedToolNames, 'no execution');
        self::assertSame([], $dispatchService->planRecordServiceDouble->calls, 'no plan application');
        self::assertSame([], $dispatchService->delegationSpawnServiceDouble->calls, 'no spawn');
        self::assertSame([], $dispatchService->loggedFailures, 'no failure logged');
    }

    /**
     * What the dispatch wrote onto the call and answered the loop.
     *
     * @param array{repeatedToolCallAbort: bool, awaitingDelegates: bool, forceToolLessAnswerStep: bool} $loopFlags
     * @param array{repeatedToolCallAbort: bool, awaitingDelegates: bool, forceToolLessAnswerStep: bool} $expectedLoopFlags
     */
    protected static function assertCallOutcome(
        AIConversationMessage $callRow,
        array $loopFlags,
        ?string $expectedResult,
        ?string $expectedStatus,
        array $expectedLoopFlags,
        bool $expectedRefusedAsDuplicate = false,
        bool $expectedRefusedByGate = false
    ): void {
        self::assertSame($expectedResult, $callRow->toolCall->result, 'the stored result');
        self::assertSame($expectedStatus, $callRow->toolCall->status, 'the status');
        self::assertSame($expectedRefusedAsDuplicate, $callRow->toolCall->wasRefusedAsDuplicate, 'wasRefusedAsDuplicate');
        self::assertSame($expectedRefusedByGate, $callRow->toolCall->wasRefusedByGate, 'wasRefusedByGate');
        self::assertSame($expectedLoopFlags, $loopFlags, 'the flags for the loop');
    }
}
