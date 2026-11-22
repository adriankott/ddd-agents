<?php

declare(strict_types=1);

/**
 * The web research of the package (`WebSearchMCPPackage`, `WebPageScreenshotMCPPackage`, skills `Web.Search` and
 * `Web.PageView`), read as `Config::get('AI.webSearch')` by {@see \DDD\Domain\AI\Services\WebSearch\AIWebSearchService}.
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
 *     screenshot:    request {url, responseType: base64, viewport_width, viewport_height, width, height, density, type,
 *                    javascript_enabled, redirect_enabled, dom_timeout, min_wait, is_mobile, force_full_page,
 *                    emulate_full_page, wait_until, omit_background, addPoweredByHeader}; answer {status: success,
 *                    content: <base64 png>} (further fields are ignored)
 * - `cacheTtlSeconds` / `timeoutSeconds`: replace the per-capability defaults (search and page read: one hour and
 *   15 s; screenshot: ten minutes and the endpoint's own render time). The tools' own limit is 45 s.
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
        'screenshot' => true,
    ],
    'endpoints' => [
        'search' => null,
        'urlToMarkdown' => null,
        'screenshot' => null,
    ],
    'cacheTtlSeconds' => [],
    'timeoutSeconds' => [],
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
