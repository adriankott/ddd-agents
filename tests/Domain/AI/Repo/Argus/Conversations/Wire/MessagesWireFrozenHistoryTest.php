<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentMessagesRequestComposer;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The history of a call on the Anthropic Messages API: a step of the frozen part goes back with the answer the
 * provider returned and with the notes its call ended on, at their place; the input of call N is the unchanged start
 * of the input of call N+1; a step whose stored calls differ from its rows, a step below the thinking floor and a
 * conversation without carry-over are rendered accordingly.
 */
class MessagesWireFrozenHistoryTest extends UnitTestCase
{
    public function testAFrozenStepCarriesItsStoredAnswerAndTheNotesOfItsCallBeforeIt(): void
    {
        $rows = [$this->ownerTextRow(0, 'read order 4711'), $this->stepRow(1, 'clock 10:00'), $this->stepRow(2, 'clock 10:01')];
        $rows[1]->requestSnapshot->messagesWireFrozenFromMessageIndex = 0;

        $entries = $this->buildEgress(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL)->buildOpenAiChatMessagesArray($rows);

        self::assertSame(['user', 'user', 'assistant', 'tool', 'user', 'assistant', 'tool'], array_column($entries, 'role'));
        self::assertSame('clock 10:00', $entries[1]['content']);
        self::assertTrue($entries[1][AgentMessagesRequestComposer::KEY_AS_SYSTEM_MESSAGE]);
        self::assertSame(AgentMessagesRequestComposer::CLEAR_AT_NEXT_USER_MESSAGE, $entries[1][AgentMessagesRequestComposer::KEY_CLEAR_AT]);
        self::assertSame($rows[1]->requestSnapshot->wireReasoningItemsJson, $entries[2][AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON]);
        self::assertArrayNotHasKey(AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_WITHOUT_THINKING, $entries[2]);
        self::assertSame('clock 10:01', $entries[4]['content']);
        self::assertSame($rows[2]->requestSnapshot->wireReasoningItemsJson, $entries[5][AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON]);
    }

    public function testTheInputOfCallNIsTheUnchangedStartOfCallNPlusOne(): void
    {
        $callNRows = [$this->ownerTextRow(0, 'read order 4711'), $this->stepRow(1, 'clock 10:00'), $this->stepRow(2, 'clock 10:01')];
        $callNRows[1]->requestSnapshot->messagesWireFrozenFromMessageIndex = 0;
        $callNPlusOneRows = [...$callNRows, $this->stepRow(3, 'clock 10:02')];

        $callNEntries = $this->buildEgress(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL)->buildOpenAiChatMessagesArray($callNRows);
        $callNPlusOneEntries = $this->buildEgress(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL)->buildOpenAiChatMessagesArray($callNPlusOneRows);

        self::assertSame($callNEntries, array_slice($callNPlusOneEntries, 0, count($callNEntries)), 'call N+1 starts with call N\'s history, entry for entry');
    }

    public function testAStepWhoseStoredCallsDifferFromItsRowsIsBuiltFromTheRows(): void
    {
        $rows = [$this->ownerTextRow(0, 'read order 4711'), $this->stepRow(1, 'clock 10:00')];
        $rows[1]->requestSnapshot->wireReasoningItemsJson = '[{"type":"thinking","thinking":"t","signature":"s"},'
            . '{"type":"tool_use","id":"toolu_1","name":"getOrder","input":{}},{"type":"tool_use","id":"toolu_lost","name":"getOrder","input":{}}]';

        $entries = $this->buildEgress(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL)->buildOpenAiChatMessagesArray($rows);

        $assistantEntry = $entries[2];
        self::assertSame('assistant', $assistantEntry['role']);
        self::assertArrayNotHasKey(AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON, $assistantEntry, 'a call the loop did not keep has no result on the wire');
        self::assertSame('toolu_1', $assistantEntry['tool_calls'][0]['id']);
    }

