<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalPerformanceBudget;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunFigures;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The performance and speed figures of an evaluated run, computed from the driven conversation in memory, and the
 * performance budget of a case checked against them.
 */
class AgentEvalRunFiguresTest extends UnitTestCase
{
    public function testTheFiguresCountTurnsModelCallsSecondsTokensAndCost(): void
    {
        $figures = AgentEvalRunFigures::fromConversation($this->conversationOfTwoTimedOwnerTurns())->toArray();

        self::assertSame(2, $figures['ownerTurns']);
        self::assertSame(3, $figures['modelCalls']);
        self::assertSame(1.5, $figures['modelCallsPerOwnerTurn']);
        self::assertSame(1, $figures['toolCalls']);
        self::assertSame([12.0, 4.0], $figures['secondsPerOwnerTurn']);
        self::assertSame(16.0, $figures['agentSeconds']);
        self::assertSame(8.0, $figures['avgSecondsPerOwnerTurn']);
        self::assertSame(12.0, $figures['maxSecondsPerOwnerTurn']);
        self::assertSame(32000, $figures['inputTokens']);
        self::assertSame(26000, $figures['cachedInputTokens']);
        self::assertSame(600, $figures['outputTokens']);
        self::assertSame(32600, $figures['totalTokens']);
        self::assertSame(0.006, $figures['costUsd']);
        self::assertSame(5, $figures['decisionRecords']);
        self::assertSame(420 + 510 + 380, $figures['decisionModelMs'], 'the records of one request count once');
        self::assertSame(['medium', 'high'], $figures['reasoningEffortsByOwnerTurn']);
    }

    public function testTheSpeedOfEveryOwnerTurnComesFromTheRows(): void
    {
        $figures = AgentEvalRunFigures::fromConversation($this->conversationWithADelegatedConversation())->toArray();

        self::assertSame(3, $figures['ownerTurns']);
        self::assertSame([2.0, 4.0, null], $figures['firstModelOutputSecondsPerOwnerTurn']);
        self::assertSame([2.0, 9.0, null], $figures['firstAnswerSecondsPerOwnerTurn']);
        // turn 1: 2000 + 2000 + child (4000 + 1000) + 1000; turn 2: 1000 + 500
        self::assertSame([10000, 1500, 0], $figures['uncachedInputTokensPerOwnerTurn']);
        self::assertSame(11500, $figures['uncachedInputTokens']);
        self::assertSame(120.0, $figures['conversationWallSeconds']);
    }

    public function testAConversationWithoutTimestampsStillYieldsItsCounts(): void
    {
        $conversation = $this->conversation([
            $this->message(null, AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, authorType: AIConversationMessage::AUTHOR_USER),
            $this->message(null, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, inputTokens: 500, outputTokens: 20, authorType: AIConversationMessage::AUTHOR_ASSISTANT),
        ]);

        $figures = AgentEvalRunFigures::fromConversation($conversation)->toArray();

        self::assertSame(1, $figures['ownerTurns']);
        self::assertSame(1, $figures['modelCalls']);
        self::assertSame([0.0], $figures['secondsPerOwnerTurn']);
        self::assertSame(0, $figures['decisionRecords']);
        self::assertSame([null], $figures['firstModelOutputSecondsPerOwnerTurn']);
        self::assertSame([null], $figures['firstAnswerSecondsPerOwnerTurn']);
        self::assertSame([500], $figures['uncachedInputTokensPerOwnerTurn']);
        self::assertNull($figures['conversationWallSeconds']);
    }

    public function testTheArrayCarriesEveryFigureUnderItsKeyInItsOrder(): void
    {
        $conversation = $this->conversation([
            $this->message('2026-09-27 10:00:00', AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, authorType: AIConversationMessage::AUTHOR_USER),
        ]);

        self::assertSame([
            'ownerTurns', 'modelCalls', 'modelCallsPerOwnerTurn', 'toolCalls', 'workToolCalls', 'agentSeconds',
            'secondsPerOwnerTurn', 'secondsByInitiatorIndex', 'avgSecondsPerOwnerTurn', 'maxSecondsPerOwnerTurn',
            'inputTokens', 'cachedInputTokens', 'outputTokens', 'totalTokens', 'costUsd', 'decisionRecords',
            'decisionModelMs', 'reasoningEffortsByOwnerTurn', 'firstModelOutputSecondsPerOwnerTurn',
            'firstAnswerSecondsPerOwnerTurn', 'uncachedInputTokensPerOwnerTurn', 'uncachedInputTokens',
            'conversationWallSeconds',
        ], array_keys(AgentEvalRunFigures::fromConversation($conversation)->toArray()));
    }

