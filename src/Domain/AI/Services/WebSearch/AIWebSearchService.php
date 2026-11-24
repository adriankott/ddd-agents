<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageExcerpt;
use DDD\Domain\AI\Entities\WebSearch\WebPageExcerpts;
use DDD\Domain\AI\Entities\WebSearch\WebPageExtract;
use DDD\Domain\AI\Entities\WebSearch\WebPageExtracts;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitList;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitListFilters;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitLists;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHits;
use DDD\Domain\AI\Entities\WebSearch\WebSearchMergedHits;
use DDD\Domain\AI\Entities\WebSearch\WebSearchConfiguration;
use DDD\Domain\AI\Entities\WebSearch\WebSearchPayer;
use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebPageExtract;
use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebPageExtracts;
use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebPageMarkdown;
use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebPageMarkdowns;
use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebSearchHitList;
use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebSearchHitLists;
use DDD\Infrastructure\Http\PublicUrlGuard;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Domain\AI\Exceptions\Conversations\AgentToolExecutionTimeoutException;
use Throwable;

/**
 * The web research of the package: the page lists of a search engine ({@see findHitListsForQueries()}), the page
 * readings of a fast model ({@see findWebPageExtracts()}), the page reads as markdown ({@see getWebPageAsMarkdown()},
 * {@see getWebPagesAsMarkdown()}). Behind the tools of `WebSearchMCPPackage`, and open to an application's own
 * consumers. Page screenshots are the page tools of `mgamadeus/ddd-agent-browser`.
 *
 * Each capability calls the endpoint the application configures (`AI.webSearch.endpoints.*`); a capability without an
 * endpoint, switched off (`AI.webSearch.capabilities.*`) or with the whole research off (`AI.webSearch.enabled`) answers
 * as a failure on the returned objects, never with an exception. What the application decides (payer, spend check,
 * fee booking, the search country) is {@see WebSearchHostService}.
 */
class AIWebSearchService extends Service
{
    public const string CAPABILITY_SEARCH = 'search';

    public const string CAPABILITY_URL_TO_MARKDOWN = 'urlToMarkdown';

    public const array CAPABILITIES = [self::CAPABILITY_SEARCH, self::CAPABILITY_URL_TO_MARKDOWN];

    /** The extraction task is cut to this length as the query of the excerpt search for unreadable pages. */
    public const int EXCERPT_SEARCH_QUERY_MAX_CHARS = 300;

    /** @var string[] host => its {@see PublicUrlGuard} class, for the life of the process */
    protected static array $hostClassByHost = [];

    /** Whether the web research is switched on at all (`AI.webSearch.enabled`, default off). */
    public function isWebSearchEnabled(): bool
    {
        return (bool)WebSearchConfiguration::findValue('enabled', false);
    }

    /**
     * Whether `$capability` (one of {@see CAPABILITIES}) can be used: the research is on, the capability is not
     * switched off and the application configures its endpoint.
     */
    public function isCapabilityAvailable(string $capability): bool
    {
        if (!$this->isWebSearchEnabled() || WebSearchConfiguration::findValue('capabilities.' . $capability, true) === false) {
            return false;
        }
        return match ($capability) {
            self::CAPABILITY_SEARCH => ArgusWebSearchHitList::findConfiguredLoadEndpoint() !== null,
            self::CAPABILITY_URL_TO_MARKDOWN => ArgusWebPageMarkdown::findConfiguredLoadEndpoint() !== null,
            default => false,
        };
    }

    /** An integer limit of `AI.webSearch` (`hits.maxQueriesPerCall`, …), at least 1, `$default` when unset. */
    public function getConfiguredLimit(string $configPath, int $default): int
    {
        $configuredLimit = WebSearchConfiguration::findValue($configPath);
        return max(1, is_numeric($configuredLimit) ? (int)$configuredLimit : $default);
    }

    /** The application's side of the web research ({@see WebSearchHostService}, replaceable by a same-path subclass). */
    public function getWebSearchHostService(): WebSearchHostService
    {
        /** @var WebSearchHostService $webSearchHostService */
        $webSearchHostService = DDDService::instance()->getService(WebSearchHostService::class);
        return $webSearchHostService;
    }

