<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentCallToolDelivery;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A call the model made through `call_tool` carries the real tool's name and arguments, and the history sends it back
 * as the `call_tool` call the model wrote — plainly and in the stubbed form, which keeps the tool's name. An unflagged
 * call renders as before.
 */
class ToolCallDeliveredThroughCallToolHistoryTest extends UnitTestCase
{
    public function testAFlaggedCallIsRenderedBackAsTheCallToolCall(): void
    {
        $toolCall = $this->buildToolCall('getReviews', ['locationId' => 7]);
        $toolCall->deliveredThroughCallTool = true;

        self::assertSame(
            [
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'call_tool', 'arguments' => '{"name":"getReviews","arguments":{"locationId":7}}'],
            ],
            $toolCall->toOpenAiToolCallArray()
        );
    }

    public function testAFlaggedCallWithoutArgumentsWrapsAnEmptyObject(): void
    {
        $toolCall = $this->buildToolCall('getReviews', []);
        $toolCall->deliveredThroughCallTool = true;

        self::assertSame('{"name":"getReviews","arguments":{}}', $toolCall->toOpenAiToolCallArray()['function']['arguments']);
    }

    public function testAnUnflaggedCallIsRenderedAsTheToolsOwnCall(): void
    {
        $toolCall = $this->buildToolCall('getReviews', ['locationId' => 7]);

        self::assertFalse($toolCall->deliveredThroughCallTool, 'off by default');
        self::assertSame(
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'getReviews', 'arguments' => '{"locationId":7}']],
            $toolCall->toOpenAiToolCallArray()
        );
    }

    public function testTheUnwrappedCallRoundTripsToTheCallTheModelWrote(): void
    {
        $modelWrittenArguments = ['name' => 'getReviews', 'arguments' => ['locationId' => 7, 'filter' => ['status' => 'open']]];
        $toolCall = $this->buildToolCall('call_tool', $modelWrittenArguments);

        AgentCallToolDelivery::unwrapCall($toolCall, ['load_skills', 'call_tool']);
        $historyEntry = $toolCall->toOpenAiToolCallArray();

        self::assertSame('call_tool', $historyEntry['function']['name']);
        self::assertSame($modelWrittenArguments, json_decode($historyEntry['function']['arguments'], true));
    }

    public function testTheStubbedFormOfAFlaggedCallKeepsTheToolName(): void
    {
        $flaggedToolCall = $this->buildToolCall('update_task_plan', ['tasks' => ['a', 'b']]);
        $flaggedToolCall->deliveredThroughCallTool = true;

        $stubbedEntry = $this->buildToolCallRow($flaggedToolCall)->toOpenAiToolCallEntryArray(true);

        self::assertSame('call_tool', $stubbedEntry['function']['name']);
        self::assertSame('{"name":"update_task_plan","arguments":{"superseded":true}}', $stubbedEntry['function']['arguments']);
        self::assertSame('call_1', $stubbedEntry['id']);
    }

    public function testTheStubbedFormOfAnUnflaggedCallIsUnchanged(): void
    {
        $stubbedEntry = $this->buildToolCallRow($this->buildToolCall('update_task_plan', ['tasks' => ['a']]))->toOpenAiToolCallEntryArray(true);

        self::assertSame('update_task_plan', $stubbedEntry['function']['name']);
        self::assertSame('{"superseded":true}', $stubbedEntry['function']['arguments']);
    }

    public function testTheFlagSurvivesThePersistedForm(): void
    {
        $toolCall = $this->buildToolCall('getReviews', ['locationId' => 7]);
        $toolCall->deliveredThroughCallTool = true;

        $storedObject = json_decode((string)json_encode($toolCall->toObject(forPersistence: true)));
        $rehydratedToolCall = new ToolCall();
        $rehydratedToolCall->setPropertiesFromObject($storedObject);

        self::assertTrue($rehydratedToolCall->deliveredThroughCallTool);
        self::assertSame('getReviews', $rehydratedToolCall->name);
        self::assertSame('call_tool', $rehydratedToolCall->toOpenAiToolCallArray()['function']['name']);
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

    protected function buildToolCallRow(ToolCall $toolCall): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->toolCall = $toolCall;
        return $message;
    }
}
