<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebSearchHitLists;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

/**
 * The plural container of the queries of ONE `searchTheWeb` call (the house pattern: its children are the per-query
 * {@see ArgusWebSearchHitList} loaders, ONE `argusLoad()` fires them concurrently — the wall clock is the slowest
 * query). No endpoint of its own; the `#[ArgusLoad]` carries only the batch's `timeout:`, which is read from the ROOT
 * of the cascade.
 *
 * @property ArgusWebSearchHitList[] $elements
 * @method ArgusWebSearchHitList[] getElements()
 */
#[ArgusLoad(cacheLevel: ArgusCache::CACHELEVEL_NONE, cacheTtl: 0, timeout: ArgusWebSearchHitList::SEARCH_TIMEOUT_SECONDS)]
class ArgusWebSearchHitLists extends WebSearchHitLists
{
    use ArgusTrait;
}