    /**
     * The hit lists of a search: one request per distinct query, all fired at once as the children of one
     * {@see ArgusWebSearchHitLists} batch (the wall clock is the slowest query), in the order of `$queries`; two spellings
     * of one query with the same filters are one request and ONE list. Each answer is cached (query and filters); a
     * failed request is not.
     *
     * Spending: the payer's spend check runs before any request ({@see WebSearchHostService::throwIfWebSearchSpendingIsRefused()});
     * afterwards the fee of the mode is booked for every request that really went to the engine and answered — never
     * for a cache hit or a failure ({@see WebSearchHostService::bookWebSearchRequests()}). Without the search capability
     * every list comes back unanswered, with the reason.
     *
     * @param string[] $queries
     */
    public function findHitListsForQueries(array $queries, WebSearchHitListFilters $webSearchHitListFilters, WebSearchPayer $webSearchPayer): WebSearchHitLists
    {
        $cleanQueries = [];
        foreach ($queries as $query) {
            $query = trim((string)$query);
            if ($query !== '') {
                $cleanQueries[] = $query;
            }
        }
        $webSearchHitLists = WebSearchHitLists::newInstance();
        if ($cleanQueries === []) {
            return $webSearchHitLists;
        }
        if (!$this->isCapabilityAvailable(self::CAPABILITY_SEARCH)) {
            foreach ($cleanQueries as $query) {
                $unansweredHitList = $this->buildUnansweredHitList($query, $webSearchHitListFilters, 'the web search is switched off in this application');
                $webSearchHitLists->add($unansweredHitList);
            }
            return $webSearchHitLists;
        }
        $webSearchHostService = $this->getWebSearchHostService();
        $webSearchHostService->throwIfWebSearchSpendingIsRefused($webSearchPayer);

        $argusWebSearchHitLists = ArgusWebSearchHitLists::newInstance();
        $argusWebSearchHitLists->getArgusSettings()->keepFulfilledCallsWhenOneIsRejected = true;
        $searchTimeoutSeconds = ArgusWebSearchHitList::findConfiguredTimeoutSeconds();
        if ($searchTimeoutSeconds !== null) {
            // the batch's HTTP timeout is read from the ROOT of the cascade
            $argusWebSearchHitLists->getArgusSettings()->timeout = $searchTimeoutSeconds;
        }
        $loadersByPosition = [];
        foreach ($cleanQueries as $position => $query) {
            $argusWebSearchHitList = ArgusWebSearchHitList::newInstance();
            $argusWebSearchHitList->query = $query;
            $argusWebSearchHitList->filters = $webSearchHitListFilters;
            $existingLoader = $argusWebSearchHitLists->getByUniqueKey($argusWebSearchHitList->uniqueKey());
            if ($existingLoader instanceof ArgusWebSearchHitList) {
                $loadersByPosition[$position] = $existingLoader;
                continue;
            }
            $argusWebSearchHitList->applyConfiguredLoadSettings();
            $argusWebSearchHitLists->add($argusWebSearchHitList);
            $loadersByPosition[$position] = $argusWebSearchHitList;
        }
        $argusWebSearchHitLists->setPropertiesToLoad(ArgusWebSearchHitList::class);
        $argusWebSearchHitLists->argusLoad();
        $this->bookWebSearchFee($argusWebSearchHitLists, $webSearchHitListFilters, $webSearchPayer);
        foreach ($loadersByPosition as $argusWebSearchHitList) {
            /** @var ArgusWebSearchHitList $argusWebSearchHitList */
            $webSearchHitListInstance = WebSearchHitList::newInstance();
            $webSearchHitList = $argusWebSearchHitList->toEntity(entityInstance: $webSearchHitListInstance);
            if ($webSearchHitList instanceof WebSearchHitList) {
                $webSearchHitLists->add($webSearchHitList);
            }
        }
        return $webSearchHitLists;
    }

    /** A hit list of `$query` that was never asked, with `$failureReason`. */
    protected function buildUnansweredHitList(string $query, WebSearchHitListFilters $webSearchHitListFilters, string $failureReason): WebSearchHitList
    {
        $webSearchHitList = WebSearchHitList::newInstance();
        $webSearchHitList->query = $query;
        $webSearchHitList->filters = $webSearchHitListFilters;
        $webSearchHitList->hits = WebSearchHits::newInstance();
        $webSearchHitList->failureReason = $failureReason;
        return $webSearchHitList;
    }

