<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\Probes\ExecutionRecordingDispatchService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An identical read the current turn already ran is answered from the earlier result without running the tool. A
 * result the executor returned in its structured shape carries its data in `structuredContent` (its words are only
 * "Successful response for '<tool>'"): the repeat gets that data again, without the fields the platform keeps for
 * itself; a markdown-rendered result gets its words, as before.
 */
class ReusedReadDispatchTest extends UnitTestCase
{
    public function testTheRepeatOfAStructuredShapeReadGetsTheStructuredDataAgain(): void
    {
        $earlierResult = json_encode([
            'content' => [['type' => 'text', 'text' => "Successful response for 'getOpeningHours'"]],
            'structuredContent' => [
                'openingHours' => [['weekday' => 'Tuesday', 'opens' => '09:00', 'closes' => '18:00']],
                'backgroundOperations' => [['kind' => 'refreshIndex']],
            ],
        ], JSON_THROW_ON_ERROR);

        $dispatchService = new ExecutionRecordingDispatchService();
        $repeatRow = $this->dispatchRepeatAfterEarlierRead($earlierResult, $dispatchService);

        self::assertSame([], $dispatchService->executedToolNames, 'the tool did not run again');
        self::assertTrue($repeatRow->toolCall->wasRefusedAsDuplicate);
        $resultText = (string)$repeatRow->toolCall->result;
        self::assertStringStartsWith('YOU ALREADY CALLED `getOpeningHours` WITH EXACTLY THESE ARGUMENTS A MOMENT AGO', $resultText);
        self::assertStringContainsString("Successful response for 'getOpeningHours'\n" . '{"openingHours":[{"weekday":"Tuesday","opens":"09:00","closes":"18:00"}]}', $resultText);
        self::assertStringNotContainsString('backgroundOperations', $resultText, 'a field the platform keeps for itself stays off');
    }

    public function testTheRepeatOfAMarkdownRenderedReadGetsItsWordsAgain(): void
    {
        $earlierResult = json_encode([
            'content' => [['type' => 'text', 'text' => 'Tuesday: 09:00–18:00']],
            'structuredContent' => ['outcome' => 'complete'],
        ], JSON_THROW_ON_ERROR);

        $repeatRow = $this->dispatchRepeatAfterEarlierRead($earlierResult, new ExecutionRecordingDispatchService());

        $resultText = (string)$repeatRow->toolCall->result;
        self::assertStringEndsWith("\n\nTuesday: 09:00–18:00", $resultText);
        self::assertStringNotContainsString('outcome', $resultText, 'the loop-control fields of a markdown result are no data');
    }

    /** An owner row, the read completed with `$earlierResult`, then the identical read dispatched by `$dispatchService`. */
    protected function dispatchRepeatAfterEarlierRead(string $earlierResult, ExecutionRecordingDispatchService $dispatchService): AIConversationMessage
    {
        $earlierReadRow = $this->readCallRow(1);
        $earlierReadRow->toolCall->result = $earlierResult;
        $earlierReadRow->toolCall->markCompleted();
        $repeatRow = $this->readCallRow(2);
        $conversation = $this->conversation($this->ownerRow(0), $earlierReadRow, $repeatRow);
        $dispatchService->dispatchToolCall(
            AgentRun::forTurnEntry(),
            $this->createStub(AgentChildTurnRunnerInterface::class),
            $conversation,
            $repeatRow->toolCall,
            $repeatRow,
            false,
            null,
            AgentTransientMessageIds::startingAt(0),
            null,
            false
        );
        return $repeatRow;
    }

    protected function readCallRow(int $index): AIConversationMessage
    {
        $message = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_' . $index;
        $toolCall->name = 'getOpeningHours';
        $toolCall->arguments = ['locationId' => 4];
        $toolCall->stampEffect(new MCPToolEffect(MCPToolEffect::READ));
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function ownerRow(int $index): AIConversationMessage
    {
        return $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'when are we open on Tuesday?', $index);
    }

    protected function row(string $role, string $authorType, string $text, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->indexInConversation = $index;
        return $message;
    }

    protected function conversation(AIConversationMessage ...$rows): AIConversation
    {
        $messages = new AIConversationMessages();
        foreach ($rows as $row) {
            $messages->add($row);
        }
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->messages = $messages;
        return $conversation;
    }
}
