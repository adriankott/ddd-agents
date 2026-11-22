<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * Fetches ONE web page as markdown through the application's url-to-markdown endpoint
 * (`AI.webSearch.endpoints.urlToMarkdown`): request `{"url": …}`, answer `data.page` with `url`, `ok`, `title`,
 * `markdown`, `reason` (a per-page failure arrives in the payload, never as an HTTP error, and is mapped onto the
 * entity). Service-side load ({@see \DDD\Domain\AI\Services\WebSearch\AIWebSearchService}): the URL is a per-call
 * parameter, so {@see uniqueKey()} carries it.
 */
#[ArgusLoad(
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHE_TTL_ONE_HOUR,
    timeout: self::CRAWL_TIMEOUT_SECONDS
)]
class ArgusWebPageMarkdown extends WebPageMarkdown
{
    use ArgusTrait, ArgusWebSearchConfiguredEndpointTrait;

    /** The capability of this repository: its endpoint, cache lifetime and timeout keys under `AI.webSearch`. */
    public const string WEB_SEARCH_CAPABILITY = 'urlToMarkdown';

    /**
     * The bound of one crawl: past it the page comes back as a TIMEOUT (uncached, so one retry really refetches)
     * while its siblings in a batch are kept.
     */
    public const float CRAWL_TIMEOUT_SECONDS = 15;

    /**
     * The key is the CANONICAL page ({@see WebPageMarkdown::canonicalUrlKey()}), not the typed string: two spellings of
     * one page hit one entry; without the URL two pages would share one entry. The payload keeps the URL as given.
     */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(md5(self::canonicalUrlKey((string)($this->url ?? ''))));
    }

    protected function getLoadPayload(): ?array
    {
        if (!($this->url ?? null)) {
            throw new BadRequestException('A url is required to fetch a web page as markdown.');
        }
        return ['body' => ['url' => $this->url]];
    }

    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $page = $callResponseData->data->page ?? null;
        if ($page === null) {
            $this->ok = false;
            // A transport rejection (the crawl ran past the bound, a connection error) arrives as a null response
            // with its reason on the operation when the batch keeps its fulfilled siblings: a timeout is retryable
            // once, anything else is not — the same classes the crawler itself reports.
            $rejectionReason = $apiOperation !== null ? (string)($apiOperation->rejectionReason ?? '') : '';
            if ($rejectionReason !== '') {
                $this->reason = stripos($rejectionReason, 'timed out') !== false || stripos($rejectionReason, 'timeout') !== false
                    ? self::REASON_TIMEOUT
                    : self::REASON_FETCH_FAILED;
            }
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }
        $this->url = $page->url ?? $this->url;
        $this->ok = (bool)($page->ok ?? false);
        $this->title = $page->title ?? null;
        $this->markdown = $page->markdown ?? null;
        $this->reason = $page->reason ?? null;
        // Cache only STABLE outcomes (success, SSRF-blocked, empty page). A transient failure (TIMEOUT /
        // FETCH_FAILED) marked successful would be positively cached for the full TTL — the agent's "retry once"
        // would then hit the cached failure instead of refetching. success=false writes an empty cache entry,
        // which reads back as a miss → the retry really refetches.
        $isStableOutcome = $this->ok
            || in_array($this->reason, [self::REASON_BLOCKED, self::REASON_NO_CONTENT], true);
        $this->postProcessLoadResponse($callResponseData, $isStableOutcome);
    }
}