    public function testAStepBelowTheThinkingFloorGoesBackWithoutItsThinking(): void
    {
        $rows = [$this->ownerTextRow(0, 'read order 4711'), $this->stepRow(1, 'clock 10:00'), $this->stepRow(2, 'clock 10:01')];
        $rows[1]->requestSnapshot->messagesWireFrozenFromMessageIndex = 0;
        $rows[2]->requestSnapshot->inputTransformations = [['type' => 'thinking_dropped', 'path' => 'messages.2.content.0', 'reason' => 'prefix_binding_mismatch']];

        $egress = $this->buildEgress(RunSettings::PREVIOUS_REASONING_CARRY_OVER_ALL);
        $entries = $egress->buildOpenAiChatMessagesArray($rows);

        self::assertTrue($entries[2][AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_WITHOUT_THINKING]);
        self::assertArrayNotHasKey(AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_WITHOUT_THINKING, $entries[5]);
        self::assertSame(2, $egress->lastMessagesWireReplay->thinkingFromMessageIndex);
    }

    public function testWithoutCarryOverTheHistoryCarriesNoStoredAnswerAndNoStoredNote(): void
    {
        $rows = [$this->ownerTextRow(0, 'read order 4711'), $this->stepRow(1, 'clock 10:00')];

        $entries = $this->buildEgress(RunSettings::PREVIOUS_REASONING_CARRY_OVER_OFF)->buildOpenAiChatMessagesArray($rows);

        self::assertSame(['user', 'assistant', 'tool'], array_column($entries, 'role'));
        self::assertArrayNotHasKey(AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON, $entries[1]);
    }

    public function testATurnBeforeTheFrozenPartIsRenderedAsOnEveryOtherWireApi(): void
    {
        $rows = [$this->ownerTextRow(0, 'read order 4711'), $this->stepRow(1, 'clock 10:00'), $this->ownerTextRow(2, 'and order 4712'), $this->stepRow(3, 'clock 10:05')];

        $entries = $this->buildEgress(RunSettings::PREVIOUS_REASONING_CARRY_OVER_CURRENT_TURN)->buildOpenAiChatMessagesArray($rows);

        self::assertSame(['user', 'assistant', 'tool', 'user', 'user', 'assistant', 'tool'], array_column($entries, 'role'));
        self::assertArrayNotHasKey(AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON, $entries[1], 'the earlier turn carries neither its stored answer nor its note');
        self::assertSame('clock 10:05', $entries[4]['content']);
        self::assertArrayHasKey(AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON, $entries[5]);
    }

    protected function buildEgress(string $previousReasoningCarryOver): ArgusAIAgentConversation
    {
        /** @var ArgusAIAgentConversation $egress */
        $egress = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        $agentWireConfiguration = new AgentWireConfiguration();
        $agentWireConfiguration->wireApi = RunSettings::AGENT_WIRE_API_MESSAGES;
        $agentWireConfiguration->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $agentWireConfiguration->previousReasoningCarryOver = $previousReasoningCarryOver;
        $agentWireConfiguration->previousReasoningCapTokens = 4000;
        $egress->agentWireConfiguration = $agentWireConfiguration;
        return $egress;
    }

    protected function ownerTextRow(int $messageIndex, string $text): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->message = $text;
        $message->indexInConversation = $messageIndex;
        return $message;
    }

    protected function stepRow(int $messageIndex, string $noteOfItsCall): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->indexInConversation = $messageIndex;
        $message->inputTokens = 100;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'toolu_' . $messageIndex;
        $toolCall->name = 'getOrder';
        $toolCall->arguments = ['orderId' => '4711'];
        $toolCall->result = '{"orderId":"4711","customerId":"C-17"}';
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $message->toolCall = $toolCall;
        $requestSnapshot = new RequestSnapshot();
        $requestSnapshot->modelName = 'ANTHROPIC.TEST';
        $requestSnapshot->wireReasoningApi = RunSettings::AGENT_WIRE_API_MESSAGES;
        $requestSnapshot->wireReasoningItemsJson = '[{"type":"thinking","thinking":"step ' . $messageIndex . '","signature":"sig-' . $messageIndex . '"},'
            . '{"type":"tool_use","id":"toolu_' . $messageIndex . '","name":"getOrder","input":{"orderId":"4711"}}]';
        $requestSnapshot->wireReasoningTokens = 300;
        $requestSnapshot->messagesWireTailJson = (string)json_encode([$noteOfItsCall]);
        $message->requestSnapshot = $requestSnapshot;
        return $message;
    }
}
