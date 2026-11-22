<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

/**
 * The plural container of several page crawls fired CONCURRENTLY (the house pattern: a plural Argus set whose
 * children are the per-URL {@see ArgusWebPageMarkdown} loaders; ONE `argusLoad()` on the set commissions one
 * operation per child into one merged batch — the wall clock is the slowest page, not the sum). No endpoint of
 * its own; each child keys its operation and its cache on its URL ({@see ArgusWebPageMarkdown::uniqueKey()}). The
 * `#[ArgusLoad]` exists only for the `timeout:` — the batch's HTTP timeout is read from the ROOT of the cascade, so
 * the bound declared on the per-URL loader never reaches a batched crawl on its own.
 *
 * @property WebPageMarkdown[] $elements
 */
#[ArgusLoad(cacheLevel: ArgusCache::CACHELEVEL_NONE, cacheTtl: 0, timeout: ArgusWebPageMarkdown::CRAWL_TIMEOUT_SECONDS)]
class ArgusWebPageMarkdowns extends WebPageMarkdowns
{
    use ArgusTrait;
}