    public function testThePerformanceBudgetFailsOnlyWhatItBounds(): void
    {
        $figures = AgentEvalRunFigures::newInstance();
        $figures->ownerTurns = 2;
        $figures->modelCalls = 7;
        $figures->modelCallsPerOwnerTurn = 3.5;
        $figures->toolCalls = 5;
        $figures->agentSeconds = 41.5;
        $figures->maxSecondsPerOwnerTurn = 30.0;
        $figures->totalTokens = 90000;
        $figures->costUsd = 0.0123;

        self::assertSame([], AgentEvalPerformanceBudget::fromBudgetBlock([])->evaluateFigures($figures));
        self::assertSame([], AgentEvalPerformanceBudget::fromBudgetBlock(['maxModelCalls' => 7, 'maxAgentSeconds' => 60, 'maxCostUsd' => 0.02])->evaluateFigures($figures));
        $failures = AgentEvalPerformanceBudget::fromBudgetBlock(['maxModelCalls' => 4, 'maxSecondsPerOwnerTurn' => 20, 'maxCostUsd' => 0.01, 'maxOwnerTurns' => 2, 'unknownKey' => 1])->evaluateFigures($figures);
        self::assertSame([
            'performance: modelCalls is 7, the budget allows 4',
            'performance: maxSecondsPerOwnerTurn is 30, the budget allows 20',
            'performance: costUsd is 0.0123, the budget allows 0.01',
        ], $failures);
    }

