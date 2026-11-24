<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentMessagesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentWireRequestParameters;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The body of an agent call on the Anthropic Messages API: the Chat-form conversation the egress composes becomes the
 * system blocks, the messages and the tools of that API — a stored answer goes back verbatim, the notes of a call are
 * `role: "system"` messages with `clear_at`, a skill load is a tool addition, and every request asks the provider to
 * drop a thinking block it cannot verify.
 */
class AgentMessagesRequestComposerTest extends UnitTestCase
{
    protected const string STORED_ANSWER = '[{"type":"thinking","thinking":"order first","signature":"sig-1"},{"type":"text","text":"I read the order."},{"type":"tool_use","id":"toolu_1","name":"getOrder","input":{}}]';

    public function testTheRequestCarriesAdaptiveThinkingWithTheDropBehaviourAndNoSamplingParameter(): void
    {
        $body = AgentMessagesRequestComposer::composeBody($this->buildParameters([
            ['role' => 'system', 'content' => 'You are an assistant.'],
            ['role' => 'user', 'content' => 'Hello'],
        ]));

        self::assertSame('anthropic/claude-test', $body['model']);
        self::assertSame(64000, $body['max_tokens']);
        self::assertSame(['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'drop_block']], $body['thinking']);
        self::assertSame(['effort' => 'high'], $body['output_config']);
        self::assertArrayNotHasKey('temperature', $body, 'the current Claude models refuse a non-default sampling parameter');
        self::assertSame([['type' => 'text', 'text' => 'You are an assistant.']], $body['system']);
        self::assertSame([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]]], $body['messages']);
        self::assertSame(['only' => ['anthropic'], 'allow_fallbacks' => false], $body['provider']);
    }

    public function testTheHeadersNameTheApiVersionAndTheThreeBetas(): void
    {
        $headers = AgentMessagesRequestComposer::buildHeaders();

        self::assertSame('2023-06-01', $headers['anthropic-version']);
        self::assertSame(
            'thinking-binding-controls-2026-08-01,mid-conversation-tool-changes-2026-07-01,mid-conversation-system-clear-at-2026-08-21',
            $headers['anthropic-beta']
        );
    }

    public function testTheSystemPartsKeepTheirCacheMarkers(): void
    {
        $body = AgentMessagesRequestComposer::composeBody($this->buildParameters([
            ['role' => 'system', 'content' => [
                ['type' => 'text', 'text' => 'Base prompt.', 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => 'Skills.'],
            ]],
            ['role' => 'user', 'content' => 'Hello'],
        ]));

        self::assertSame([
            ['type' => 'text', 'text' => 'Base prompt.', 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => 'Skills.'],
        ], $body['system']);
    }

