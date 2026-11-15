<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalStateAfterRunCheck;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The state check after a run: the read and its arguments out of the agent's own calls, every field predicate with
 * its failure sentence byte for byte, and the cleanup of what the agent created.
 */
class AgentEvalStateAfterRunCheckTest extends UnitTestCase
{
    protected const string CREATED_NOTE_RESULT = '{"content":[{"type":"text","text":"[#4711 · NOTE · DRAFT]"}],"isError":false}';

    /**
     * Tool-call rows of a driven conversation: each [name, arguments, stored result], all COMPLETED.
     *
     * @param array<int, array{0: string, 1: array<string, mixed>, 2: string}> $toolCalls
     */
    protected function messagesOfToolCalls(array $toolCalls): AIConversationMessages
    {
        $messages = new AIConversationMessages();
        foreach ($toolCalls as $indexInConversation => [$toolName, $toolArguments, $storedResult]) {
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
            $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
            $toolCall->result = $storedResult;
            $message->toolCall = $toolCall;
            $messages->add($message);
        }
        return $messages;
    }

    /** @param array<string, array<string, mixed>> $fields */
    protected function checkOfFields(array $fields): AgentEvalStateAfterRunCheck
    {
        return AgentEvalStateAfterRunCheck::fromStateAfterRunBlock(['readTool' => 'getNote', 'fields' => $fields]);
    }

    public function testEveryPredicateThatHoldsGivesNoFailure(): void
    {
        $failures = $this->checkOfFields([
            'note.content' => ['maxLength' => 20, 'matches' => '^short', 'notMatches' => 'https?:'],
            'note.id' => ['equals' => '12'],
        ])->evaluateFieldPredicates(['note' => ['content' => 'Short TEXT', 'id' => 12]]);

        self::assertSame([], $failures);
    }

    public function testTheFailureSentencesOfEveryPredicate(): void
    {
        $failures = $this->checkOfFields([
            'note.content' => ['maxLength' => 5, 'matches' => '^long', 'notMatches' => 'short', 'equals' => 'other', 'unknownPredicate' => true],
            'note.tags' => ['maxLength' => 5, 'matches' => 'x', 'notMatches' => 'x', 'equals' => ['a']],
            'note.missing' => ['equals' => null, 'matches' => 'x'],
        ])->evaluateFieldPredicates(['note' => ['content' => 'Short text', 'tags' => ['b']]]);

        self::assertSame([
            'stateAfterRun[note.content].maxLength(5) failed — value: "Short text" (10 chars)',
            'stateAfterRun[note.content].matches(^long) failed — value: "Short text" (10 chars)',
            'stateAfterRun[note.content].notMatches(short) failed — value: "Short text" (10 chars)',
            'stateAfterRun[note.content].equals(other) failed — value: "Short text" (10 chars)',
            'stateAfterRun[note.content].unknownPredicate(1) failed — value: "Short text" (10 chars)',
            'stateAfterRun[note.tags].maxLength(5) failed — value: ["b"]',
            'stateAfterRun[note.tags].matches(x) failed — value: ["b"]',
            'stateAfterRun[note.tags].equals(["a"]) failed — value: ["b"]',
            'stateAfterRun[note.missing].matches(x) failed — value: null',
        ], $failures);
    }

    public function testALongValueIsQuotedCutWithItsFullLength(): void
    {
        $failures = $this->checkOfFields(['text' => ['maxLength' => 10]])->evaluateFieldPredicates(['text' => str_repeat('é', 150)]);

        self::assertSame(['stateAfterRun[text].maxLength(10) failed — value: "' . str_repeat('é', 120) . '" (150 chars)'], $failures);
    }

    public function testAPatternWithASlashIsChecked(): void
    {
        $check = $this->checkOfFields(['note.content' => ['notMatches' => 'https?://']]);

        self::assertSame(
            ['stateAfterRun[note.content].notMatches(https?://) failed — value: "Read https://example.org now" (28 chars)'],
            $check->evaluateFieldPredicates(['note' => ['content' => 'Read https://example.org now']]),
            'a link in the text fails notMatches instead of passing unchecked'
        );
        self::assertSame([], $check->evaluateFieldPredicates(['note' => ['content' => 'No link here']]));
        self::assertSame([], $this->checkOfFields(['url' => ['matches' => '^https://example\.org/notes/\d+$']])->evaluateFieldPredicates(['url' => 'https://example.org/notes/4711']));
    }

    public function testAnInvalidPatternFailsItsPredicateWithItsOwnSentence(): void
    {
        $check = $this->checkOfFields(['note.content' => ['notMatches' => '(unclosed', 'matches' => '[a-', 'maxLength' => 100]]);

        self::assertSame([
            'stateAfterRun[note.content].notMatches((unclosed) failed — invalid pattern, the value was not checked',
            'stateAfterRun[note.content].matches([a-) failed — invalid pattern, the value was not checked',
        ], $check->evaluateFieldPredicates(['note' => ['content' => 'Short text']]), 'an invalid notMatches no longer passes unchecked');
        self::assertSame(
            ['stateAfterRun[note.missing].notMatches((unclosed) failed — invalid pattern, the value was not checked'],
            $this->checkOfFields(['note.missing' => ['notMatches' => '(unclosed']])->evaluateFieldPredicates(['note' => []]),
            'also for a value that is not there'
        );
    }

