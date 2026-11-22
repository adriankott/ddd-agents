<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebSearchHit;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitList;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitListFilters;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitLists;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHits;
use DDD\Domain\AI\Entities\WebSearch\WebSearchMergedHits;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The merge of several queries' hits into one page list (round robin over the queries, one entry per canonical page
 * with every excerpt and every query number) and its rendering within the budget.
 */
class WebSearchMergedHitsTest extends UnitTestCase
{
    public function testThePagesAreMergedRoundRobinAndOncePerCanonicalPage(): void
    {
        $webSearchMergedHits = WebSearchMergedHits::fromHitLists(self::buildHitLists());

        $urls = [];
        foreach ($webSearchMergedHits->getElements() as $webSearchMergedHit) {
            $urls[] = $webSearchMergedHit->url;
        }
        // rank 1 of query 1, rank 1 of query 2 (the same page as query 1's rank 2), rank 2 of query 2, rank 2 of query 1
        self::assertSame(['https://a.example/one', 'https://www.b.example/two/', 'https://c.example/three'], $urls);
        $sharedPage = array_values($webSearchMergedHits->getElements())[1];
        self::assertSame([1, 2], $sharedPage->queryNumbers, 'a page found by both queries names both');
        self::assertSame(['excerpt of two from query 2', 'excerpt of two from query 1'], $sharedPage->snippets);
    }

    public function testThePageListStopsAtTheBudgetAndSaysHowManyPagesAreLeftOut(): void
    {
        $webSearchMergedHits = WebSearchMergedHits::fromHitLists(self::buildHitLists());

        $webSearchPageListRendering = $webSearchMergedHits->buildPageListRenderingWithinBudget(10, 1800);

        self::assertStringContainsString('[1] One — https://a.example/one', $webSearchPageListRendering->markdown, 'the first page is always shown');
        self::assertSame(2, $webSearchPageListRendering->omittedPageCount);
        self::assertStringContainsString('<untrusted-content', $webSearchPageListRendering->markdown, 'the excerpts are marked');

        $markdown = self::buildHitLists()->buildLlmMarkdown(10, 1800, new Date('2026-11-22'));
        self::assertStringContainsString('2 more pages not shown', $markdown);
        self::assertStringContainsString('Query 1: "first query" — 2 hits', $markdown);
    }

    public function testAnExcerptIsCutAtItsCapWithAVisibleMarker(): void
    {
        $webSearchMergedHits = WebSearchMergedHits::fromHitLists(self::buildHitLists());

        $block = array_values($webSearchMergedHits->getElements())[0]->buildLlmMarkdownBlock(1, 10);

        self::assertStringContainsString('[excerpt cut]', $block);
        self::assertStringContainsString('page published 2026-11-01', $block);
    }

    public function testTheExcerptsOfTheFirstPagesAreKeyedByTheirCanonicalUrl(): void
    {
        $webPageExcerpts = WebSearchMergedHits::fromHitLists(self::buildHitLists())->buildExcerptsOfFirstPages(2);

        self::assertSame(['https://a.example/one', 'https://www.b.example/two/'], $webPageExcerpts->getUrls());
        self::assertSame(['excerpt of two from query 2', 'excerpt of two from query 1'], $webPageExcerpts->findSnippetsOfUrl('http://b.example/two'));
        self::assertSame([], $webPageExcerpts->findSnippetsOfUrl('https://c.example/three'));
    }

    public function testAQueryTheEngineDidNotAnswerIsNamedWithItsReason(): void
    {
        $webSearchHitLists = new WebSearchHitLists();
        $webSearchHitList = new WebSearchHitList();
        $webSearchHitList->query = 'unanswered';
        $webSearchHitList->filters = new WebSearchHitListFilters();
        $webSearchHitList->hits = new WebSearchHits();
        $webSearchHitList->failureReason = 'timeout';
        $webSearchHitLists->add($webSearchHitList);

        $markdown = $webSearchHitLists->buildLlmMarkdown(48000, 1800);

        self::assertStringContainsString('Query 1: "unanswered" — not answered: timeout', $markdown);
        self::assertStringContainsString('No pages found.', $markdown);
        self::assertFalse($webSearchHitLists->hasAnyHit());
    }

    public function testARecencyWindowIsDatedFromItsFirstDayToToday(): void
    {
        $recencyNote = WebSearchHitLists::buildRecencyWindowReadingNote('week', new Date('2026-11-22'));

        self::assertStringContainsString('between 2026-11-15 and 2026-11-22', $recencyNote);
        self::assertStringContainsString('between 2026-02-28 and 2026-03-31', WebSearchHitLists::buildRecencyWindowReadingNote('month', new Date('2026-03-31')));
    }

    protected static function buildHitLists(): WebSearchHitLists
    {
        $webSearchHitLists = new WebSearchHitLists();
        $firstHitList = self::buildHitList('first query', [
            ['One', 'https://a.example/one', 'excerpt of one, a little longer than ten characters', '2026-11-01'],
            ['Two', 'https://b.example/two', 'excerpt of two from query 1', null],
        ]);
        $webSearchHitLists->add($firstHitList);
        $secondHitList = self::buildHitList('second query', [
            ['Two', 'https://www.b.example/two/', 'excerpt of two from query 2', null],
            ['Three', 'https://c.example/three', 'excerpt of three', null],
        ]);
        $webSearchHitLists->add($secondHitList);
        return $webSearchHitLists;
    }

    /** @param string[][] $hitRows title, url, snippet, publication day */
    protected static function buildHitList(string $query, array $hitRows): WebSearchHitList
    {
        $webSearchHits = new WebSearchHits();
        foreach ($hitRows as [$title, $url, $snippet, $publishedAt]) {
            $webSearchHit = WebSearchHit::fromSearchResultRow((object)['title' => $title, 'url' => $url, 'snippet' => $snippet, 'date' => $publishedAt]);
            $webSearchHits->add($webSearchHit);
        }
        $webSearchHitList = new WebSearchHitList();
        $webSearchHitList->query = $query;
        $webSearchHitList->filters = WebSearchHitListFilters::fromToolArguments('fast', null, null, [], [], null, 8, 4096);
        $webSearchHitList->hits = $webSearchHits;
        return $webSearchHitList;
    }
}
