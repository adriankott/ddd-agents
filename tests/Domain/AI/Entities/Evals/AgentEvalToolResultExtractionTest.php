<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalToolResultExtraction;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Reading one value out of a tool result: by path, by pattern, from the last completed call of a tool, and never from
 * a call that updated a record that existed before the run — a cleanup deletes what the agent CREATED. A create
 * carries no id argument (or `0`), an update carries the id of an existing record.
 */
class AgentEvalToolResultExtractionTest extends UnitTestCase
{
    /**
     * Tool-call rows of a driven conversation: each [name, arguments, status, stored result].
     *
     * @param array<int, array{0: string, 1: array<string, mixed>, 2: string, 3: ?string}> $toolCalls
     */
    protected function messagesOfToolCalls(array $toolCalls): AIConversationMessages
    {
        $messages = new AIConversationMessages();
        foreach ($toolCalls as $indexInConversation => [$toolName, $toolArguments, $status, $storedResult]) {
            /** @var AIConversationMessage $message */
            $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
            $message->role = AIConversationMessage::ROLE_ASSISTANT;
            $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
            $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
            $message->message = '';
            $message->indexInConversation = $indexInConversation;
            /** @var ToolCall $toolCall */
            $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
            $toolCall->name = $toolName;
            $toolCall->arguments = $toolArguments;
            $toolCall->status = $status;
            $toolCall->result = $storedResult;
            $message->toolCall = $toolCall;
            $messages->add($message);
        }
        return $messages;
    }

    public function testACreateCallCarriesNoIdWhileAnUpdateDoes(): void
    {
        $extraction = AgentEvalToolResultExtraction::fromExtractionBlock([]);

        self::assertFalse($extraction->toolCallCarriesAnyArgument(['content' => 'Eval note', 'spaceRef' => 6615910], ['noteId']));
        self::assertFalse($extraction->toolCallCarriesAnyArgument(['noteId' => 0, 'content' => 'Eval note'], ['noteId']));
        self::assertFalse($extraction->toolCallCarriesAnyArgument(['noteId' => null, 'content' => 'Eval note'], ['noteId']));
        self::assertFalse($extraction->toolCallCarriesAnyArgument(null, ['noteId']));

        self::assertTrue($extraction->toolCallCarriesAnyArgument(['noteId' => 7612430, 'content' => 'Book the summer session now!'], ['noteId']));
        self::assertTrue($extraction->toolCallCarriesAnyArgument(['noteId' => '7612430'], ['noteId']));
    }

    public function testEmptyStringZeroStringAndFalseCountAsAbsent(): void
    {
        $extraction = AgentEvalToolResultExtraction::fromExtractionBlock([]);

        self::assertFalse($extraction->toolCallCarriesAnyArgument(['noteId' => '', 'otherId' => '0', 'thirdId' => false], ['noteId', 'otherId', 'thirdId']));
        self::assertTrue($extraction->toolCallCarriesAnyArgument(['noteId' => '', 'otherId' => 3], ['noteId', 'otherId']));
    }

    public function testThePathIsReadFirstAndThePatternOnlyWhenThePathFoundNothing(): void
    {
        $result = '{"content":[{"type":"text","text":"[#77 · NOTE]"}],"structuredContent":{"note":{"id":12}},"isError":false}';

        self::assertSame(12, AgentEvalToolResultExtraction::fromExtractionBlock(['path' => 'note.id', 'regex' => '\[#(\d+)'])->resolveValueFromToolResult($result));
        self::assertSame('77', AgentEvalToolResultExtraction::fromExtractionBlock(['path' => 'note.missing', 'regex' => '\[#(\d+)'])->resolveValueFromToolResult($result));
        self::assertNull(AgentEvalToolResultExtraction::fromExtractionBlock(['path' => 'note.missing'])->resolveValueFromToolResult($result));
        self::assertNull(AgentEvalToolResultExtraction::fromExtractionBlock(['regex' => 'nothing (\d+)'])->resolveValueFromToolResult($result));
        self::assertNull(AgentEvalToolResultExtraction::fromExtractionBlock('not a block')->resolveValueFromToolResult($result));
    }