    /**
     * Books the search fee of the batch's mode for every child that went to the engine and answered, through the
     * application's hook. Public for an application's own batch that carries hit-list children.
     */
    public function bookWebSearchFee(ArgusWebSearchHitLists $argusWebSearchHitLists, WebSearchHitListFilters $webSearchHitListFilters, WebSearchPayer $webSearchPayer): void
    {
        $feeUsdPerRequestByMode = (array)WebSearchConfiguration::findValue('hits.feeUsdPerRequestByMode', []);
        $configuredFeeUsdPerRequest = (float)($feeUsdPerRequestByMode[$webSearchHitListFilters->mode] ?? 0.0);
        $billableRequestCount = 0;
        $feeUsd = 0.0;
        foreach ($argusWebSearchHitLists->getElements() as $argusWebSearchHitList) {
            $argusSettings = $argusWebSearchHitList->getArgusSettings();
            if ($argusSettings->loadedFromCache || !$argusSettings->isLoadedSuccessfully || !$argusWebSearchHitList->isAnswered()) {
                continue;
            }
            if ($argusWebSearchHitList->answeredFromEndpointCache) {
                continue;
            }
            $billableRequestCount++;
            $feeUsd += $argusWebSearchHitList->endpointFeeUsd ?? $configuredFeeUsdPerRequest;
        }
        if ($billableRequestCount === 0) {
            return;
        }
        try {
            $this->getWebSearchHostService()->bookWebSearchRequests($webSearchPayer, $webSearchHitListFilters, $billableRequestCount, $feeUsd);
        } catch (Throwable $throwable) {
            error_log('Failed to book the web search fee: ' . $throwable->getMessage());
        }
    }

