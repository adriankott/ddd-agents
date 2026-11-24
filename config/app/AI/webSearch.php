<?php

declare(strict_types=1);

/**
 * The web research of the package (`WebSearchMCPPackage`, skill `Agent.Web.Search`), read as `Config::get('AI.webSearch')` by {@see \DDD\Domain\AI\Services\WebSearch\AIWebSearchService}.
 * An application ships its own file at this path, which REPLACES this one whole: copy it, then change it.
 *
 * - `enabled`: the whole research; off by default. While it is off (or a capability has no endpoint) the packages are
 *   not offered and a direct call is refused with a sentence the model reads.
 * - `capabilities`: switch one capability off although it has an endpoint (`false`).
 * - `endpoints`: the application's endpoint per capability, `METHOD:path` (no method = POST). A relative path goes
 *   through the Argus gateway, an absolute URL is called directly. Wire shapes:
 *     search:        request {query, max_results, max_tokens_per_page, search_type, country?, search_recency_filter?,
 *                    search_domain_filter?, search_language_filter?}; answer {data: {results: [{title, url, snippet,
 *                    date?, last_updated?}]}}
 *     urlToMarkdown: request {url}; answer {data: {page: {url, ok, title?, markdown?, reason?}}} with reason BLOCKED,
 *                    TIMEOUT, FETCH_FAILED or NO_CONTENT
 *     urlToMarkdown reasons also: BOT_CHALLENGE, UNRESOLVABLE, UNSUPPORTED_TYPE (an unknown code is named as is)
 * - `cacheTtlSeconds` / `timeoutSeconds`: replace the per-capability defaults (search and page read: one hour and
 *   15 s). The tools' own limit is 45 s. Page screenshots are the page tools of `mgamadeus/ddd-agent-browser`.
 * - `cacheInDatabase`: per capability, true adds the database cache beside the memory cache, false keeps memory only;
 *   unset keeps the class default (memory and database).
 * - `hits`: the search. `feeUsdPerRequestByMode` is what one real request costs; it is handed to the application's
 *   booking hook (a cache hit or a failure books nothing). `providerName` goes into the references widget.
 *   `renderBudgetChars` caps what the model receives from one call, `snippetCharCapPerHit` one page's excerpts.
 *   `maxSearchCallsPerTurn`: `searchTheWeb` refuses a call beyond it in one turn (0 or null = no limit).
 * - `pageReads`: `getWebPageAsMarkdown`.
 * - `extraction`: the fast-model readings of `extractInformationFromWebPagesWithFastLLM` and of a search with
 *   `extractInformationFromResultPagesWithFastLLM`.
 * - `reading.modelName`: the reading model; null = the package default (a fast model without reasoning).
 */
return [
    'enabled' => false,
    'capabilities' => [
        'search' => true,
        'urlToMarkdown' => true,
    ],
    'endpoints' => [
        'search' => null,
        'urlToMarkdown' => null,
    ],
    'cacheTtlSeconds' => [],
    'timeoutSeconds' => [],
    'cacheInDatabase' => [],
    'hits' => [
        'defaultMode' => 'fast',
        'feeUsdPerRequestByMode' => ['fast' => 0.001, 'web' => 0.005],
        'providerName' => 'PERPLEXITY',
        'maxHitsPerQuery' => 8,
        'maxTokensPerPage' => 4096,
        'maxQueriesPerCall' => 5,
        'maxSearchCallsPerTurn' => 3,
        'renderBudgetChars' => 48000,
        'snippetCharCapPerHit' => 1800,
    ],
    'pageReads' => [
        'maxPagesPerCall' => 5,
    ],
    'extraction' => [
        'maxPagesPerCall' => 10,
        'defaultMaxResultPages' => 8,
        'pageCharCap' => 60000,
        'crawlTimeoutSeconds' => 10,
        'extractCharCapPerPage' => 2400,
    ],
    'reading' => [
        'modelName' => null,
    ],
];
