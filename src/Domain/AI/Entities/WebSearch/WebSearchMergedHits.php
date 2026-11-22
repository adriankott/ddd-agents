<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The pages of ONE `searchTheWeb` call, merged across its queries. The order is round-robin over the
 * queries — the first hit of every query, then the second of every query, and so on — so a render budget that stops
 * early still keeps every query's best pages; a page found by several queries keeps the position of its first find.
 *
 * @property WebSearchMergedHit[] $elements
 * @method WebSearchMergedHit[] getElements()
 * @method WebSearchMergedHit|null getByUniqueKey(string $uniqueKey)
 */
class WebSearchMergedHits extends ObjectSet
{
    /** The merged pages of the given hit lists (query number = 1-based position of the list). Pure. */
    public static function fromHitLists(WebSearchHitLists $webSearchHitLists): static
    {
        $webSearchMergedHits = static::newInstance();
        $hitsPerQuery = [];
        foreach ($webSearchHitLists->getElements() as $webSearchHitList) {
            $hitsPerQuery[] = array_values($webSearchHitList->hits?->getElements() ?? []);
        }
        $deepestRank = $hitsPerQuery === [] ? 0 : max(array_map('count', $hitsPerQuery));
        for ($rank = 0; $rank < $deepestRank; $rank++) {
            foreach ($hitsPerQuery as $queryIndex => $hitsOfQuery) {
                $webSearchHit = $hitsOfQuery[$rank] ?? null;
                if (!$webSearchHit instanceof WebSearchHit || trim((string)($webSearchHit->url ?? '')) === '') {
                    continue;
                }
                $existingMergedHit = $webSearchMergedHits->getByUniqueKey(WebSearchMergedHit::buildUniqueKeyForUrl((string)$webSearchHit->url));
                if ($existingMergedHit instanceof WebSearchMergedHit) {
                    $existingMergedHit->addHitOfQuery($webSearchHit, $queryIndex + 1);
                    continue;
                }
                $newMergedHit = WebSearchMergedHit::fromFirstHit($webSearchHit, $queryIndex + 1);
                $webSearchMergedHits->add($newMergedHit);
            }
        }
        return $webSearchMergedHits;
    }

    /**
     * The pages as the agent reads them, numbered, until `$renderBudgetChars` would be exceeded; a
     * page that no longer fits is left out and the omission is stated in one closing line. The first page is always
     * shown. Returns the rendered blocks and how many pages were left out.
     */
    public function buildPageListRenderingWithinBudget(int $renderBudgetChars, int $snippetCharCap): WebSearchPageListRendering
    {
        $blocks = [];
        $usedChars = 0;
        $omittedCount = 0;
        $hitNumber = 0;
        foreach ($this->getElements() as $webSearchMergedHit) {
            $block = $webSearchMergedHit->buildLlmMarkdownBlock($hitNumber + 1, $snippetCharCap);
            $blockChars = mb_strlen($block) + 2;
            if ($blocks !== [] && $usedChars + $blockChars > $renderBudgetChars) {
                $omittedCount++;
                continue;
            }
            $hitNumber++;
            $blocks[] = $block;
            $usedChars += $blockChars;
        }
        $webSearchPageListRendering = WebSearchPageListRendering::newInstance();
        $webSearchPageListRendering->markdown = implode("\n\n", $blocks);
        $webSearchPageListRendering->omittedPageCount = $omittedCount;
        return $webSearchPageListRendering;
    }

    /**
     * The search engine's excerpts of the first `$pageCount` pages, keyed by page URL — what the fast model reads
     * alongside a page, and alone when the page cannot be crawled. Pure.
     */
    public function buildExcerptsOfFirstPages(int $pageCount): WebPageExcerpts
    {
        $webPageExcerpts = WebPageExcerpts::newInstance();
        foreach (array_slice(array_values($this->getElements()), 0, max(0, $pageCount)) as $webSearchMergedHit) {
            $webPageExcerpts->addOrReplaceExcerpt(WebPageExcerpt::fromUrlAndSnippets((string)$webSearchMergedHit->url, $webSearchMergedHit->snippets));
        }
        return $webPageExcerpts;
    }

    /** The excerpts of every page that has some. Pure. */
    public function buildExcerptsOfPagesWithSnippets(): WebPageExcerpts
    {
        $webPageExcerpts = WebPageExcerpts::newInstance();
        foreach ($this->getElements() as $webSearchMergedHit) {
            if ($webSearchMergedHit->snippets !== []) {
                $webPageExcerpts->addOrReplaceExcerpt(WebPageExcerpt::fromUrlAndSnippets((string)$webSearchMergedHit->url, $webSearchMergedHit->snippets));
            }
        }
        return $webPageExcerpts;
    }
}
