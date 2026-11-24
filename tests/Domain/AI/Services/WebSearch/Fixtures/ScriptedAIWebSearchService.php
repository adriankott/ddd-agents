<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\WebSearch\Fixtures;

use DDD\Domain\AI\Entities\WebSearch\WebSearchHitListFilters;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitLists;
use DDD\Domain\AI\Entities\WebSearch\WebSearchPayer;
use DDD\Domain\AI\Services\WebSearch\AIWebSearchService;

/**
 * The research service with its outward calls scripted: every capability on, every host public, the searches
 * answered by `$scriptedHitLists` and recorded.
 */
class ScriptedAIWebSearchService extends AIWebSearchService
{
    public ?WebSearchHitLists $scriptedHitLists = null;

    public int $searchCallCount = 0;

    /** @var string[] The queries of the last search */
    public array $lastSearchedQueries = [];

    public ?WebSearchHitListFilters $lastSearchFilters = null;

    public function isCapabilityAvailable(string $capability): bool
    {
        return true;
    }

    public function findHitListsForQueries(array $queries, WebSearchHitListFilters $webSearchHitListFilters, WebSearchPayer $webSearchPayer): WebSearchHitLists
    {
        $this->searchCallCount++;
        $this->lastSearchedQueries = $queries;
        $this->lastSearchFilters = $webSearchHitListFilters;
        return $this->scriptedHitLists ?? new WebSearchHitLists();
    }
}