    /** Two owner turns of 12 s and 4 s, three model calls, one tool call, five decision records of three requests. */
    protected function conversationOfTwoTimedOwnerTurns(): AIConversation
    {
        $conversation = $this->conversation([
            // turn 1: the owner asks, two model calls (a tool call, the answer) — 12 s
            $this->message('2026-09-27 10:00:00', AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, authorType: AIConversationMessage::AUTHOR_USER),
            $this->message('2026-09-27 10:00:05', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TOOL_CALL, inputTokens: 9000, cachedInputTokens: 6000, outputTokens: 100, costInUSD: 0.002, toolName: 'listReports'),
            $this->message('2026-09-27 10:00:12', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, inputTokens: 11000, cachedInputTokens: 9000, outputTokens: 300, costInUSD: 0.003, authorType: AIConversationMessage::AUTHOR_ASSISTANT),
            // the simulated owner and the judge think for a minute — not the agent's time
            // turn 2: one model call — 4 s
            $this->message('2026-09-27 10:01:12', AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, authorType: AIConversationMessage::AUTHOR_USER),
            $this->message('2026-09-27 10:01:16', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, inputTokens: 12000, cachedInputTokens: 11000, outputTokens: 200, costInUSD: 0.001, authorType: AIConversationMessage::AUTHOR_ASSISTANT),
        ]);
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->decisions = [
            // the folded turn-start request: three records, ONE request of 420 ms
            $this->decisionRecord(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE, 0, 420),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 0, 420),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_REASONING_EFFORT, 0, 420, ['effectiveEffort' => 'medium']),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_TURN_CLOSING, 0, 510),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_REASONING_EFFORT, 3, 380, ['effectiveEffort' => 'high']),
        ];
        $conversation->agentWorkingState = $agentWorkingState;
        return $conversation;
    }

    /** Three owner turns; the first spawns a delegated conversation, the last is never answered. */
    protected function conversationWithADelegatedConversation(): AIConversation
    {
        // a delegated conversation: its two model calls belong to the owner turn that spawned it
        $childConversation = $this->conversation([
            $this->message('2026-10-27 10:00:04', AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, authorType: AIConversationMessage::AUTHOR_ASSISTANT),
            $this->message('2026-10-27 10:00:07', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TOOL_CALL, inputTokens: 5000, cachedInputTokens: 1000, toolName: 'listReports'),
            $this->message('2026-10-27 10:00:09', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, inputTokens: 6000, cachedInputTokens: 5000, authorType: AIConversationMessage::AUTHOR_ASSISTANT),
        ]);
        $delegateCallMessage = $this->message('2026-10-27 10:00:03', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TOOL_CALL, inputTokens: 20000, cachedInputTokens: 18000, toolName: 'delegate');
        $childConversations = new AIConversations();
        $childConversations->add($childConversation);
        $delegateCallMessage->delegatedConversations = $childConversations;
        $conversation = $this->conversation([
            // turn 1: the owner asks at :00; the status line at :02 is both the first model output (its call's usage
            // row) and the first text the owner sees
            $this->message('2026-10-27 10:00:00', AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, authorType: AIConversationMessage::AUTHOR_USER),
            $this->message('2026-10-27 10:00:02', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, inputTokens: 19000, cachedInputTokens: 17000, authorType: AIConversationMessage::AUTHOR_ASSISTANT, text: AIConversationMessage::PRE_CALL_MESSAGE_MARKER . ' Looking at your reports'),
            $delegateCallMessage,
            $this->message('2026-10-27 10:00:12', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, inputTokens: 22000, cachedInputTokens: 21000, authorType: AIConversationMessage::AUTHOR_ASSISTANT),
            // turn 2: two tool calls before the answer; an empty assistant text is no answer
            $this->message('2026-10-27 10:01:00', AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, authorType: AIConversationMessage::AUTHOR_USER),
            $this->message('2026-10-27 10:01:04', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TOOL_CALL, inputTokens: 23000, cachedInputTokens: 22000, toolName: 'listDrafts'),
            $this->message('2026-10-27 10:01:06', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, authorType: AIConversationMessage::AUTHOR_ASSISTANT, text: '   '),
            $this->message('2026-10-27 10:01:09', AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, inputTokens: 24000, cachedInputTokens: 23500, authorType: AIConversationMessage::AUTHOR_ASSISTANT),
            // turn 3: the owner's last words, never answered
            $this->message('2026-10-27 10:02:00', AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, authorType: AIConversationMessage::AUTHOR_USER),
        ]);
        return $conversation;
    }

    /** @param AIConversationMessage[] $messageList */
    protected function conversation(array $messageList): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = new AIConversationMessages();
        foreach ($messageList as $index => $message) {
            $message->indexInConversation = $index;
            $conversation->messages->add($message);
        }
        return $conversation;
    }

    protected function message(
        ?string $createdAt,
        string $role,
        string $messageType,
        int $inputTokens = 0,
        int $cachedInputTokens = 0,
        int $outputTokens = 0,
        float $costInUSD = 0.0,
        ?string $authorType = null,
        ?string $toolName = null,
        ?string $text = null
    ): AIConversationMessage {
        $message = new AIConversationMessage();
        $message->role = $role;
        $message->messageType = $messageType;
        $message->authorType = $authorType;
        $message->inputTokens = $inputTokens;
        $message->cachedInputTokens = $cachedInputTokens;
        $message->outputTokens = $outputTokens;
        $message->costInUSD = $costInUSD;
        $message->message = $text ?? ($messageType === AIConversationMessage::MESSAGE_TEXT ? 'text' : '');
        $message->toolCall = null;
        if ($toolName !== null) {
            $toolCall = new ToolCall();
            $toolCall->name = $toolName;
            $toolCall->arguments = [];
            $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
            $message->toolCall = $toolCall;
        }
        $message->getChangeHistory()->createdTime = $createdAt !== null ? new DateTime($createdAt) : null;
        return $message;
    }

    /** @param array<string, mixed>|null $outcome */
    protected function decisionRecord(string $site, int $ownerMessageIndex, int $elapsedMs, ?array $outcome = null): AgentDecisionRecord
    {
        $decisionRecord = new AgentDecisionRecord();
        $decisionRecord->site = $site;
        $decisionRecord->ownerMessageIndex = $ownerMessageIndex;
        $decisionRecord->elapsedMs = $elapsedMs;
        $decisionRecord->outcome = $outcome;
        return $decisionRecord;
    }
}
