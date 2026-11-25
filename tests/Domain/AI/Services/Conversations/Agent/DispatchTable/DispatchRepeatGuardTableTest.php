<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinition;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The per-turn repeat guard of {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService::dispatchToolCall()}:
 * the fourth identical call of every meta tool and of a read, a write and a read that keeps the turn's reads reusable is
 * aborted, answered with the corrective, or runs its own branch (the exempt names); the third identical call gets the
 * corrective and a gate refusal before it never counts; a tool that declares it refreshes state is exempt while its
 * identical calls succeed.
 */
class DispatchRepeatGuardTableTest extends UnitTestCase
{
    use DispatchTableFixtures;

    protected const string REPEAT_ABORT = 'Aborted: this exact tool call was repeated without progress.';

    protected const string REPEAT_CORRECTIVE = 'You have already called `%s` with these exact arguments %d times and the result has '
        . 'not changed. Do NOT call it again. If the call SUCCEEDED earlier, the work IS done — nothing '
        . 'failed and nothing is unavailable, so NEVER tell the user you lack access: the platform closes '
        . 'the matching plan item from the record; continue with the NEXT open item. Otherwise '
        . 'answer from the data you already have, or tell the user plainly what you cannot determine '
        . 'and why.';

    /** A READ that declares it refreshes state; its definition is given by the conversation */
    protected const string STATE_REFRESHING_TOOL = 'readSiteState';

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

    /**
     * The fourth identical call (three identical calls before it in the turn, no outcome recorded): `abort`, `corrective`
     * (the abort-exempt names), or `branch` (the guard-exempt names run their own branch).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fourthIdenticalCalls(): array
    {
        return [
            'load_skills: never aborted → corrective' => ['load_skills', 'corrective'],
            'load_tool → abort' => ['load_tool', 'abort'],
            'delegate without threads → abort' => ['delegate', 'abort'],
            'check_delegations: exempt → its branch' => ['check_delegations', 'branch'],
            'update_task_plan: exempt → its branch' => ['update_task_plan', 'branch'],
            'set_task_status: exempt → its branch' => ['set_task_status', 'branch'],
            'get_task_plans → abort' => ['get_task_plans', 'abort'],
            'send_owner_report → abort' => ['send_owner_report', 'abort'],
            'askOwner: never aborted → corrective' => ['askOwner', 'corrective'],
            'wait_for_sub_agents: exempt while it succeeds → its branch' => ['wait_for_sub_agents', 'branch'],
            'message_sub_agent → abort' => ['message_sub_agent', 'abort'],
            'findWidgets: never aborted → corrective' => ['findWidgets', 'corrective'],
            'a reused read → abort' => [self::REUSABLE_READ_TOOL, 'abort'],
            'a write → abort' => [self::WRITE_TOOL, 'abort'],
            'a read that keeps the reads reusable → abort' => [self::READ_KEEPING_READS_REUSABLE_TOOL, 'abort'],
        ];
    }

    #[DataProvider('fourthIdenticalCalls')]
    public function testTheFourthIdenticalCallIsAbortedCorrectedOrRunsItsBranch(string $toolName, string $expectedOutcome): void
    {
        $arguments = ['probe' => 'same'];
        $conversation = $this->conversation();
        foreach (['call-a', 'call-b', 'call-c'] as $earlierToolCallId) {
            $this->appendEarlierCall($conversation, $toolName, $arguments, null, null, $earlierToolCallId);
        }
        $callRow = $this->appendCallRow($conversation, $toolName, $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        if ($expectedOutcome === 'abort') {
            self::assertCallOutcome($callRow, $loopFlags, self::REPEAT_ABORT, AIConversationMessage::STATUS_FAILED, self::ABORT_ONLY);
            self::assertNothingBehindTheDispatchWasReached($dispatchService);
            return;
        }
        if ($expectedOutcome === 'corrective') {
            self::assertCallOutcome($callRow, $loopFlags, sprintf(self::REPEAT_CORRECTIVE, $toolName, 4), AIConversationMessage::STATUS_COMPLETED, self::TOOL_LESS_STEP_ONLY, true);
            self::assertNothingBehindTheDispatchWasReached($dispatchService);
            return;
        }
        self::assertFalse($callRow->toolCall->wasRefusedAsDuplicate, 'an exempt call is not refused');
        self::assertFalse($loopFlags['repeatedToolCallAbort']);
        $planApplications = $dispatchService->planRecordServiceDouble->calls;
        if ($toolName === 'check_delegations') {
            self::assertSame('No sub-agents in this conversation yet.', $callRow->toolCall->result);
            self::assertSame([], $planApplications);
        } elseif ($toolName === 'update_task_plan') {
            self::assertSame([['method' => 'applyUpdateTaskPlanCall', 'arguments' => $arguments]], $planApplications);
        } elseif ($toolName === 'set_task_status') {
            self::assertSame([['method' => 'applySetTaskStatusCall', 'arguments' => $arguments]], $planApplications);
        } else {
            self::assertSame('wait_for_sub_agents', $toolName);
            self::assertStringNotContainsString('You have already called', (string)$callRow->toolCall->result);
            self::assertNotSame(self::REPEAT_ABORT, $callRow->toolCall->result);
        }
    }

    /** A conversation whose loaded tools hold {@see STATE_REFRESHING_TOOL}, declared state-refreshing. */
    protected function conversationWithAStateRefreshingTool(): DispatchTableConversation
    {
        $toolDefinition = new ToolDefinition();
        $toolDefinition->name = self::STATE_REFRESHING_TOOL;
        $toolDefinition->refreshesStateOnEveryCall = true;
        $toolDefinitions = new ToolDefinitions();
        $toolDefinitions->add($toolDefinition);
        $conversation = $this->conversation();
        $conversation->stubbedLoadedToolDefinitions = $toolDefinitions;
        return $conversation;
    }

