<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\Date;

/**
 * ONE ranked hit of a web search: the page's title and URL, the excerpt the search engine chose for the
 * query (query-relevant passages of the page, `...` where it left text out) and the two dates the engine knows. The
 * agent reads the excerpt and decides whether the page holds what it needs; it never gets a pre-written answer.
 * Filled by {@see \DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebSearchHitList} from the search endpoint's answer.
 */
class WebSearchHit extends ValueObject
{
    public ?string $title = null;

    public ?string $url = null;

    /** The search engine's excerpt of the page for the query — page text, untrusted. */
    public ?string $snippet = null;

    /** The page's publication date, when the engine knows it. */
    public ?Date $publishedAt = null;

    /** When the engine last crawled the page. */
    public ?Date $lastCrawledAt = null;

    /** A hit from one row of the search endpoint's `data.results` (`title`, `url`, `snippet`, `date`, `last_updated`). */
    public static function fromSearchResultRow(object $resultRow): static
    {
        $webSearchHit = static::newInstance();
        $webSearchHit->title = isset($resultRow->title) ? trim((string)$resultRow->title) : null;
        $webSearchHit->url = isset($resultRow->url) ? trim((string)$resultRow->url) : null;
        $webSearchHit->snippet = isset($resultRow->snippet) ? (string)$resultRow->snippet : null;
        $webSearchHit->publishedAt = self::parseDayOrNull($resultRow->date ?? null);
        $webSearchHit->lastCrawledAt = self::parseDayOrNull($resultRow->last_updated ?? null);
        return $webSearchHit;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(WebPageMarkdown::canonicalUrlKey((string)($this->url ?? '')));
    }

    /** A `YYYY-MM-DD` day of the engine (it may carry a time part), null for anything else. */
    protected static function parseDayOrNull(mixed $rawDay): ?Date
    {
        if (!is_string($rawDay) || preg_match('/^\d{4}-\d{2}-\d{2}/', $rawDay) !== 1) {
            return null;
        }
        $day = Date::fromString(substr($rawDay, 0, 10));
        return $day instanceof Date ? $day : null;
    }
}