    public function testAPatternWithASlashReadsTheValue(): void
    {
        $result = '{"content":[{"type":"text","text":"Saved at https://example.org/notes/4711 ."}],"isError":false}';

        self::assertSame('4711', AgentEvalToolResultExtraction::fromExtractionBlock(['regex' => 'example.org/notes/(\d+)'])->resolveValueFromToolResult($result));
        self::assertSame('4711', AgentEvalToolResultExtraction::fromExtractionBlock(['regex' => 'example.org\/notes\/(\d+)'])->resolveValueFromToolResult($result), 'an escaped slash stays as written');
    }

    public function testTheDelimitedPatternEscapesOnlyTheSlashesTheBodyLeftUnescaped(): void
    {
        $extraction = AgentEvalToolResultExtraction::fromExtractionBlock([]);

        self::assertSame('/https?:\/\//i', $extraction->buildDelimitedPattern('https?://', 'i'));
        self::assertSame('/a\/b/', $extraction->buildDelimitedPattern('a\/b'));
        self::assertSame('/a\\\\\/b/', $extraction->buildDelimitedPattern('a\\\\/b'), 'an escaped backslash leaves the slash after it unescaped');
        self::assertSame('/\[#(\d+)/', $extraction->buildDelimitedPattern('\[#(\d+)'));
    }

    public function testTheLastCompletedCallOfTheToolIsRead(): void
    {
        $messages = $this->messagesOfToolCalls([
            ['createNote', [], AIConversationMessage::STATUS_COMPLETED, '{"content":[{"type":"text","text":"[#1 · NOTE]"}]}'],
            ['createNote', [], AIConversationMessage::STATUS_COMPLETED, '{"content":[{"type":"text","text":"[#2 · NOTE]"}]}'],
            ['createNote', [], AIConversationMessage::STATUS_FAILED, '{"content":[{"type":"text","text":"[#3 · NOTE]"}]}'],
            ['otherTool', [], AIConversationMessage::STATUS_COMPLETED, '{"content":[{"type":"text","text":"[#4 · NOTE]"}]}'],
        ]);

        self::assertSame('2', AgentEvalToolResultExtraction::fromExtractionBlock(['tool' => 'createNote', 'regex' => '\[#(\d+)'])->resolveValueFromToolCallsOf($messages));
        self::assertNull(AgentEvalToolResultExtraction::fromExtractionBlock(['tool' => 'missingTool', 'regex' => '\[#(\d+)'])->resolveValueFromToolCallsOf($messages));
    }

    public function testACallThatCarriesAnArgumentRequiredAbsentIsNeverTheTarget(): void
    {
        $messages = $this->messagesOfToolCalls([
            ['saveNote', ['content' => 'Eval note'], AIConversationMessage::STATUS_COMPLETED, '{"content":[{"type":"text","text":"[#5 · NOTE]"}]}'],
            ['saveNote', ['noteId' => 7612430, 'content' => 'Eval note'], AIConversationMessage::STATUS_COMPLETED, '{"content":[{"type":"text","text":"[#7612430 · NOTE]"}]}'],
        ]);

        self::assertSame('5', AgentEvalToolResultExtraction::fromExtractionBlock(['tool' => 'saveNote', 'regex' => '\[#(\d+)', 'requireArgsAbsent' => ['noteId']])->resolveValueFromToolCallsOf($messages));
        self::assertSame('7612430', AgentEvalToolResultExtraction::fromExtractionBlock(['tool' => 'saveNote', 'regex' => '\[#(\d+)'])->resolveValueFromToolCallsOf($messages));
    }

    public function testALaterMatchingCallWithoutStoredResultReadsNothing(): void
    {
        $messages = $this->messagesOfToolCalls([
            ['createNote', [], AIConversationMessage::STATUS_COMPLETED, '{"content":[{"type":"text","text":"[#1 · NOTE]"}]}'],
            ['createNote', [], AIConversationMessage::STATUS_COMPLETED, null],
        ]);

        self::assertNull(AgentEvalToolResultExtraction::fromExtractionBlock(['tool' => 'createNote', 'regex' => '\[#(\d+)'])->resolveValueFromToolCallsOf($messages));
    }

    public function testTheBlockIsKeptWithTheKeysItCarries(): void
    {
        self::assertSame(
            ['tool' => 'saveNote', 'regex' => '\[#(\d+)', 'requireArgsAbsent' => ['noteId']],
            AgentEvalToolResultExtraction::fromExtractionBlock(['tool' => 'saveNote', 'regex' => '\[#(\d+)', 'path' => null, 'requireArgsAbsent' => 'noteId'])->toArray()
        );
    }
}
