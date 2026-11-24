<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/**
 * The agent's egress reads an answer of the Anthropic Messages API through the language-model reader's hooks for
 * another answer shape: the object is the answer itself (no envelope), its text, its usage and its calls come from
 * the package's own reader; a Chat-Completions answer still goes to the shared readers.
 */
class AgentEgressReadsAMessagesAnswerTest extends UnitTestCase
{
    protected const string MESSAGES_ANSWER = '{"type":"message","role":"assistant","content":['
        . '{"type":"thinking","thinking":"order first","signature":"sig"},'
        . '{"type":"text","text":"Reading the order."},'
        . '{"type":"tool_use","id":"toolu_1","name":"getOrder","input":{"orderId":"4711"}}],'
        . '"stop_reason":"tool_use","usage":{"input_tokens":3,"cache_read_input_tokens":6000,"cache_creation_input_tokens":200,"output_tokens":90,"output_tokens_details":{"thinking_tokens":40}}}';

    public function testAMessagesAnswerIsTheAnswerItselfAndIsReadByThePackagesReader(): void
    {
        $egress = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        $messagesAnswer = json_decode(self::MESSAGES_ANSWER);

        self::assertTrue($this->callHook($egress, 'isAnswerObjectWithoutEnvelope', $messagesAnswer));
        self::assertSame($messagesAnswer, $this->callHook($egress, 'unwrapAIModelResponse', $messagesAnswer));
        self::assertSame('Reading the order.', $this->callHook($egress, 'extractAnswerText', $messagesAnswer));
        $aiModelCallUsage = $this->callHook($egress, 'extractAnswerUsage', $messagesAnswer);
        self::assertSame(6203, $aiModelCallUsage->inputTokens);
        self::assertSame(6000, $aiModelCallUsage->cachedInputTokens);
        self::assertSame(200, $aiModelCallUsage->cacheWriteTokens);
        $toolCalls = $this->callHook($egress, 'extractToolCallsFromResponse', $messagesAnswer);
        self::assertSame('toolu_1', $toolCalls[0]->id);
        self::assertSame('{"orderId":"4711"}', $toolCalls[0]->function->arguments);
    }

    public function testAChatCompletionsAnswerStillGoesToTheSharedReaders(): void
    {
        $egress = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        $chatCompletionsAnswer = json_decode('{"choices":[{"message":{"content":"Done.","tool_calls":[{"id":"call_1","type":"function","function":{"name":"getOrder","arguments":"{}"}}]}}]}');

        self::assertTrue($this->callHook($egress, 'isAnswerObjectWithoutEnvelope', $chatCompletionsAnswer));
        self::assertNull($this->callHook($egress, 'extractAnswerText', $chatCompletionsAnswer), 'the text of a Chat-Completions answer is read by the shared reader after the hook');
        self::assertNull($this->callHook($egress, 'extractAnswerUsage', $chatCompletionsAnswer));
        self::assertSame('call_1', $this->callHook($egress, 'extractToolCallsFromResponse', $chatCompletionsAnswer)[0]->id);
    }

    protected function callHook(object $egress, string $methodName, object $answer): mixed
    {
        return (new ReflectionMethod($egress, $methodName))->invoke($egress, $answer);
    }
}
