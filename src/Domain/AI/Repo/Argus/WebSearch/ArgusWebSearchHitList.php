<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebSearchHit;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitList;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitListFilters;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHits;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * ONE query against the application's search endpoint (`AI.webSearch.endpoints.search`): ranked hits with the engine's
 * query-relevant excerpts, no model in between. Request: {@see WebSearchHitListFilters::buildSearchRequestBody()};
 * answer: `data.results[]` with `title`, `url`, `snippet`, `date`, `last_updated`; optional `data.cached` (the endpoint's
 * own cache answered: nothing is booked) and `data.billing.feeUsd` (the fee the endpoint reports: booked instead of the
 * configured fee). The endpoint holds the vendor key.
 * One child per query: an engine's multi-query request answers with one merged list without saying which query found
 * which row.
 *
 * Service-side load ({@see \DDD\Domain\AI\Services\WebSearch\AIWebSearchService::findHitListsForQueries()}), as a
 * child of {@see ArgusWebSearchHitLists}. The cache key is the content key (query and filters), so the same search
 * within the cache lifetime is served without a request; a transport failure is never cached.
 */
#[ArgusLoad(
    cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB,
    cacheTtl: ArgusCache::CACHE_TTL_ONE_HOUR,
    timeout: self::SEARCH_TIMEOUT_SECONDS,
)]
class ArgusWebSearchHitList extends WebSearchHitList
{
    use ArgusTrait, ArgusWebSearchConfiguredEndpointTrait;

    /** The capability of this repository: its endpoint, cache lifetime and timeout keys under `AI.webSearch`. */
    public const string WEB_SEARCH_CAPABILITY = 'search';

    /** @var bool Whether the endpoint answered from its own cache (`data.cached`): no engine request, no fee to book. */
    public bool $answeredFromEndpointCache = false;

    /** @var float|null The fee the endpoint reports for this request (`data.billing.feeUsd`); null when it reports none. */
    public ?float $endpointFeeUsd = null;

    /** One search answers in well under a second; past this bound it counts as failed. */
    public const float SEARCH_TIMEOUT_SECONDS = 15;
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->buildContentKey());
    }

    protected function getLoadPayload(): ?array
    {
        if (trim($this->query) === '') {
            throw new BadRequestException('A query is required for a web search.');
        }
        $filters = $this->filters ?? new WebSearchHitListFilters();
        return ['body' => $filters->buildSearchRequestBody($this->query)];
    }

    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {
        $resultRows = $callResponseData->data->results ?? null;
        if (!is_array($resultRows)) {
            $rejectionReason = $apiOperation !== null ? trim((string)($apiOperation->rejectionReason ?? '')) : '';
            $this->failureReason = $rejectionReason !== '' ? mb_substr($rejectionReason, 0, 160) : 'the search engine sent no result list';
            $this->hits = new WebSearchHits();
            // a transport failure is not an answer: never cached, the next call asks again
            $this->postProcessLoadResponse($callResponseData, false, false);
            return;
        }
        $webSearchHits = new WebSearchHits();
        foreach ($resultRows as $resultRow) {
            if (!is_object($resultRow)) {
                continue;
            }
            $webSearchHit = WebSearchHit::fromSearchResultRow($resultRow);
            if (($webSearchHit->url ?? '') !== '') {
                $webSearchHits->add($webSearchHit);
            }
        }
        $this->hits = $webSearchHits;
        $this->failureReason = null;
        $this->answeredFromEndpointCache = (bool)($callResponseData->data->cached ?? false);
        $reportedFeeUsd = $callResponseData->data->billing->feeUsd ?? null;
        $this->endpointFeeUsd = is_numeric($reportedFeeUsd) ? (float)$reportedFeeUsd : null;
        $this->postProcessLoadResponse($callResponseData, true);
    }
}
