<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Tests\Agents\Presentation\Services\Probes\StubbedResponseToolExecutor;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A FINAL refusal of the executor reaches the agent loop as such: the tool result carries it beside the gate kind, and
 * the refusal of a mandate the platform cannot obtain here (a sub-agent, a scheduled run) is one.
 */
class MCPToolExecutorFinalRefusalTest extends UnitTestCase
{
    public function testTheToolResultCarriesAFinalRefusal(): void
    {
        $executor = new StubbedResponseToolExecutor();
        $executor->stubbedResponse = MCPToolResponse::finalRefusal('Not executed. Do not retry.', MCPToolResponse::REFUSAL_MANDATE_REQUIRED);

        $toolResult = $executor->callExecuteTool('publishOffer', ['offerId' => 4]);

        self::assertTrue($toolResult->isError);
        self::assertTrue($toolResult->refusalIsFinal);
        self::assertSame(MCPToolResponse::REFUSAL_MANDATE_REQUIRED, $toolResult->refusalKind);
        self::assertStringNotContainsString('refusalIsFinal', $toolResult->content, 'the model reads the refusal text, not the flag');
    }

    public function testAnErrorOrAGateRefusalThatMayOpenIsNoFinalRefusal(): void
    {
        $executor = new StubbedResponseToolExecutor();
        $executor->stubbedResponse = MCPToolResponse::gateRefusal('Not executed: ask the customer.', MCPToolResponse::REFUSAL_MANDATE_REQUIRED);
        self::assertFalse($executor->callExecuteTool('publishOffer', ['offerId' => 4])->refusalIsFinal);

        $executor->stubbedResponse = MCPToolResponse::error('Tool error: timeout.');
        self::assertFalse($executor->callExecuteTool('publishOffer', ['offerId' => 4])->refusalIsFinal);
    }

    public function testTheMandateRefusalWhereThePlatformCannotObtainTheMandateIsFinal(): void
    {
        $refusal = (new StubbedResponseToolExecutor())->callBuildMandateRequiredRefusal('publishOffer', 'ONE_INSTRUCTION_ACTION', 'publish an offer');

        self::assertTrue($refusal->isFinalRefusal());
        self::assertSame(MCPToolResponse::REFUSAL_MANDATE_REQUIRED, $refusal->refusalKind, 'still a gate refusal for the repeat guards');
        self::assertStringEndsWith('Do not retry here.', $refusal->content[0]['text']);
    }
}