    public function testTheReadResultIsCheckedOnItsStructuredContent(): void
    {
        $readCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_STATE_READ, 'getNote', [], true);
        $readCall->recordResult('{"content":[{"type":"text","text":"Note"}],"structuredContent":{"note":{"content":"x"}}}', false);
        $notJson = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_STATE_READ, 'getNote', [], true);
        $notJson->recordResult('Note: x', false);
        $check = $this->checkOfFields(['note.content' => ['equals' => 'x']]);

        self::assertSame([], $check->evaluateReadResult($readCall));
        self::assertSame(['stateAfterRun[note.content].equals(x) failed — value: null'], $check->evaluateReadResult($notJson));
    }

    public function testTheReadTakesItsArgumentsFromTheAgentsOwnCalls(): void
    {
        $check = AgentEvalStateAfterRunCheck::fromStateAfterRunBlock([
            'readTool' => 'getNote',
            'args' => ['noteId' => 1, 'withHistory' => true],
            'argsFromToolResult' => ['noteId' => ['tool' => 'createNote', 'regex' => '\[#(\d+)']],
        ]);
        $messages = $this->messagesOfToolCalls([['createNote', ['content' => 'x'], self::CREATED_NOTE_RESULT]]);

        $resolvedReadArguments = $check->resolveReadArgumentsFromToolCallsOf($messages);
        $readCall = $check->buildReadCall($resolvedReadArguments);

        self::assertTrue($check->hasRead());
        self::assertSame(['noteId' => '4711'], $resolvedReadArguments);
        self::assertSame([], $check->buildUnresolvedArgumentFailures($resolvedReadArguments));
        self::assertSame(['tool' => 'getNote', 'args' => ['noteId' => '4711', 'withHistory' => true]], $readCall?->toArray());
        self::assertSame(['spaceRef' => 3, 'outputFormat' => 'structured', 'noteId' => '4711', 'withHistory' => true], $readCall->buildExecutedArguments(['spaceRef' => 3]));
    }

    public function testAnUnreadArgumentIsOneFailureForTheFirstOfThem(): void
    {
        $check = AgentEvalStateAfterRunCheck::fromStateAfterRunBlock([
            'readTool' => 'getNote',
            'argsFromToolResult' => ['noteId' => ['tool' => 'createNote', 'regex' => '\[#(\d+)'], 'otherId' => ['tool' => 'createOther', 'path' => 'id']],
        ]);

        $resolvedReadArguments = $check->resolveReadArgumentsFromToolCallsOf($this->messagesOfToolCalls([]));

        self::assertSame(['noteId' => null, 'otherId' => null], $resolvedReadArguments);
        self::assertSame(["stateAfterRun: could not resolve 'noteId' from the agent's tool results"], $check->buildUnresolvedArgumentFailures($resolvedReadArguments));
    }

    public function testABlockWithoutReadToolHasNoRead(): void
    {
        $check = AgentEvalStateAfterRunCheck::fromStateAfterRunBlock(['fields' => ['a' => ['equals' => 1]]]);

        self::assertFalse($check->hasRead());
        self::assertNull($check->buildReadCall([]));
        self::assertSame([], $check->resolveReadArgumentsFromToolCallsOf($this->messagesOfToolCalls([])));
    }

    public function testTheCleanupRemovesWhatTheAgentCreatedAndNeverTheRecordItUpdated(): void
    {
        $check = AgentEvalStateAfterRunCheck::fromStateAfterRunBlock([
            'readTool' => 'getNote',
            'cleanup' => ['writeTool' => 'deleteNote', 'argsFromToolResult' => ['noteId' => ['tool' => 'saveNote', 'regex' => '\[#(\d+)', 'requireArgsAbsent' => ['noteId']]]],
        ]);
        $createdAndUpdated = $this->messagesOfToolCalls([
            ['saveNote', ['content' => 'x'], self::CREATED_NOTE_RESULT],
            ['saveNote', ['noteId' => 7612430, 'content' => 'x'], '{"content":[{"type":"text","text":"[#7612430 · NOTE]"}]}'],
        ]);
        $onlyUpdated = $this->messagesOfToolCalls([
            ['saveNote', ['noteId' => 7612430, 'content' => 'x'], '{"content":[{"type":"text","text":"[#7612430 · NOTE]"}]}'],
        ]);

        $cleanupCall = $check->buildCleanupCall($createdAndUpdated);

        self::assertSame(['tool' => 'deleteNote', 'args' => ['noteId' => '4711']], $cleanupCall?->toArray());
        self::assertSame(AgentEvalHarnessToolCall::STEP_CLEANUP, $cleanupCall->step);
        self::assertSame(['outputFormat' => 'structured', 'noteId' => '4711'], $cleanupCall->buildExecutedArguments());
        self::assertNull($check->buildCleanupCall($onlyUpdated), 'a cleanup without its target is not made');
    }

    public function testACleanupWithFixedArgumentsRunsWhenTheReadValueIsMissing(): void
    {
        $check = AgentEvalStateAfterRunCheck::fromStateAfterRunBlock([
            'cleanup' => ['writeTool' => 'deleteNote', 'args' => ['tag' => 'eval'], 'argsFromToolResult' => ['noteId' => ['tool' => 'createNote', 'regex' => '\[#(\d+)']]],
        ]);

        self::assertSame(['tool' => 'deleteNote', 'args' => ['tag' => 'eval']], $check->buildCleanupCall($this->messagesOfToolCalls([]))?->toArray());
        self::assertNull(AgentEvalStateAfterRunCheck::fromStateAfterRunBlock(['cleanup' => ['args' => ['tag' => 'eval']]])->buildCleanupCall($this->messagesOfToolCalls([])));
    }
}
