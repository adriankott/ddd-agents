<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalEvictionTurnFigures;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The figures of one turn of an eviction trajectory: only the current turn's rows count — model calls, `load_tool`
 * hops, reads refused as duplicates, input and cached tokens — beside the seconds the caller measured.
 */
class AgentEvalEvictionTurnFiguresTest extends UnitTestCase
{
    public function testOnlyTheCurrentTurnCounts(): void
    {
        $figures = AgentEvalEvictionTurnFigures::fromCurrentTurnOfConversation($this->conversationOfTwoTurns(), 12.345);

        self::assertSame($this->expectedFiguresOfTwoTurns(), $figures->toArray());
    }

    public function testAConversationWithoutAnInitiatorCountsEveryRow(): void
    {
        $figures = AgentEvalEvictionTurnFigures::fromCurrentTurnOfConversation($this->conversationWithoutInitiator(), 0.04);

        self::assertSame($this->expectedFiguresWithoutInitiator(), $figures->toArray());
    }

    /** A first turn with a `load_tool` call, then the current turn: two `load_tool` hops, a refused read, an answer. */
    protected function conversationOfTwoTurns(): AIConversation
    {
        $refusedRead = $this->toolCall('listReports', 0, 0);
        $refusedRead->toolCall->wasRefusedAsDuplicate = true;
        return $this->conversation([
            $this->ownerText(),
            $this->toolCall('load_tool', 1000, 200),
            $this->assistantText(1500, 1000),
            $this->ownerText(),
            $this->toolCall('load_tool', 3000, 1000),
            $refusedRead,
            $this->toolCall('load_tool', 4000, 3500),
            $this->assistantText(5000, 4000),
        ]);
    }

    /** Rows without a turn initiator: an assistant text and a `load_tool` call. */
    protected function conversationWithoutInitiator(): AIConversation
    {
        return $this->conversation([
            $this->assistantText(700, 100),
            $this->toolCall('load_tool', 800, 0),
        ]);
    }

    /** @return array<string, int|float> the expected figures of this conversation */
    protected function expectedFiguresOfTwoTurns(): array
    {
        return [
            'modelCalls' => 3,
            'loadToolHops' => 2,
            'refusedReads' => 1,
            'inputTokens' => 12000,
            'cachedInputTokens' => 8500,
            'seconds' => 12.3,
        ];
    }

    /** @return array<string, int|float> the expected figures of this conversation */
    protected function expectedFiguresWithoutInitiator(): array
    {
        return [
            'modelCalls' => 2,
            'loadToolHops' => 1,
            'refusedReads' => 0,
            'inputTokens' => 1500,
            'cachedInputTokens' => 100,
            'seconds' => 0.0,
        ];
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

    protected function ownerText(): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'Please help';
        return $message;
    }

    protected function assistantText(int $inputTokens, int $cachedInputTokens): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'Done';
        $message->inputTokens = $inputTokens;
        $message->cachedInputTokens = $cachedInputTokens;
        return $message;
    }

    protected function toolCall(string $toolName, int $inputTokens, int $cachedInputTokens): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        $message->inputTokens = $inputTokens;
        $message->cachedInputTokens = $cachedInputTokens;
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = [];
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $message->toolCall = $toolCall;
        return $message;
    }
}
