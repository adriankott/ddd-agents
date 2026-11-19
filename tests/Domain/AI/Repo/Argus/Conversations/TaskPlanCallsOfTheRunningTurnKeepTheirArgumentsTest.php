<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An `update_task_plan` call of the RUNNING turn keeps its arguments on the wire, also when a newer plan call follows:
 * the input of call N is the unchanged start of the input of call N+1 inside one turn, so the cached prefix holds
 * across plan updates. The arguments of an older plan call become `{"superseded":true}` only once a new turn has
 * started (the turn boundary), like the tool-result clearing; the newest plan call is never stubbed.
 */
class TaskPlanCallsOfTheRunningTurnKeepTheirArgumentsTest extends UnitTestCase
{
    protected int $nextIndexInConversation = 0;

    public function testTheInputOfCallNIsTheUnchangedStartOfCallNPlusOneWithinATurn(): void
    {
        $callNRows = [
            $this->ownerTextRow('plan the nine campaign cards'),
            $this->callRow(UpdateTaskPlanTool::NAME, ['items' => [['text' => 'card 1'], ['text' => 'card 2']]], 'plan created'),
            $this->callRow('getCampaigns', ['limit' => 9], '{"campaigns":[]}'),
            $this->callRow(UpdateTaskPlanTool::NAME, ['items' => [['text' => 'card 1', 'status' => 'done'], ['text' => 'card 2']]], 'plan updated'),
        ];
        $callNPlusOneRows = [...$callNRows, $this->callRow(UpdateTaskPlanTool::NAME, ['items' => [['text' => 'card 1', 'status' => 'done'], ['text' => 'card 2', 'status' => 'done']]], 'plan updated')];

        $callNWire = $this->buildEgress()->buildOpenAiChatMessagesArray($callNRows);
        $callNPlusOneWire = $this->buildEgress()->buildOpenAiChatMessagesArray($callNPlusOneRows);

        self::assertSame($callNWire, array_slice($callNPlusOneWire, 0, count($callNWire)), 'call N+1 starts with call N\'s input, byte for byte');
        self::assertStringNotContainsString('superseded', (string)json_encode($callNPlusOneWire), 'no plan call of the running turn is stubbed');
    }

    public function testAtTheNextTurnTheOlderPlanCallsOfEarlierTurnsAreStubbedAndTheNewestKeepsItsArguments(): void
    {
        $rows = [
            $this->ownerTextRow('plan the cards'),
            $this->callRow(UpdateTaskPlanTool::NAME, ['items' => [['text' => 'first plan']]], 'plan created'),
            $this->callRow(UpdateTaskPlanTool::NAME, ['items' => [['text' => 'second plan']]], 'plan updated'),
            $this->ownerTextRow('go on'),
        ];

        $wireJson = (string)json_encode($this->buildEgress()->buildOpenAiChatMessagesArray($rows));

        self::assertStringNotContainsString('first plan', $wireJson, 'the older plan call of the earlier turn is stubbed at the boundary');
        self::assertStringContainsString('superseded', $wireJson);
        self::assertStringContainsString('second plan', $wireJson, 'the newest plan call keeps its arguments');
    }

    protected function buildEgress(): ArgusAIAgentConversation
    {
        /** @var ArgusAIAgentConversation $egress */
        $egress = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        return $egress;
    }

    protected function ownerTextRow(string $text): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->message = $text;
        $message->indexInConversation = $this->nextIndexInConversation++;
        return $message;
    }

    /** @param array<string, mixed> $arguments */
    protected function callRow(string $toolName, array $arguments, string $result): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->indexInConversation = $this->nextIndexInConversation;
        $message->inputTokens = 100;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_' . $this->nextIndexInConversation++;
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->result = $result;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $message->toolCall = $toolCall;
        return $message;
    }
}
