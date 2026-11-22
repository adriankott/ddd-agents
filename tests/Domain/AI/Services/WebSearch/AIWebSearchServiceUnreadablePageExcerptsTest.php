<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageExcerpt;
use DDD\Domain\AI\Entities\WebSearch\WebPageExcerpts;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHit;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitList;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitLists;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHits;
use DDD\Domain\AI\Entities\WebSearch\WebSearchPayer;
use DDD\Tests\Agents\Domain\AI\Services\WebSearch\Fixtures\ScriptedAIWebSearchService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A page the crawler could not read gets its search excerpt from ONE search for the task, restricted to its site
 * ({@see \DDD\Domain\AI\Services\WebSearch\AIWebSearchService::addExcerptsOfUnreadablePages()}). A readable page and a
 * page whose excerpts are already in hand trigger no search.
 */
class AIWebSearchServiceUnreadablePageExcerptsTest extends UnitTestCase
{
    public function testAnUnreadablePageGetsItsExcerptFromASiteRestrictedSearch(): void
    {
        $scriptedAIWebSearchService = new ScriptedAIWebSearchService();
        $scriptedAIWebSearchService->scriptedHitLists = $this->buildHitLists('https://laptimes.example/models/sport-coupe', 'Est. 100 - 200 kph 11.2 s');
        $webPageMarkdowns = new WebPageMarkdowns();
        $unreadablePage = $this->buildPage('https://www.laptimes.example/models/sport-coupe', false, null);
        $webPageMarkdowns->add($unreadablePage);
        $readablePage = $this->buildPage('https://maker.example/coupe', true, 'page text');
        $webPageMarkdowns->add($readablePage);

        /** @var WebPageExcerpts $webPageExcerpts */
        $webPageExcerpts = self::invokeMethod($scriptedAIWebSearchService, 'addExcerptsOfUnreadablePages', [
            $webPageMarkdowns,
            ['https://www.laptimes.example/models/sport-coupe', 'https://maker.example/coupe'],
            new WebPageExcerpts(),
            'coupe 100-200 km/h',
            WebSearchPayer::forAccount(null),
        ]);

        self::assertSame(1, $scriptedAIWebSearchService->searchCallCount);
        self::assertSame(['laptimes.example'], $scriptedAIWebSearchService->lastSearchFilters?->domains);
        self::assertSame(['Est. 100 - 200 kph 11.2 s'], $webPageExcerpts->findSnippetsOfUrl('https://www.laptimes.example/models/sport-coupe'));
        self::assertSame([], $webPageExcerpts->findSnippetsOfUrl('https://maker.example/coupe'), 'a readable page needs no excerpt');
    }

    public function testNoSearchWhenEveryPageIsReadableOrHasExcerpts(): void
    {
        $scriptedAIWebSearchService = new ScriptedAIWebSearchService();
        $webPageMarkdowns = new WebPageMarkdowns();
        $unreadablePage = $this->buildPage('https://a.example/', false, null);
        $webPageMarkdowns->add($unreadablePage);
        $knownExcerpts = new WebPageExcerpts();
        $knownExcerpts->addOrReplaceExcerpt(WebPageExcerpt::fromUrlAndSnippets('https://a.example/', ['an excerpt']));

        /** @var WebPageExcerpts $webPageExcerpts */
        $webPageExcerpts = self::invokeMethod($scriptedAIWebSearchService, 'addExcerptsOfUnreadablePages', [
            $webPageMarkdowns,
            ['https://a.example/'],
            $knownExcerpts,
            'task',
            WebSearchPayer::forAccount(null),
        ]);

        self::assertSame(0, $scriptedAIWebSearchService->searchCallCount);
        self::assertSame(['an excerpt'], $webPageExcerpts->findSnippetsOfUrl('https://a.example/'));
    }

    protected function buildPage(string $url, bool $ok, ?string $markdown): WebPageMarkdown
    {
        $webPageMarkdown = new WebPageMarkdown();
        $webPageMarkdown->url = $url;
        $webPageMarkdown->ok = $ok;
        $webPageMarkdown->markdown = $markdown;
        $webPageMarkdown->reason = $ok ? null : WebPageMarkdown::REASON_FETCH_FAILED;
        return $webPageMarkdown;
    }

    protected function buildHitLists(string $url, string $snippet): WebSearchHitLists
    {
        $webSearchHit = new WebSearchHit();
        $webSearchHit->url = $url;
        $webSearchHit->snippet = $snippet;
        $webSearchHits = new WebSearchHits();
        $webSearchHits->add($webSearchHit);
        $webSearchHitList = new WebSearchHitList();
        $webSearchHitList->query = 'q';
        $webSearchHitList->hits = $webSearchHits;
        $webSearchHitLists = new WebSearchHitLists();
        $webSearchHitLists->add($webSearchHitList);
        return $webSearchHitLists;
    }
}
