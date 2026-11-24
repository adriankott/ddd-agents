<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentMessagesResponse;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An answer of the Anthropic Messages API as the agent reads it: the content blocks for the verbatim replay, the
 * thinking it carries, the blocks the provider dropped from the request and an answer with nothing to act on.
 */
class AgentMessagesResponseTest extends UnitTestCase
{
    public function testTheContentBlocksAreKeptAsTheyCameWithAnEmptyInputAsAnObject(): void
    {
        $contentJson = '[{"type":"thinking","thinking":"plan","signature":"sig"},{"type":"tool_use","id":"toolu_1","name":"listOrders","input":{}}]';
        $agentMessagesResponse = $this->buildResponse('{"type":"message","content":' . $contentJson . ',"stop_reason":"tool_use","usage":{"output_tokens":90,"output_tokens_details":{"thinking_tokens":41}}}');

        self::assertSame($contentJson, $agentMessagesResponse->getContentBlocksJson());
        self::assertSame(1, $agentMessagesResponse->countThinkingBlocks());
        self::assertSame(41, $agentMessagesResponse->getThinkingTokens());
        self::assertSame([], $agentMessagesResponse->getInputTransformations());
        self::assertNull($agentMessagesResponse->findUnusableAnswerReason());
    }

    public function testTheTextBlocksAreTheAnswerAndTheThinkingIsNot(): void
    {
        $agentMessagesResponse = $this->buildResponse('{"id":"msg_1","type":"message","role":"assistant","content":['
            . '{"type":"thinking","thinking":"The order comes first.","signature":"c2ln"},'
            . '{"type":"text","text":"The customer lives in Munich."},'
            . '{"type":"text","text":"Both orders go there."}],"stop_reason":"end_turn"}');

        self::assertSame("The customer lives in Munich.\nBoth orders go there.", $agentMessagesResponse->getText());
        self::assertNull($this->buildResponse('{"type":"message","content":[{"type":"tool_use","id":"toolu_1","name":"get_order","input":{}}]}')->getText());
    }

    public function testTheBookedInputIsTheSumOfTheUncachedTheReadAndTheWrittenTokens(): void
    {
        $aiModelCallUsage = $this->buildResponse('{"type":"message","content":[{"type":"text","text":"Done."}],'
            . '"usage":{"input_tokens":2,"cache_read_input_tokens":5869,"cache_creation_input_tokens":185,"output_tokens":120,"output_tokens_details":{"thinking_tokens":40}}}')->getUsage();

        self::assertSame(6056, $aiModelCallUsage->inputTokens);
        self::assertSame(120, $aiModelCallUsage->outputTokens);
        self::assertSame(5869, $aiModelCallUsage->cachedInputTokens);
        self::assertSame(185, $aiModelCallUsage->cacheWriteTokens);
        self::assertSame(40, $aiModelCallUsage->reasoningOutputTokens);
        self::assertNull($this->buildResponse('{"type":"message","content":[]}')->getUsage());
    }

    public function testTheToolUseBlocksAreReadInTheChatCompletionsShape(): void
    {
        $toolCalls = $this->buildResponse('{"type":"message","role":"assistant","content":['
            . '{"type":"thinking","thinking":"","signature":"c2ln"},'
            . '{"type":"tool_use","id":"toolu_1","name":"get_order","input":{"orderId":"4711","filter":{}}},'
            . '{"type":"tool_use","id":"toolu_2","name":"list_orders","input":{}}],"stop_reason":"tool_use"}')->getToolCallsInChatCompletionsShape();

        self::assertCount(2, $toolCalls);
        self::assertSame('toolu_1', $toolCalls[0]->id);
        self::assertSame('function', $toolCalls[0]->type);
        self::assertSame('get_order', $toolCalls[0]->function->name);
        self::assertSame('{"orderId":"4711","filter":{}}', $toolCalls[0]->function->arguments);
        self::assertSame('{}', $toolCalls[1]->function->arguments);
        self::assertNull($this->buildResponse('{"type":"message","content":[{"type":"text","text":"Done."}]}')->getToolCallsInChatCompletionsShape());
    }

    public function testTheDroppedThinkingBlocksOfTheRequestAreRead(): void
    {
        $agentMessagesResponse = $this->buildResponse(
            '{"type":"message","content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn",'
            . '"input_transformations":[{"type":"thinking_dropped","path":"messages.4.content.0","reason":"prefix_binding_mismatch"},'
            . '{"type":"thinking_dropped","path":"messages.6.content.0","reason":"model_binding_mismatch"}]}'
        );

        $inputTransformations = $agentMessagesResponse->getInputTransformations();

        self::assertCount(2, $inputTransformations);
        self::assertTrue($inputTransformations[0]->isDroppedThinking());
        self::assertSame(['type' => 'thinking_dropped', 'path' => 'messages.4.content.0', 'reason' => AgentMessagesResponse::REASON_PREFIX_BINDING_MISMATCH], $inputTransformations[0]->toArray());
        self::assertSame(AgentMessagesResponse::REASON_MODEL_BINDING_MISMATCH, $inputTransformations[1]->reason);
    }

    public function testARefusalAndAnAnswerCutOffBeforeAnyTextAreUnusable(): void
    {
        $refusal = $this->buildResponse('{"type":"message","content":[],"stop_reason":"refusal","stop_details":{"category":"cyber"}}');
        self::assertSame('the model refused the request (cyber)', $refusal->findUnusableAnswerReason());
        self::assertNull($refusal->getContentBlocksJson(), 'an answer without a block stores nothing');

        $cutOff = $this->buildResponse('{"type":"message","content":[{"type":"thinking","thinking":"long","signature":"s"}],"stop_reason":"max_tokens"}');
        self::assertSame('the answer was cut off at the output limit before any text or call', $cutOff->findUnusableAnswerReason());

        $cutOffWithText = $this->buildResponse('{"type":"message","content":[{"type":"text","text":"partial"}],"stop_reason":"max_tokens"}');
        self::assertNull($cutOffWithText->findUnusableAnswerReason(), 'a cut-off answer with text is read as it is');
    }

    public function testOnlyAMessageObjectWithAContentListIsAMessagesAnswer(): void
    {
        self::assertTrue(AgentMessagesResponse::isMessagesObject(json_decode('{"type":"message","content":[]}')));
        self::assertFalse(AgentMessagesResponse::isMessagesObject(json_decode('{"choices":[{"message":{"content":"x"}}]}')));
        self::assertFalse(AgentMessagesResponse::isMessagesObject(json_decode('{"type":"error","error":{"message":"x"}}')));
        self::assertFalse(AgentMessagesResponse::isMessagesObject(null));
    }

    protected function buildResponse(string $responseJson): AgentMessagesResponse
    {
        return AgentMessagesResponse::fromResponseObject(json_decode($responseJson));
    }
}
