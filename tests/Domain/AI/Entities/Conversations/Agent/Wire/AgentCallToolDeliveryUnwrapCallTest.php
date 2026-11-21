<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentCallToolDelivery;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A `call_tool` call is unwrapped into the call of the tool it names, in every form the model writes it: proper,
 * arguments as a JSON string, arguments beside `name`, wrapped twice; a call without a tool name stays as it is; a
 * direct call of an undeclared tool is only flagged. The wrapped arguments JSON is what the history sends back.
 */
class AgentCallToolDeliveryUnwrapCallTest extends UnitTestCase
{
    protected const array TOP_LEVEL_TOOL_NAMES = ['load_skills', 'update_task_plan', 'call_tool'];

    public function testAProperCallIsUnwrappedIntoTheNamedToolAndFlagged(): void
    {
        $toolCall = $this->buildToolCall('call_tool', ['name' => ' getReviews ', 'arguments' => ['locationId' => 7]]);

        AgentCallToolDelivery::unwrapCall($toolCall, self::TOP_LEVEL_TOOL_NAMES);

        self::assertSame('getReviews', $toolCall->name, 'the name is trimmed');
        self::assertSame(['locationId' => 7], $toolCall->arguments);
        self::assertTrue($toolCall->deliveredThroughCallTool);
        self::assertSame('call_1', $toolCall->toolCallId, 'the call id stays');
    }

    public function testArgumentsWrittenAsAJsonStringAreDecoded(): void
    {
        $toolCall = $this->buildToolCall('call_tool', ['name' => 'getReviews', 'arguments' => '{"locationId":7}']);

        AgentCallToolDelivery::unwrapCall($toolCall, self::TOP_LEVEL_TOOL_NAMES);

        self::assertSame('getReviews', $toolCall->name);
        self::assertSame(['locationId' => 7], $toolCall->arguments);
        self::assertTrue($toolCall->deliveredThroughCallTool);
    }

    public function testArgumentsAsAStringThatIsNoJsonObjectBecomeNoArguments(): void
    {
        $toolCall = $this->buildToolCall('call_tool', ['name' => 'getReviews', 'arguments' => 'not json']);

        AgentCallToolDelivery::unwrapCall($toolCall, self::TOP_LEVEL_TOOL_NAMES);

        self::assertSame('getReviews', $toolCall->name);
        self::assertSame([], $toolCall->arguments);
    }

    public function testArgumentsWrittenBesideTheNameAreTakenAsTheToolsArguments(): void
    {
        $toolCall = $this->buildToolCall('call_tool', ['name' => 'getReviews', 'locationId' => 7, 'limit' => 3]);

        AgentCallToolDelivery::unwrapCall($toolCall, self::TOP_LEVEL_TOOL_NAMES);

        self::assertSame('getReviews', $toolCall->name);
        self::assertSame(['locationId' => 7, 'limit' => 3], $toolCall->arguments);
        self::assertTrue($toolCall->deliveredThroughCallTool);
    }

    public function testACallWithoutArgumentsGetsNoArguments(): void
    {
        $toolCall = $this->buildToolCall('call_tool', ['name' => 'getReviews']);

        AgentCallToolDelivery::unwrapCall($toolCall, self::TOP_LEVEL_TOOL_NAMES);

        self::assertSame('getReviews', $toolCall->name);
        self::assertSame([], $toolCall->arguments);
    }

    public function testACallWrappedTwiceIsUnwrappedToTheInnerTool(): void
    {
        $toolCall = $this->buildToolCall('call_tool', ['name' => 'call_tool', 'arguments' => ['name' => 'getReviews', 'arguments' => ['locationId' => 7]]]);

        AgentCallToolDelivery::unwrapCall($toolCall, self::TOP_LEVEL_TOOL_NAMES);

        self::assertSame('getReviews', $toolCall->name);
        self::assertSame(['locationId' => 7], $toolCall->arguments);
        self::assertTrue($toolCall->deliveredThroughCallTool);
    }