    /**
     * The readings of a fast model: one {@see WebPageExtract} per distinct page (canonical URL), in the order asked, at
     * most `AI.webSearch.extraction.maxPagesPerCall`:
     *
     * 1. the pages through {@see getWebPagesAsMarkdown()} (its refusals, the bot-challenge downgrade, its cache);
     * 2. a page without text and without excerpts gets its excerpts from ONE search restricted to those sites;
     * 3. every page with text or excerpts read by the model as ONE batch of {@see ArgusWebPageExtract} children; a page
     *    with neither is {@see WebPageExtract::STATUS_UNREADABLE} without a model call;
     * 4. every fact's quote verified against the page and the excerpts (an unverified quote is marked, never dropped).
     *
     * Spending: the payer's spend check runs before any crawl; each reading is billed to the payer by the
     * language-model trait. Without an account nothing can be billed, so nothing is read: every page comes back failed
     * with that reason.
     *
     * @param string[] $urls
     */
    public function findWebPageExtracts(array $urls, string $extractionTask, WebPageExcerpts $webPageExcerpts, WebSearchPayer $webSearchPayer): WebPageExtracts
    {
        $webPageExtracts = WebPageExtracts::newInstance();
        $urlsByCanonicalKey = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '' && !isset($urlsByCanonicalKey[WebPageMarkdown::canonicalUrlKey($url)])) {
                $urlsByCanonicalKey[WebPageMarkdown::canonicalUrlKey($url)] = $url;
            }
        }
        $urlsToRead = array_slice(array_values($urlsByCanonicalKey), 0, $this->getConfiguredLimit('extraction.maxPagesPerCall', 10));
        if ($urlsToRead === [] || trim($extractionTask) === '') {
            return $webPageExtracts;
        }
        if (!$webSearchPayer->hasAccount()) {
            foreach ($urlsToRead as $url) {
                $webPageExtract = WebPageExtract::fromUnreadablePage($url, null, 'no account to bill the reading to');
                $webPageExtract->status = WebPageExtract::STATUS_EXTRACTION_FAILED;
                $webPageExtracts->add($webPageExtract);
            }
            return $webPageExtracts;
        }
        $this->getWebSearchHostService()->throwIfWebSearchSpendingIsRefused($webSearchPayer);
        $crawlTimeoutSeconds = WebSearchConfiguration::findValue('extraction.crawlTimeoutSeconds');
        $webPageMarkdowns = $this->getWebPagesAsMarkdown($urlsToRead, false, is_numeric($crawlTimeoutSeconds) ? (float)$crawlTimeoutSeconds : 10.0);
        $pageCharCap = $this->getConfiguredLimit('extraction.pageCharCap', 60000);
        $webPageExcerpts = $this->addExcerptsOfUnreadablePages($webPageMarkdowns, $urlsToRead, $webPageExcerpts, $extractionTask, $webSearchPayer);

        $argusWebPageExtracts = ArgusWebPageExtracts::newInstance();
        $argusWebPageExtracts->getArgusSettings()->keepFulfilledCallsWhenOneIsRejected = true;
        $extractsByPosition = [];
        foreach (array_values($webPageMarkdowns->getElements()) as $position => $webPageMarkdown) {
            $url = $urlsToRead[$position] ?? (string)$webPageMarkdown->url;
            $snippets = $webPageExcerpts->findSnippetsOfUrl($url);
            $pageText = ($webPageMarkdown->ok ?? false) ? trim((string)$webPageMarkdown->markdown) : '';
            if ($pageText === '' && $snippets === []) {
                $extractsByPosition[$position] = WebPageExtract::fromUnreadablePage($url, $webPageMarkdown->title, static::buildUnreadablePageReason($webPageMarkdown));
                continue;
            }
            $argusWebPageExtract = ArgusWebPageExtract::newInstance();
            $argusWebPageExtract->url = $url;
            $argusWebPageExtract->title = $webPageMarkdown->title;
            $argusWebPageExtract->extractionTask = trim($extractionTask);
            $argusWebPageExtract->pageText = $pageText !== '' ? mb_substr($pageText, 0, $pageCharCap) : null;
            $argusWebPageExtract->snippets = $snippets;
            // the payer on the loader itself, before the load: the gate and the cost booking both read it
            $argusWebPageExtract->billAICostsTo($webSearchPayer->account, $webSearchPayer->entityWithinAccount);
            if ($pageText === '') {
                $argusWebPageExtract->failureReason = static::buildUnreadablePageReason($webPageMarkdown);
            }
            $argusWebPageExtracts->add($argusWebPageExtract);
            $extractsByPosition[$position] = $argusWebPageExtract;
        }
        if ($argusWebPageExtracts->count() > 0) {
            $argusWebPageExtracts->setPropertiesToLoad(ArgusWebPageExtract::class);
            $argusWebPageExtracts->argusLoad();
        }
        foreach ($extractsByPosition as $webPageExtractOrLoader) {
            $webPageExtract = $webPageExtractOrLoader;
            if ($webPageExtractOrLoader instanceof ArgusWebPageExtract) {
                $webPageExtractInstance = WebPageExtract::newInstance();
                $webPageExtract = $webPageExtractOrLoader->toEntity(entityInstance: $webPageExtractInstance);
            }
            if ($webPageExtract instanceof WebPageExtract) {
                $webPageExtracts->add($webPageExtract);
            }
        }
        return $webPageExtracts;
    }

    /**
     * The search excerpts of the pages the crawler could not read and nobody passed excerpts for: ONE search for the
     * task restricted to those sites (mode `fast`), its hits mapped back by canonical URL. Best-effort: a failed or
     * unavailable search adds nothing.
     *
     * @param string[] $urlsToRead
     */
    protected function addExcerptsOfUnreadablePages(
        WebPageMarkdowns $webPageMarkdowns,
        array $urlsToRead,
        WebPageExcerpts $webPageExcerpts,
        string $extractionTask,
        WebSearchPayer $webSearchPayer,
    ): WebPageExcerpts {
        $unreadableUrls = [];
        $hosts = [];
        foreach (array_values($webPageMarkdowns->getElements()) as $position => $webPageMarkdown) {
            $url = $urlsToRead[$position] ?? (string)$webPageMarkdown->url;
            $pageText = ($webPageMarkdown->ok ?? false) ? trim((string)$webPageMarkdown->markdown) : '';
            if ($pageText !== '' || $webPageExcerpts->findSnippetsOfUrl($url) !== []) {
                continue;
            }
            $host = (string)preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)));
            if ($host === '') {
                continue;
            }
            $unreadableUrls[WebPageMarkdown::canonicalUrlKey($url)] = $url;
            $hosts[$host] = $host;
        }
        if ($unreadableUrls === [] || !$this->isCapabilityAvailable(self::CAPABILITY_SEARCH)) {
            return $webPageExcerpts;
        }
        try {
            $webSearchHitListFilters = WebSearchHitListFilters::fromToolArguments(
                WebSearchHitListFilters::MODE_FAST,
                null,
                null,
                array_values($hosts),
                [],
                WebSearchHitListFilters::MAX_HITS_PER_QUERY,
                WebSearchHitListFilters::MAX_HITS_PER_QUERY,
                $this->getConfiguredLimit('hits.maxTokensPerPage', 4096),
            );
            $webSearchHitLists = $this->findHitListsForQueries([mb_substr($extractionTask, 0, static::EXCERPT_SEARCH_QUERY_MAX_CHARS)], $webSearchHitListFilters, $webSearchPayer);
        } catch (Throwable $throwable) {
            error_log('Excerpt search for unreadable pages failed: ' . $throwable->getMessage());
            return $webPageExcerpts;
        }
        foreach (WebSearchMergedHits::fromHitLists($webSearchHitLists)->buildExcerptsOfPagesWithSnippets()->getElements() as $foundExcerpt) {
            $unreadableUrl = $unreadableUrls[WebPageMarkdown::canonicalUrlKey($foundExcerpt->url)] ?? null;
            if ($unreadableUrl !== null) {
                $webPageExcerpts->addOrReplaceExcerpt(WebPageExcerpt::fromUrlAndSnippets($unreadableUrl, $foundExcerpt->snippets));
            }
        }
        return $webPageExcerpts;
    }

    /** Why a page could not be read, in the words the model reads ("the page could not be read (bot challenge)"). */
    public static function buildUnreadablePageReason(WebPageMarkdown $webPageMarkdown): string
    {
        return 'the page could not be read (' . strtolower(str_replace('_', ' ', (string)($webPageMarkdown->reason ?? 'no content'))) . ')';
    }

    /**
     * ONE web page as markdown through the url-to-markdown endpoint. No model call. A failure arrives ON the returned
     * object ({@see WebPageMarkdown::$ok} false and the reason), never as an exception. `$noCache` reads the page again
     * and rewrites its cache entry; `$crawlTimeoutSeconds` shortens the crawl for a caller with its own deadline.
     */
    public function getWebPageAsMarkdown(string $url, bool $noCache = false, ?float $crawlTimeoutSeconds = null): WebPageMarkdown
    {
        $refusedWebPageMarkdown = $this->refuseWebPageUrlBeforeCrawl($url);
        if ($refusedWebPageMarkdown !== null) {
            return $refusedWebPageMarkdown;
        }
        $argusWebPageMarkdown = ArgusWebPageMarkdown::newInstance();
        $argusWebPageMarkdown->url = trim($url);
        $argusWebPageMarkdown->applyConfiguredLoadSettings();
        if ($crawlTimeoutSeconds !== null) {
            $argusWebPageMarkdown->getArgusSettings()->timeout = $crawlTimeoutSeconds;
        }
        $argusWebPageMarkdown->argusLoad(useArgusEntityCache: !$noCache, useApiACallCache: !$noCache);
        $webPageMarkdownInstance = WebPageMarkdown::newInstance();
        $webPageMarkdown = $argusWebPageMarkdown->toEntity(entityInstance: $webPageMarkdownInstance);
        if (!$webPageMarkdown instanceof WebPageMarkdown) {
            return WebPageMarkdown::fromRefusedUrl($url, WebPageMarkdown::REASON_FETCH_FAILED);
        }
        // the endpoint reports a bot-protection interstitial as a success
        $webPageMarkdown->downgradeBotChallengeToFailure();
        return $webPageMarkdown;
    }

    /**
     * SEVERAL pages in ONE read: the refusals decided here as in the single read, the rest crawled CONCURRENTLY as one
     * batch (the wall clock is the slowest page). One {@see WebPageMarkdown} per URL, in the order asked; a failure is
     * ON its element, never missing. A page past the crawl bound comes back as a TIMEOUT while its siblings are kept.
     *
     * @param string[] $urls
     */
    public function getWebPagesAsMarkdown(array $urls, bool $noCache = false, ?float $crawlTimeoutSeconds = null): WebPageMarkdowns
    {
        $cleanUrls = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $cleanUrls[] = $url;
            }
        }
        $webPageMarkdowns = WebPageMarkdowns::newInstance();
        if ($cleanUrls === []) {
            return $webPageMarkdowns;
        }
        if (count($cleanUrls) === 1) {
            $webPageMarkdown = $this->getWebPageAsMarkdown($cleanUrls[0], $noCache, $crawlTimeoutSeconds);
            $webPageMarkdowns->add($webPageMarkdown);
            return $webPageMarkdowns;
        }
        $refusedPagesByPosition = [];
        $argusWebPageMarkdowns = ArgusWebPageMarkdowns::newInstance();
        $loadersByPosition = [];
        foreach ($cleanUrls as $position => $url) {
            $refusedWebPageMarkdown = $this->refuseWebPageUrlBeforeCrawl($url);
            if ($refusedWebPageMarkdown !== null) {
                $refusedPagesByPosition[$position] = $refusedWebPageMarkdown;
                continue;
            }
            $argusWebPageMarkdown = ArgusWebPageMarkdown::newInstance();
            $argusWebPageMarkdown->url = $url;
            // a URL asked twice is one operation: both positions read it
            $existingLoader = $argusWebPageMarkdowns->getByUniqueKey($argusWebPageMarkdown->uniqueKey());
            if ($existingLoader instanceof ArgusWebPageMarkdown) {
                $loadersByPosition[$position] = $existingLoader;
                continue;
            }
            $argusWebPageMarkdown->applyConfiguredLoadSettings();
            $argusWebPageMarkdowns->add($argusWebPageMarkdown);
            $loadersByPosition[$position] = $argusWebPageMarkdown;
        }
        if ($loadersByPosition !== []) {
            $argusWebPageMarkdowns->getArgusSettings()->keepFulfilledCallsWhenOneIsRejected = true;
            $argusWebPageMarkdowns->setPropertiesToLoad(ArgusWebPageMarkdown::class);
            // the batch's HTTP timeout is read from the ROOT of the cascade
            $batchTimeoutSeconds = $crawlTimeoutSeconds ?? ArgusWebPageMarkdown::findConfiguredTimeoutSeconds();
            if ($batchTimeoutSeconds !== null) {
                $argusWebPageMarkdowns->getArgusSettings()->timeout = $batchTimeoutSeconds;
            }
            $argusWebPageMarkdowns->argusLoad(useArgusEntityCache: !$noCache, useApiACallCache: !$noCache);
        }
        foreach ($cleanUrls as $position => $url) {
            if (isset($refusedPagesByPosition[$position])) {
                $webPageMarkdowns->add($refusedPagesByPosition[$position]);
                continue;
            }
            /** @var ArgusWebPageMarkdown $argusWebPageMarkdown */
            $argusWebPageMarkdown = $loadersByPosition[$position];
            $webPageMarkdownInstance = WebPageMarkdown::newInstance();
            $webPageMarkdown = $argusWebPageMarkdown->toEntity(entityInstance: $webPageMarkdownInstance);
            if (!$webPageMarkdown instanceof WebPageMarkdown) {
                $webPageMarkdown = WebPageMarkdown::fromRefusedUrl($url, WebPageMarkdown::REASON_FETCH_FAILED);
            }
            $webPageMarkdown->downgradeBotChallengeToFailure();
            $webPageMarkdowns->add($webPageMarkdown);
        }
        return $webPageMarkdowns;
    }

    /**
     * The refusals of a page URL decided BEFORE any crawl: the capability is off, a search engine's results page, a
     * host without a DNS record, a private address. Null = the crawler decides.
     */
    protected function refuseWebPageUrlBeforeCrawl(string $url): ?WebPageMarkdown
    {
        if (!$this->isCapabilityAvailable(self::CAPABILITY_URL_TO_MARKDOWN)) {
            return WebPageMarkdown::fromRefusedUrl($url, WebPageMarkdown::REASON_CAPABILITY_OFF);
        }
        if (WebPageMarkdown::isSearchEngineResultsPageUrl($url)) {
            return WebPageMarkdown::fromRefusedUrl($url, WebPageMarkdown::REASON_SEARCH_RESULTS_PAGE);
        }
        $hostClass = $this->classifyHostMemoized(trim($url));
        if ($hostClass === PublicUrlGuard::HOST_UNRESOLVABLE) {
            return WebPageMarkdown::fromRefusedUrl($url, WebPageMarkdown::REASON_UNRESOLVABLE);
        }
        if ($hostClass === PublicUrlGuard::HOST_PRIVATE) {
            return WebPageMarkdown::fromRefusedUrl($url, WebPageMarkdown::REASON_BLOCKED);
        }
        return null;
    }

    /**
     * {@see PublicUrlGuard::classifyHost()} once per host and process: several pages of one site, and every later read
     * of the same site in a worker, ask the resolver once. A resolver failure (UNKNOWN) is not kept.
     */
    protected function classifyHostMemoized(string $url): string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? parse_url('https://' . ltrim($url, '/'), PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return PublicUrlGuard::classifyHost($url);
        }
        if (isset(static::$hostClassByHost[$host])) {
            return static::$hostClassByHost[$host];
        }
        $hostClass = PublicUrlGuard::classifyHost($url);
        if ($hostClass !== PublicUrlGuard::HOST_UNKNOWN) {
            static::$hostClassByHost[$host] = $hostClass;
        }
        return $hostClass;
    }
}
