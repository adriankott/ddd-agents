<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Mcp\Client\AI\ToolCallsOfTheCurrentTurn;
use DDD\Presentation\Mcp\Client\AI\WebSearchMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Support\WebSearchTestConfiguration;

/**
 * `searchTheWeb` refuses a call beyond `hits.maxSearchCallsPerTurn` searches in the current turn: the calling row
 * counts, an earlier turn, a failed call (a refusal) and a duplicate do not; outside the agent loop there is no limit.
 */
class WebSearchCallsPerTurnLimitTest extends UnitTestCase
{
    public function testTheFourthSearchOfATurnIsRefused(): void
    {
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration(WebSearchTestConfiguration::buildEnabledConfiguration());
        try {
            $threeSearches = $this->buildTurnWithSearches(3);
            WebSearchMCPPackage::throwIfSearchCallsOfTheTurnAreUsedUp(new AgentToolCallProvenance(conversationMessages: $threeSearches));
            self::assertSame(3, ToolCallsOfTheCurrentTurn::countCallsThatDidWorkInMessages($threeSearches, 'searchTheWeb'));

            $this->expectException(BadRequestException::class);
            $this->expectExceptionMessage('At most 3 searches per turn');
            WebSearchMCPPackage::throwIfSearchCallsOfTheTurnAreUsedUp(new AgentToolCallProvenance(conversationMessages: $this->buildTurnWithSearches(4)));
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }

    public function testEarlierTurnsFailedCallsAndDuplicatesDoNotCount(): void
    {
        $messages = new AIConversationMessages();
        $row = $this->buildMessage(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 0);
        $messages->add($row);
        $row = $this->buildSearchCall(1, AIConversationMessage::STATUS_COMPLETED);
        $messages->add($row);
        $row = $this->buildSearchCall(2, AIConversationMessage::STATUS_COMPLETED);
        $messages->add($row);
        $row = $this->buildMessage(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 3);
        $messages->add($row);
        $row = $this->buildSearchCall(4, AIConversationMessage::STATUS_FAILED);
        $messages->add($row);
        $duplicateCall = $this->buildSearchCall(5, AIConversationMessage::STATUS_COMPLETED);
        $duplicateCall->toolCall->wasRefusedAsDuplicate = true;
        $messages->add($duplicateCall);
        $row = $this->buildSearchCall(6, AIConversationMessage::STATUS_PENDING);
        $messages->add($row);

        self::assertSame(1, ToolCallsOfTheCurrentTurn::countCallsThatDidWorkInMessages($messages, 'searchTheWeb'));
    }

    public function testNoLimitOutsideTheLoopOrWhenSwitchedOff(): void
    {
        $webSearchConfiguration = WebSearchTestConfiguration::buildEnabledConfiguration();
        $webSearchConfiguration['hits']['maxSearchCallsPerTurn'] = 0;
        $configValuesBefore = WebSearchTestConfiguration::declareWebSearchConfiguration($webSearchConfiguration);
        try {
            WebSearchMCPPackage::throwIfSearchCallsOfTheTurnAreUsedUp(new AgentToolCallProvenance(conversationMessages: $this->buildTurnWithSearches(6)));
            self::assertNull(ToolCallsOfTheCurrentTurn::countCallsThatDidWork(new AgentToolCallProvenance(), 'searchTheWeb'));
        } finally {
            WebSearchTestConfiguration::restoreConfigValues($configValuesBefore);
        }
    }

    protected function buildTurnWithSearches(int $searchCount): AIConversationMessages
    {
        $messages = new AIConversationMessages();
        $row = $this->buildMessage(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 0);
        $messages->add($row);
        for ($searchIndex = 1; $searchIndex <= $searchCount; $searchIndex++) {
            $row = $this->buildSearchCall($searchIndex, $searchIndex === $searchCount ? AIConversationMessage::STATUS_PENDING : AIConversationMessage::STATUS_COMPLETED);
            $messages->add($row);
        }
        return $messages;
    }

    protected function buildMessage(string $role, string $authorType, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'look it up';
        $message->indexInConversation = $index;
        return $message;
    }

    protected function buildSearchCall(int $index, string $status): AIConversationMessage
    {
        $message = $this->buildMessage(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, $index);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = 'searchTheWeb';
        $toolCall->status = $status;
        $toolCall->arguments = ['query' => 'q' . $index];
        $message->toolCall = $toolCall;
        return $message;
    }
}
