<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Presentation\Mcp\Client\AI\Dtos\WebPageMarkdownMcpResponseDto;
use DDD\Presentation\Mcp\Client\AI\ToolBatchAdoptionNote;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The in-result corrective: the SECOND single-target call of a batchable tool in the CURRENT turn gets ONE line naming
 * the batch argument. The first single call, a batch call, a call of another tool, an earlier turn's call, a refused
 * call and a call outside the agent loop carry no line; on a page read the line leads the platform note, never the
 * screened result.
 */
class ToolBatchAdoptionNoteTest extends UnitTestCase
{
    public function testTheFirstSinglePageReadOfTheTurnCarriesNoLine(): void
    {
        $messages = $this->buildMessages(
            $this->buildUserRow(1, 'Here is my website: https://bakery.example'),
            $this->buildToolCallRow(2, 'getWebPageAsMarkdown', ['url' => 'https://bakery.example'])
        );

        self::assertNull($this->buildPageReadNote($messages));
    }

    public function testTheSecondSinglePageReadOfTheTurnLeadsWithTheCorrective(): void
    {
        $messages = $this->buildMessages(
            $this->buildUserRow(1, 'Here is my website: https://bakery.example'),
            $this->buildToolCallRow(2, 'getWebPageAsMarkdown', ['url' => 'https://bakery.example']),
            $this->buildToolCallRow(3, 'getWebPageAsMarkdown', ['url' => 'https://bakery.example/contact'])
        );

        self::assertSame(
            'NOTE: second single-page read in this turn — next time put all pages of a step into ONE call\'s `urls`.',
            $this->buildPageReadNote($messages)
        );
    }

    public function testALaterRepeatIsCountedAndAOneEntryBatchIsASingleRead(): void
    {
        $messages = $this->buildMessages(
            $this->buildUserRow(1, 'Please read my pages.'),
            $this->buildToolCallRow(2, 'getWebPageAsMarkdown', ['url' => 'https://a.example']),
            $this->buildToolCallRow(3, 'getWebPageAsMarkdown', ['urls' => ['https://a.example/team']]),
            $this->buildToolCallRow(4, 'getWebPageAsMarkdown', ['url' => 'https://a.example/prices', 'urls' => ['https://a.example/prices']])
        );

        self::assertSame(
            'NOTE: third single-page read in this turn — next time put all pages of a step into ONE call\'s `urls`.',
            $this->buildPageReadNote($messages)
        );
    }

    public function testBatchCallsOtherToolsEarlierTurnsAndRefusedCallsAreNotCounted(): void
    {
        $messages = $this->buildMessages(
            $this->buildUserRow(1, 'First message.'),
            $this->buildToolCallRow(2, 'getWebPageAsMarkdown', ['url' => 'https://a.example']),
            $this->buildUserRow(3, 'Second message.'),
            $this->buildToolCallRow(4, 'getWebPageAsMarkdown', ['urls' => ['https://a.example', 'https://a.example/contact']]),
            $this->buildToolCallRow(5, 'searchTheWeb', ['query' => 'bakery opening hours']),
            $this->buildToolCallRow(6, 'getWebPageAsMarkdown', ['url' => 'https://a.example'], true),
            $this->buildToolCallRow(7, 'getWebPageAsMarkdown', ['url' => 'https://a.example/team'])
        );

        self::assertNull($this->buildPageReadNote($messages));
    }

    public function testNoConversationOrAnEvalHarnessCallCarriesNoLine(): void
    {
        self::assertNull(ToolBatchAdoptionNote::buildForRepeatedSingleTargetCall(new AgentToolCallProvenance(), 'getWebPageAsMarkdown', 'url', 'urls', 'single-page read', 'all pages of a step'));
        self::assertNull(ToolBatchAdoptionNote::buildForRepeatedSingleTargetCall(AgentToolCallProvenance::forEvalHarness(null), 'getWebPageAsMarkdown', 'url', 'urls', 'single-page read', 'all pages of a step'));
    }

    public function testTheLineNamesTheBatchArgumentAndTargetsAreCountedDistinctly(): void
    {
        self::assertSame(
            'NOTE: single-query search number 6 in this turn — next time put all searches of a step into ONE call\'s `queries`.',
            ToolBatchAdoptionNote::composeLine(6, 'single-query search', 'all searches of a step', 'queries')
        );
        self::assertSame(2, ToolBatchAdoptionNote::countDistinctTargets(['query' => 'Pasing', 'queries' => ['pasing ', 'Laim']], 'query', 'queries'));
    }

    public function testOnAPageReadTheLineLeadsThePlatformNoteNotTheResult(): void
    {
        $responseDto = new WebPageMarkdownMcpResponseDto();
        $responseDto->batchAdoptionNote = 'NOTE: second single-page read in this turn — next time put all pages of a step into ONE call\'s `urls`.';

        self::assertStringStartsWith('NOTE: second single-page read', (string)$responseDto->buildPlatformNoteForModel());
        self::assertStringNotContainsString('NOTE: second single-page read', $responseDto->toLlmMarkdown());
    }

    protected function buildPageReadNote(AIConversationMessages $messages): ?string
    {
        return ToolBatchAdoptionNote::buildForRepeatedSingleTargetCall(
            new AgentToolCallProvenance(conversationId: 10, rootConversationId: 10, conversationMessages: $messages, toolName: 'getWebPageAsMarkdown'),
            'getWebPageAsMarkdown',
            'url',
            'urls',
            'single-page read',
            'all pages of a step',
        );
    }

    protected function buildMessages(AIConversationMessage ...$orderedMessages): AIConversationMessages
    {
        $messages = new AIConversationMessages();
        foreach ($orderedMessages as $message) {
            $messages->add($message);
        }
        return $messages;
    }

    protected function buildUserRow(int $index, string $text): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->toolCall = null;
        return $message;
    }

    /** @param array<string, mixed> $arguments */
    protected function buildToolCallRow(int $index, string $toolName, array $arguments, bool $wasRefusedAsDuplicate = false): AIConversationMessage
    {
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->wasRefusedAsDuplicate = $wasRefusedAsDuplicate;
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->toolCall = $toolCall;
        return $message;
    }
}
