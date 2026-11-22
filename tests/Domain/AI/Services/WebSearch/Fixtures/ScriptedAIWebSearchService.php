<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\WebSearch\Fixtures;

use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshot;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitListFilters;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitLists;
use DDD\Domain\AI\Entities\WebSearch\WebSearchPayer;
use DDD\Domain\AI\Services\WebSearch\AIWebSearchService;

/**
 * The research service with its outward calls scripted: every capability on, every host public, the searches
 * answered by `$scriptedHitLists` and recorded, the captures answered by `$scriptedCaptures` in call order.
 */
class ScriptedAIWebSearchService extends AIWebSearchService
{
    public ?WebSearchHitLists $scriptedHitLists = null;

    /** @var PageScreenshot[] The captures, in call order */
    public array $scriptedCaptures = [];

    public int $searchCallCount = 0;

    public int $captureCallCount = 0;

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

    public function capturePageScreenshot(string $url, int $viewportWidth, int $viewportHeight, bool $isMobile, bool $bypassCache = false): PageScreenshot
    {
        $pageScreenshot = $this->scriptedCaptures[$this->captureCallCount] ?? new PageScreenshot();
        $this->captureCallCount++;
        return $pageScreenshot;
    }

    protected function findScreenshotRefusalReasonOfHost(string $url): ?string
    {
        return null;
    }
}