    public function testAStateRefreshingToolIsExemptWhileItsIdenticalCallsSucceed(): void
    {
        $arguments = ['siteId' => 7];
        $conversation = $this->conversationWithAStateRefreshingTool();
        foreach (['call-a', 'call-b', 'call-c'] as $earlierToolCallId) {
            $this->appendEarlierCall($conversation, self::STATE_REFRESHING_TOOL, $arguments, AIConversationMessage::STATUS_COMPLETED, 'state', $earlierToolCallId, true);
        }
        $callRow = $this->appendCallRow($conversation, self::STATE_REFRESHING_TOOL, $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertSame([self::STATE_REFRESHING_TOOL], $dispatchService->executedToolNames, 'the fourth call executes');
        self::assertCallOutcome($callRow, $loopFlags, 'result:' . self::STATE_REFRESHING_TOOL, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
    }

    public function testThreeIdenticalFailuresEndTheExemptionOfAStateRefreshingTool(): void
    {
        $arguments = ['siteId' => 7];
        $conversation = $this->conversationWithAStateRefreshingTool();
        foreach (['call-a', 'call-b', 'call-c'] as $earlierToolCallId) {
            $this->appendEarlierCall($conversation, self::STATE_REFRESHING_TOOL, $arguments, AIConversationMessage::STATUS_FAILED, 'no site', $earlierToolCallId, true);
        }
        $callRow = $this->appendCallRow($conversation, self::STATE_REFRESHING_TOOL, $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, self::REPEAT_ABORT, AIConversationMessage::STATUS_FAILED, self::ABORT_ONLY);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    public function testTheThirdIdenticalCallGetsTheCorrective(): void
    {
        $arguments = ['n' => 1];
        $conversation = $this->conversation();
        $this->appendEarlierCall($conversation, self::PLAIN_TOOL, $arguments, AIConversationMessage::STATUS_COMPLETED, 'x', 'call-a');
        $this->appendEarlierCall($conversation, self::PLAIN_TOOL, $arguments, AIConversationMessage::STATUS_COMPLETED, 'x', 'call-b');
        $callRow = $this->appendCallRow($conversation, self::PLAIN_TOOL, $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, sprintf(self::REPEAT_CORRECTIVE, self::PLAIN_TOOL, 3), AIConversationMessage::STATUS_COMPLETED, self::TOOL_LESS_STEP_ONLY, true);
        self::assertNothingBehindTheDispatchWasReached($dispatchService);
    }

    public function testAnEarlierCallRefusedByAGateIsNoIdenticalCall(): void
    {
        $arguments = ['n' => 1];
        $conversation = $this->conversation();
        $this->appendEarlierCall($conversation, self::PLAIN_TOOL, $arguments, AIConversationMessage::STATUS_COMPLETED, 'x', 'call-a');
        $gateRefusedCallRow = $this->appendEarlierCall($conversation, self::PLAIN_TOOL, $arguments, AIConversationMessage::STATUS_FAILED, 'gate', 'call-b');
        $gateRefusedCallRow->toolCall->wasRefusedByGate = true;
        $callRow = $this->appendCallRow($conversation, self::PLAIN_TOOL, $arguments, 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertSame([self::PLAIN_TOOL], $dispatchService->executedToolNames);
        self::assertCallOutcome($callRow, $loopFlags, 'result:' . self::PLAIN_TOOL, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
    }
}
