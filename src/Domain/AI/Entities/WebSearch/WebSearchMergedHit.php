<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Infrastructure\Base\DateTime\Date;

/**
 * ONE page as the agent sees it after the hits of all queries of a `searchTheWeb` call were merged: a
 * page found by several queries appears once, with every excerpt the engine gave for it and the numbers of the
 * queries that found it.
 */
class WebSearchMergedHit extends ValueObject
{
    public ?string $title = null;

    public ?string $url = null;

    /** @var string[] Every distinct excerpt the engine gave for this page, in the order they arrived. */
    public array $snippets = [];

    /** @var int[] The 1-based numbers of the queries that found this page. */
    public array $queryNumbers = [];

    public ?Date $publishedAt = null;

    public ?Date $lastCrawledAt = null;

    /** A merged hit started from the first hit of a page. */
    public static function fromFirstHit(WebSearchHit $webSearchHit, int $queryNumber): static
    {
        $webSearchMergedHit = static::newInstance();
        $webSearchMergedHit->title = $webSearchHit->title;
        $webSearchMergedHit->url = $webSearchHit->url;
        $webSearchMergedHit->publishedAt = $webSearchHit->publishedAt;
        $webSearchMergedHit->lastCrawledAt = $webSearchHit->lastCrawledAt;
        $webSearchMergedHit->addHitOfQuery($webSearchHit, $queryNumber);
        return $webSearchMergedHit;
    }

    /** Adds another query's hit of the same page: its excerpt (when new), its query number, a missing date. */
    public function addHitOfQuery(WebSearchHit $webSearchHit, int $queryNumber): void
    {
        if (!in_array($queryNumber, $this->queryNumbers, true)) {
            $this->queryNumbers[] = $queryNumber;
            sort($this->queryNumbers);
        }
        $snippet = trim((string)($webSearchHit->snippet ?? ''));
        if ($snippet !== '' && !in_array($snippet, $this->snippets, true)) {
            $this->snippets[] = $snippet;
        }
        $this->publishedAt ??= $webSearchHit->publishedAt;
        $this->lastCrawledAt ??= $webSearchHit->lastCrawledAt;
        if (($this->title ?? '') === '' && ($webSearchHit->title ?? '') !== '') {
            $this->title = $webSearchHit->title;
        }
    }

    public function uniqueKey(): string
    {
        return self::buildUniqueKeyForUrl((string)($this->url ?? ''));
    }

    /** The set key of the merged hit of a page — the canonical URL under THIS class (a hit's own key differs). */
    public static function buildUniqueKeyForUrl(string $url): string
    {
        return self::uniqueKeyStatic(WebPageMarkdown::canonicalUrlKey($url));
    }

    /**
     * The agent's view of this page: `[n] title — url`, a line with the queries and the dates, then the excerpts as a
     * quoted block, cut at `$snippetCharCap` characters with a visible marker — the whole block inside
     * `<untrusted-content>` marks ({@see ToolResultUntrustedSegments}).
     */
    public function buildLlmMarkdownBlock(int $hitNumber, int $snippetCharCap): string
    {
        $title = trim((string)($this->title ?? ''));
        $url = trim((string)($this->url ?? ''));
        $lines = ['[' . $hitNumber . '] ' . ($title !== '' ? $title . ' — ' : '') . $url];
        $facts = ['queries ' . implode(', ', $this->queryNumbers)];
        if ($this->publishedAt !== null) {
            $facts[] = 'page published ' . $this->publishedAt->format('Y-m-d');
        }
        if ($this->lastCrawledAt !== null) {
            $facts[] = 'crawled ' . $this->lastCrawledAt->format('Y-m-d');
        }
        $lines[] = implode(' · ', $facts);
        $excerpt = implode("\n...\n", $this->snippets);
        $excerpt = (string)preg_replace("/\n{3,}/", "\n\n", $excerpt);
        if (mb_strlen($excerpt) > $snippetCharCap) {
            $excerpt = rtrim(mb_substr($excerpt, 0, $snippetCharCap)) . ' [excerpt cut]';
        }
        if ($excerpt !== '') {
            foreach (explode("\n", $excerpt) as $excerptLine) {
                $lines[] = '> ' . $excerptLine;
            }
        }
        // the page's title and excerpts are written by the site — marked, so the screening reads them and only them
        return ToolResultUntrustedSegments::wrap($url, implode("\n", $lines));
    }
}
