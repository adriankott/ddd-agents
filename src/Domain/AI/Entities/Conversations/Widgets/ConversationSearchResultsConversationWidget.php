<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * The result of a search across earlier conversations — data-complete: the query and the grouped hits; the widget
 * never re-runs the search. The block either carries the `groups` itself or names the search's tool call in
 * {@see $groupsFromToolCallId}: the platform then fills `groups` (and an empty `query`) from that call's stored result
 * when the row is stored ({@see \DDD\Domain\AI\Services\Conversations\ComposedViewBindingResolutionService::fillConversationSearchResultsGroupsOfRow()}),
 * so the model never copies kilobytes of JSON. The two catalog texts name no tool, because which skill carries the
 * search is the application's binding; an application that binds one declares a subclass of the same
 * {@see self::WIDGET_TYPE} that states both texts with its tool names.
 */
class ConversationSearchResultsConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'ConversationSearchResults';

    /** @var string The block field naming the search's tool call whose stored result fills `groups`. */
    public const string FIELD_GROUPS_FROM_TOOL_CALL_ID = 'groupsFromToolCallId';

    public const string SUMMARY = 'Grouped results of a search across earlier conversations. DATA-COMPLETE; ≤5 groups inline, more → canvas.';

    public const string WHEN_TO_USE = 'After a search across earlier conversations — render the matches as this widget instead of re-listing them in prose. Few matches → they render inline; many → they open in the canvas side panel automatically (or set `display`). Skip for a recall inside the current conversation — that\'s the current chat, answer inline in prose.';

    /** @var string The search query (at most 200 characters). */
    public string $query = '';

    /** @var string|null A headline instead of the query line (at most 120 characters). */
    public ?string $title = null;

    /** @var string|null `inline` or `canvas`; unset = inline up to five groups, the canvas above. */
    public ?string $display = null;

    /** @var array[] One group per conversation (`conversationId`, `title`, `summary`, the dates, `hits`), at most 20. */
    public array $groups = [];

    /**
     * @var string|null The tool call id of the search whose stored result holds `groups` (a structured result with a
     * top-level `groups` list): the platform writes those groups into the block when the row is stored; `groups` is
     * then left out of the block.
     */
    public ?string $groupsFromToolCallId = null;

    /** The fields in block order; an optional field left null is not written. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'query' => $this->query,
            'title' => $this->title,
            'display' => $this->display,
            'groups' => $this->groups === [] && $this->groupsFromToolCallId !== null ? null : $this->groups,
            self::FIELD_GROUPS_FROM_TOOL_CALL_ID => $this->groupsFromToolCallId,
        ]);
    }
}