    public function testAStoredAnswerGoesBackVerbatimAndTheResultsOfOneStepShareOneUserMessage(): void
    {
        $body = AgentMessagesRequestComposer::composeBody($this->buildParameters([
            ['role' => 'system', 'content' => 'S'],
            ['role' => 'user', 'content' => 'Read both orders.'],
            [
                'role' => 'assistant',
                'content' => 'I read the order.',
                'tool_calls' => [['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'getOrder', 'arguments' => '{"superseded":true}']]],
                AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON => self::STORED_ANSWER,
            ],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => 'order 1'],
            ['role' => 'tool', 'tool_call_id' => 'toolu_2', 'content' => 'order 2'],
        ]));

        $bodyJson = (string)json_encode($body['messages'], JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString(substr(self::STORED_ANSWER, 1, -1), $bodyJson, 'the blocks go back byte for byte: the signature, and the empty input as an object');
        self::assertStringNotContainsString('superseded', $bodyJson, 'the rows\' own rendering of the call is not sent for a stored answer');
        self::assertCount(3, $body['messages']);
        self::assertSame('user', $body['messages'][2]['role']);
        self::assertSame([
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'order 1'],
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_2', 'content' => 'order 2'],
        ], $body['messages'][2]['content']);
    }

    public function testAStoredAnswerBelowTheThinkingFloorGoesBackWithoutItsThinkingBlocks(): void
    {
        $body = AgentMessagesRequestComposer::composeBody($this->buildParameters([
            ['role' => 'user', 'content' => 'Read the order.'],
            [
                'role' => 'assistant',
                'content' => '',
                AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON => self::STORED_ANSWER,
                AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_WITHOUT_THINKING => true,
            ],
            ['role' => 'tool', 'tool_call_id' => 'toolu_1', 'content' => 'order 1'],
        ]));

        self::assertSame(
            '[{"type":"text","text":"I read the order."},{"type":"tool_use","id":"toolu_1","name":"getOrder","input":{}}]',
            json_encode($body['messages'][1]['content'], JSON_UNESCAPED_SLASHES)
        );
    }

    public function testAStepWithoutAStoredAnswerIsBuiltFromItsTextAndItsCalls(): void
    {
        $body = AgentMessagesRequestComposer::composeBody($this->buildParameters([
            ['role' => 'user', 'content' => 'Read the order.'],
            [
                'role' => 'assistant',
                'content' => 'Reading.',
                'tool_calls' => [
                    ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'getOrder', 'arguments' => '{"orderId":"4711"}']],
                    ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'listOrders', 'arguments' => '{}']],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'order'],
            ['role' => 'tool', 'tool_call_id' => 'call_2', 'content' => ''],
        ]));

        self::assertSame(
            '[{"type":"text","text":"Reading."},{"type":"tool_use","id":"call_1","name":"getOrder","input":{"orderId":"4711"}},{"type":"tool_use","id":"call_2","name":"listOrders","input":{}}]',
            json_encode($body['messages'][1]['content'], JSON_UNESCAPED_SLASHES)
        );
        self::assertSame(['type' => 'tool_result', 'tool_use_id' => 'call_2'], $body['messages'][2]['content'][1], 'an empty result carries no content');
    }

    public function testANoteOfACallIsASystemMessageWithClearAtAndStaysApartFromOtherSystemMessages(): void
    {
        $body = AgentMessagesRequestComposer::composeBody($this->buildParameters([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'developer', 'content' => 'Skill instructions.'],
            [
                'role' => 'user',
                'content' => '[SIGNAL:clock] 10:42',
                AgentMessagesRequestComposer::KEY_AS_SYSTEM_MESSAGE => true,
                AgentMessagesRequestComposer::KEY_CLEAR_AT => AgentMessagesRequestComposer::CLEAR_AT_NEXT_USER_MESSAGE,
            ],
        ]));

        self::assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Skill instructions.']]],
            ['role' => 'system', 'clear_at' => 'next_user_message', 'content' => [['type' => 'text', 'text' => '[SIGNAL:clock] 10:42']]],
        ], $body['messages']);
    }

    public function testASkillLoadIsAToolAdditionAndItsToolsAreDeclaredDeferred(): void
    {
        $parameters = $this->buildParameters([
            ['role' => 'user', 'content' => 'Hello'],
            ['type' => 'additional_tools', 'role' => 'developer', 'tools' => [$this->buildChatTool('getInvoice'), $this->buildChatTool('getOrder')]],
            ['role' => 'developer', 'content' => 'Invoices: call getInvoice.'],
        ]);
        $parameters->chatCompletionsTools = [$this->buildChatTool('getOrder'), $this->buildChatTool('listOrders', [])];

        $body = AgentMessagesRequestComposer::composeBody($parameters);

        self::assertSame(['getOrder', 'listOrders', 'getInvoice'], array_column($body['tools'], 'name'), 'a tool offered both ways is declared once');
        self::assertArrayNotHasKey('defer_loading', $body['tools'][0]);
        self::assertTrue($body['tools'][2]['defer_loading']);
        self::assertSame('{"type":"object","properties":{}}', json_encode($body['tools'][1]['input_schema']), 'an empty property list is an object');
        self::assertSame([
            'role' => 'system',
            'content' => [
                ['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => 'getInvoice']],
                ['type' => 'tool_addition', 'tool' => ['type' => 'tool_reference', 'name' => 'getOrder']],
                ['type' => 'text', 'text' => 'Invoices: call getInvoice.'],
            ],
        ], $body['messages'][1]);
    }

    public function testTheToolChoiceIsTranslatedAndASingleCallModelDisablesParallelUse(): void
    {
        $parameters = $this->buildParameters([['role' => 'user', 'content' => 'Hello']]);
        $parameters->chatCompletionsTools = [$this->buildChatTool('getOrder')];

        $parameters->chatCompletionsToolChoice = 'auto';
        self::assertSame(['type' => 'auto'], AgentMessagesRequestComposer::composeBody($parameters)['tool_choice']);
        $parameters->chatCompletionsToolChoice = 'none';
        self::assertSame(['type' => 'none'], AgentMessagesRequestComposer::composeBody($parameters)['tool_choice']);
        $parameters->chatCompletionsToolChoice = 'required';
        self::assertSame(['type' => 'any'], AgentMessagesRequestComposer::composeBody($parameters)['tool_choice']);
        $parameters->chatCompletionsToolChoice = ['type' => 'function', 'function' => ['name' => 'getOrder']];
        self::assertSame(['type' => 'tool', 'name' => 'getOrder'], AgentMessagesRequestComposer::composeBody($parameters)['tool_choice']);

        $parameters->chatCompletionsToolChoice = 'auto';
        $parameters->sendsParallelToolCalls = false;
        self::assertSame(['type' => 'auto', 'disable_parallel_tool_use' => true], AgentMessagesRequestComposer::composeBody($parameters)['tool_choice']);
    }

    public function testAPictureOfAToolResultIsAnImageBlockAndACacheMarkerMovesToTheResultBlock(): void
    {
        $body = AgentMessagesRequestComposer::composeBody($this->buildParameters([
            ['role' => 'user', 'content' => 'Look.'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'screenshot', 'arguments' => '{}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => [
                ['type' => 'text', 'text' => 'The page.', 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
            ]],
        ]));

        self::assertSame([
            'type' => 'tool_result',
            'tool_use_id' => 'call_1',
            'cache_control' => ['type' => 'ephemeral'],
            'content' => [
                ['type' => 'text', 'text' => 'The page.'],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'AAAA']],
            ],
        ], $body['messages'][2]['content'][0]);
    }

    public function testACacheMarkerOnAnEntryWithAStoredAnswerMovesToItsNewestBlockThatIsNoThinking(): void
    {
        $body = AgentMessagesRequestComposer::composeBody($this->buildParameters([
            ['role' => 'user', 'content' => 'Read the order.'],
            [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'I read the order.', 'cache_control' => ['type' => 'ephemeral']]],
                AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON => '[{"type":"text","text":"Done."},{"type":"thinking","thinking":"t","signature":"s"}]',
            ],
        ]));

        self::assertSame(
            '[{"type":"text","text":"Done.","cache_control":{"type":"ephemeral"}},{"type":"thinking","thinking":"t","signature":"s"}]',
            json_encode($body['messages'][1]['content'], JSON_UNESCAPED_SLASHES)
        );
    }

    public function testTheKeysOfThisWireAreRemovedForTheViewsOfAReconstructedCall(): void
    {
        $cleanedEntries = AgentMessagesRequestComposer::withoutEntryKeysOfThisWire([[
            'role' => 'assistant',
            'content' => 'x',
            AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_JSON => self::STORED_ANSWER,
            AgentMessagesRequestComposer::KEY_ASSISTANT_CONTENT_WITHOUT_THINKING => true,
        ]]);

        self::assertSame([['role' => 'assistant', 'content' => 'x']], $cleanedEntries);
        self::assertSame(['toolu_1'], AgentMessagesRequestComposer::readToolUseIdsOfStoredContent(self::STORED_ANSWER));
    }

    /**
     * @param array<int, array<string, mixed>> $chatCompletionsMessages
     */
    protected function buildParameters(array $chatCompletionsMessages): AgentWireRequestParameters
    {
        $agentWireRequestParameters = new AgentWireRequestParameters();
        $agentWireRequestParameters->modelId = 'anthropic/claude-test';
        $agentWireRequestParameters->maxOutputTokens = 64000;
        $agentWireRequestParameters->temperature = 0.7;
        $agentWireRequestParameters->reasoningEffort = 'high';
        $agentWireRequestParameters->chatCompletionsMessages = $chatCompletionsMessages;
        $agentWireRequestParameters->providerPreference = ['only' => ['anthropic'], 'allow_fallbacks' => false];
        return $agentWireRequestParameters;
    }

    /**
     * @param array<string, mixed>|null $properties
     * @return array<string, mixed>
     */
    protected function buildChatTool(string $toolName, ?array $properties = null): array
    {
        return ['type' => 'function', 'function' => [
            'name' => $toolName,
            'description' => 'Tool ' . $toolName,
            'parameters' => ['type' => 'object', 'properties' => $properties ?? ['id' => ['type' => 'string']]],
        ]];
    }
}