    public function testACallWrappedTwiceWithTheInnerArgumentsBesideTheNameIsUnwrapped(): void
    {
        $toolCall = $this->buildToolCall('call_tool', ['name' => 'call_tool', 'arguments' => ['name' => 'getReviews', 'locationId' => 7]]);

        AgentCallToolDelivery::unwrapCall($toolCall, self::TOP_LEVEL_TOOL_NAMES);

        self::assertSame('getReviews', $toolCall->name);
        self::assertSame(['locationId' => 7], $toolCall->arguments);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function callsWithoutAToolName(): array
    {
        return [
            'no name' => [['arguments' => ['locationId' => 7]]],
            'blank name' => [['name' => '  ', 'arguments' => []]],
            'name not a string' => [['name' => 7, 'arguments' => []]],
            'call_tool naming itself without an inner name' => [['name' => 'call_tool', 'arguments' => ['locationId' => 7]]],
            'wrapped three times' => [['name' => 'call_tool', 'arguments' => ['name' => 'call_tool', 'arguments' => []]]],
        ];
    }

    /**
     * @param array<string, mixed> $wrapperArguments
     */
    #[DataProvider('callsWithoutAToolName')]
    public function testACallWithoutAToolNameStaysAsItIs(array $wrapperArguments): void
    {
        $toolCall = $this->buildToolCall('call_tool', $wrapperArguments);

        AgentCallToolDelivery::unwrapCall($toolCall, self::TOP_LEVEL_TOOL_NAMES);

        self::assertSame('call_tool', $toolCall->name);
        self::assertSame($wrapperArguments, $toolCall->arguments);
        self::assertFalse($toolCall->deliveredThroughCallTool, 'the dispatch answers it with the call form');
    }

    public function testADirectCallOfAnUndeclaredToolIsOnlyFlagged(): void
    {
        $toolCall = $this->buildToolCall('getReviews', ['locationId' => 7]);

        AgentCallToolDelivery::unwrapCall($toolCall, self::TOP_LEVEL_TOOL_NAMES);

        self::assertSame('getReviews', $toolCall->name);
        self::assertSame(['locationId' => 7], $toolCall->arguments);
        self::assertTrue($toolCall->deliveredThroughCallTool, 'the history carries it as a call_tool call');
    }

    public function testADirectCallOfADeclaredToolOrWithoutKnownDeclarationsIsLeftAlone(): void
    {
        $declaredCall = $this->buildToolCall('load_skills', ['skill_names' => ['Shop.Orders']]);
        AgentCallToolDelivery::unwrapCall($declaredCall, self::TOP_LEVEL_TOOL_NAMES);
        self::assertFalse($declaredCall->deliveredThroughCallTool);

        $callWithoutDeclarations = $this->buildToolCall('getReviews', []);
        AgentCallToolDelivery::unwrapCall($callWithoutDeclarations, []);
        self::assertFalse($callWithoutDeclarations->deliveredThroughCallTool, 'no declared tools known: nothing to compare with');

        $callWithoutName = $this->buildToolCall('', []);
        AgentCallToolDelivery::unwrapCall($callWithoutName, self::TOP_LEVEL_TOOL_NAMES);
        self::assertFalse($callWithoutName->deliveredThroughCallTool);
    }

    public function testTheWrappedArgumentsJsonCarriesTheNameAndTheEncodedArguments(): void
    {
        self::assertSame(
            '{"name":"getReviews","arguments":{"locationId":7}}',
            AgentCallToolDelivery::buildWrappedArgumentsJson('getReviews', '{"locationId":7}')
        );
        self::assertSame('{"name":"get\"Reviews","arguments":{}}', AgentCallToolDelivery::buildWrappedArgumentsJson('get"Reviews', '{}'), 'the name is JSON-escaped');
        self::assertSame(
            ['name' => 'getReviews', 'arguments' => ['locationId' => 7]],
            json_decode(AgentCallToolDelivery::buildWrappedArgumentsJson('getReviews', '{"locationId":7}'), true)
        );
    }

    /** @param array<string, mixed> $arguments */
    protected function buildToolCall(string $name, array $arguments): ToolCall
    {
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_1';
        $toolCall->name = $name;
        $toolCall->arguments = $arguments;
        return $toolCall;
    }
}
