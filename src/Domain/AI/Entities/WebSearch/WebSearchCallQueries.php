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
     * @param string[] $rawQueries the queries as the model wrote them (deduplicated, non-empty)
     * @param string[] $requestedDomains the `domains` argument of the call
     */
    public static function fromRawQueries(array $rawQueries, array $requestedDomains): static
    {
        $callQueries = static::newInstance();
        $callQueries->domains = array_values($requestedDomains);
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
        }
        $noteParts = [];
        if ($removedOperators !== []) {
            $siteDomainsApplied = $siteDomains !== [] && $requestedDomains === [] && $everyQueryNamedASite;
            if ($siteDomainsApplied) {
                $callQueries->domains = $siteDomains;
            }
            $notePart = 'search operators removed from the queries (' . implode(', ', $removedOperators) . ') — the engine takes plain words';
            if ($siteDomainsApplied) {
                $notePart .= '; the `site:` domains were applied as `domains`';
            } elseif ($siteDomains !== []) {
                $notePart .= '; the `site:` domains were dropped: a site goes into `domains`, which applies to every query of the call';
            }
            $noteParts[] = $notePart;
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
