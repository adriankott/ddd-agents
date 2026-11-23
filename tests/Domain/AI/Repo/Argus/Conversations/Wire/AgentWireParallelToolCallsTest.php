<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Wire;

use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentChatCompletionsRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentResponsesRequestComposer;
use DDD\Domain\AI\Repo\Argus\Conversations\Wire\AgentWireRequestParameters;
use PHPUnit\Framework\TestCase;

/**
 * `parallel_tool_calls` goes out with tools only for a model that accepts it: a model whose allowed providers do not
 * (Kimi K3.1 Pro behind OpenRouter's require_parameters) would otherwise find no endpoint.
 */
class AgentWireParallelToolCallsTest extends TestCase
{
    public function testChatCompletionsCarriesTheFlagOnlyWhenTheModelAcceptsIt(): void
    {
        self::assertTrue(AgentChatCompletionsRequestComposer::composeBody($this->buildParameters(true))['parallel_tool_calls']);
        $body = AgentChatCompletionsRequestComposer::composeBody($this->buildParameters(false));
        self::assertArrayHasKey('tools', $body);
        self::assertArrayNotHasKey('parallel_tool_calls', $body);
    }

    public function testResponsesCarriesTheFlagOnlyWhenTheModelAcceptsIt(): void
    {
        self::assertTrue(AgentResponsesRequestComposer::composeBody($this->buildParameters(true))['parallel_tool_calls']);
        $body = AgentResponsesRequestComposer::composeBody($this->buildParameters(false));
        self::assertArrayHasKey('tools', $body);
        self::assertArrayNotHasKey('parallel_tool_calls', $body);
    }

    protected function buildParameters(bool $sendsParallelToolCalls): AgentWireRequestParameters
    {
        $agentWireRequestParameters = new AgentWireRequestParameters();
        $agentWireRequestParameters->modelId = 'probe/model';
        $agentWireRequestParameters->chatCompletionsMessages = [['role' => 'user', 'content' => 'hello']];
        $agentWireRequestParameters->chatCompletionsTools = [['type' => 'function', 'function' => ['name' => 'probeTool', 'parameters' => ['type' => 'object']]]];
        $agentWireRequestParameters->sendsParallelToolCalls = $sendsParallelToolCalls;
        return $agentWireRequestParameters;
    }
}
