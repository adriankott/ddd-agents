<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The queries of one `searchTheWeb` call after their operators were removed ({@see WebSearchQueryNormalization}),
 * the call's `domains` filter, and the note that tells the model what changed. The `site:` domains become the call's
 * filter only when the call has no `domains` of its own and every query named a site (the filter applies to all
 * queries of a call); otherwise they are dropped, and the note says how to search a site. Pure.
 */
class WebSearchCallQueries extends ValueObject
{
    /** @var string[] The queries as the engine receives them, deduplicated, in the order given. */
    public array $queries = [];

    /** @var string[] The call's `domains` filter: the requested one, or the `site:` domains of the queries. */
    public array $domains = [];

    /** The note for the model; null when the queries were plain and short. */
    public ?string $noteForModel = null;

    /**
     * @var string|null The `recency` window the call searches with: the requested one, or the window a query named
     * unambiguously (`today` → day, `this week` → week, `this month` → month) when none was requested.
     */
    public ?string $recency = null;

    /** Unambiguous time phrases of a query → the `recency` window they mean. */
    public const array RECENCY_WINDOWS_BY_PHRASE_PATTERN = [
        '/\b(today|past 24 hours|last 24 hours)\b/iu' => 'day',
        '/\b(this week|past week|past 7 days|last 7 days)\b/iu' => 'week',
        '/\b(this month|past month|past 30 days|last 30 days)\b/iu' => 'month',
    ];

    /**
     * Words that ask for current items without saying how current: the note suggests `recency`. A year alone does not
     * count: "prices 2026" or "trends 2026" are facts whose best sources may be months old.
     */
    public const string CURRENT_ITEMS_PATTERN = '/\b(news|latest|breaking|headlines?|yesterday)\b/iu';

    /**
     * @param string[] $rawQueries the queries as the model wrote them (deduplicated, non-empty)
     * @param string[] $requestedDomains the `domains` argument of the call
     * @param string|null $requestedRecency the `recency` argument of the call
     */
    public static function fromRawQueries(array $rawQueries, array $requestedDomains, ?string $requestedRecency = null): static
    {
        $callQueries = static::newInstance();
        $callQueries->domains = array_values($requestedDomains);
        $requestedRecency = $requestedRecency !== null && trim($requestedRecency) !== '' ? trim($requestedRecency) : null;
        $callQueries->recency = $requestedRecency;
        $impliedRecencyWindows = [];
        $asksForCurrentItems = false;
        $removedOperators = [];
        $siteDomains = [];
        $everyQueryNamedASite = true;
        $keywordChainQueries = [];
        foreach ($rawQueries as $rawQuery) {
            $normalization = WebSearchQueryNormalization::fromRawQuery((string)$rawQuery);
            $removedOperators = array_values(array_unique([...$removedOperators, ...$normalization->removedOperators]));
            if ($normalization->siteDomains === []) {
                $everyQueryNamedASite = false;
            }
            $siteDomains = array_values(array_unique([...$siteDomains, ...$normalization->siteDomains]));
            $query = $normalization->query !== '' ? $normalization->query : static::buildQueryFromSiteDomains($normalization->siteDomains);
            if ($query !== '' && !in_array($query, $callQueries->queries, true)) {
                $callQueries->queries[] = $query;
            }
            if ($normalization->isKeywordChain()) {
                $keywordChainQueries[] = $query;
            }
            foreach (static::RECENCY_WINDOWS_BY_PHRASE_PATTERN as $phrasePattern => $recencyWindow) {
                if (preg_match($phrasePattern, (string)$rawQuery) === 1) {
                    $impliedRecencyWindows[] = $recencyWindow;
                }
            }
            if (preg_match(static::CURRENT_ITEMS_PATTERN, (string)$rawQuery) === 1) {
                $asksForCurrentItems = true;
            }
        }
        $impliedRecencyWindows = array_values(array_unique($impliedRecencyWindows));
        $noteParts = [];
        if ($removedOperators !== []) {
            $siteDomainsApplied = $siteDomains !== [] && $requestedDomains === [] && $everyQueryNamedASite;
            if ($siteDomainsApplied) {
                $callQueries->domains = $siteDomains;
            }
            $notePart = 'CORRECTION: search operators removed from the queries (' . implode(', ', $removedOperators) . ') — the engine takes plain words';
            if ($siteDomains !== []) {
                $includedSiteDomains = [];
                foreach ($siteDomains as $siteDomain) {
                    $includedSiteDomains[] = '"' . $siteDomain . '"';
                }
                $notePart .= $siteDomainsApplied
                    ? '; `site:` was applied as `domains` this time'
                    : '; `site:` was dropped this time (`domains` applies to every query of the call: search that site in its own call)';
                $notePart .= '. Next time pass "domains": [' . implode(', ', $includedSiteDomains) . '] instead of `site:`';
            }
            $noteParts[] = $notePart;
        }
        if ($requestedRecency === null && count($impliedRecencyWindows) === 1) {
            $callQueries->recency = $impliedRecencyWindows[0];
            $noteParts[] = 'a query named a time window, so the search ran with "recency": "' . $impliedRecencyWindows[0]
                . '" — pass `recency` yourself next time';
        } elseif ($requestedRecency === null && ($impliedRecencyWindows !== [] || $asksForCurrentItems)) {
            $noteParts[] = 'for news and current events pass `recency` (day, week, month, year) instead of a date or year in '
                . 'the query, and give every news item its date from the page list (or "undated")';
        }
        if ($keywordChainQueries !== []) {
            $noteParts[] = 'long keyword chains find less than one short query per aspect (more than '
                . WebSearchQueryNormalization::MAX_WORDS_OF_A_SHORT_QUERY . ' words: "' . implode('", "', $keywordChainQueries) . '")';
        }
        if ($noteParts !== []) {
            $callQueries->noteForModel = 'NOTE: ' . implode('; ', $noteParts) . '.';
        }
        return $callQueries;
    }

    /**
     * The words of a query that consisted of `site:` operators only: the included domains.
     *
     * @param string[] $siteDomains
     */
    protected static function buildQueryFromSiteDomains(array $siteDomains): string
    {
        $includedDomains = [];
        foreach ($siteDomains as $siteDomain) {
            if (!str_starts_with($siteDomain, '-')) {
                $includedDomains[] = $siteDomain;
            }
        }
        return implode(' ', $includedDomains);
    }
}
