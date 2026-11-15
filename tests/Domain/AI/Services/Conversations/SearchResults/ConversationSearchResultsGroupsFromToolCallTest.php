<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\SearchResults;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolCallResult;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationSearchResultsConversationWidget;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A `ConversationSearchResults` block that names its search call in `groupsFromToolCallId` gets its `groups` (and an
 * empty `query`) from that call's stored structured result when the row is stored — the model copies no JSON. A block
 * that carries groups, a call that has not ended or failed, and a result without `groups` are left as they are.
 */
class ConversationSearchResultsGroupsFromToolCallTest extends UnitTestCase
{
    protected const array SEARCH_GROUPS = [
        ['conversationId' => 1841, 'title' => 'Create Facebook Post', 'hits' => [['role' => 'user', 'excerpt' => 'connect fb']]],
    ];

    public function testTheBlockGetsTheGroupsAndTheQueryOfTheNamedSearchCall(): void
    {
        $fillProbeService = $this->buildServiceWithSearchCall('call_search', AIConversationMessage::STATUS_COMPLETED, ['query' => 'facebook', 'groups' => self::SEARCH_GROUPS]);
        $message = $this->buildAssistantRow('{"type":"ConversationSearchResults","query":"","groupsFromToolCallId":"call_search"}');

        $fillProbeService->resolveBindingsOfRowBeforePersist($message);

        self::assertSame(
            "Your chats about it:\n```widget\n"
                . '{"type":"ConversationSearchResults","query":"facebook","groupsFromToolCallId":"call_search","groups":'
                . '[{"conversationId":1841,"title":"Create Facebook Post","hits":[{"role":"user","excerpt":"connect fb"}]}]}'
                . "\n```",
            $message->message
        );
        self::assertSame([['call_search']], $fillProbeService->askedToolCallIds);
    }

    public function testABlockThatCarriesGroupsAndACallThatGivesNoGroupsAreLeftAsTheyAre(): void
    {
        $blockWithGroups = '{"type":"ConversationSearchResults","query":"fb","groupsFromToolCallId":"call_search","groups":[{"hits":[]}]}';
        $fillProbeService = $this->buildServiceWithSearchCall('call_search', AIConversationMessage::STATUS_COMPLETED, ['query' => 'facebook', 'groups' => self::SEARCH_GROUPS]);
        $message = $this->buildAssistantRow($blockWithGroups);
        $fillProbeService->resolveBindingsOfRowBeforePersist($message);
        self::assertStringContainsString($blockWithGroups, $message->message, 'a block with groups is never refilled');

        $blockNamingTheCall = '{"type":"ConversationSearchResults","query":"fb","groupsFromToolCallId":"call_search"}';
        foreach ([
            'a running call' => [AIConversationMessage::STATUS_RUNNING, ['groups' => self::SEARCH_GROUPS]],
            'a result without groups' => [AIConversationMessage::STATUS_COMPLETED, ['hits' => []]],
        ] as $case => [$status, $searchResultData]) {
            $message = $this->buildAssistantRow($blockNamingTheCall);
            $this->buildServiceWithSearchCall('call_search', $status, $searchResultData)->resolveBindingsOfRowBeforePersist($message);
            self::assertStringContainsString($blockNamingTheCall, $message->message, $case);
        }

        $message = $this->buildAssistantRow($blockNamingTheCall);
        $this->buildServiceWithSearchCall('call_other', AIConversationMessage::STATUS_COMPLETED, ['groups' => self::SEARCH_GROUPS])->resolveBindingsOfRowBeforePersist($message);
        self::assertStringContainsString($blockNamingTheCall, $message->message, 'an unknown call id fills nothing');
    }

    public function testTheOwnersRowAndARowWithoutTheFieldAreNotRead(): void
    {
        $fillProbeService = $this->buildServiceWithSearchCall('call_search', AIConversationMessage::STATUS_COMPLETED, ['groups' => self::SEARCH_GROUPS]);
        $ownerRow = $this->buildAssistantRow('{"type":"ConversationSearchResults","groupsFromToolCallId":"call_search"}');
        $ownerRow->role = AIConversationMessage::ROLE_USER;
        $fillProbeService->resolveBindingsOfRowBeforePersist($ownerRow);
        $fillProbeService->resolveBindingsOfRowBeforePersist($this->buildAssistantRow('{"type":"ConversationSearchResults","query":"fb","groups":[]}'));

        self::assertSame([], $fillProbeService->askedToolCallIds);
    }

    public function testTheWidgetWritesTheCallIdInsteadOfEmptyGroups(): void
    {
        $conversationSearchResultsWidget = new ConversationSearchResultsConversationWidget();
        $conversationSearchResultsWidget->query = 'facebook';
        $conversationSearchResultsWidget->groupsFromToolCallId = 'call_search';

        $widgetBlock = $conversationSearchResultsWidget->buildWidgetBlock();

        self::assertStringContainsString('"groupsFromToolCallId":"call_search"', $widgetBlock);
        self::assertStringNotContainsString('"groups"', $widgetBlock);
    }

    /** @param array<string, mixed> $searchResultData */
    protected function buildServiceWithSearchCall(string $toolCallId, string $status, array $searchResultData): ConversationSearchResultsFillProbeService
    {
        $searchCallResult = new ComposedViewToolCallResult();
        $searchCallResult->toolCallId = $toolCallId;
        $searchCallResult->status = $status;
        $searchCallResult->hasBindableData = true;
        $searchCallResult->bindableData = $searchResultData;
        $fillProbeService = new ConversationSearchResultsFillProbeService();
        $fillProbeService->givenToolCallResults->addToolCallResult($searchCallResult);
        return $fillProbeService;
    }

    protected function buildAssistantRow(string $widgetJson): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->aiConversation = null;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->message = "Your chats about it:\n```widget\n" . $widgetJson . "\n```";
        return $message;
    }
}
