<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\WebSearch;

use DDD\Domain\AI\Entities\WebSearch\WebPageExtracts;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;

/**
 * The plural container of the page readings of ONE extraction call (the house pattern: its children are the per-page
 * {@see ArgusWebPageExtract} loaders, ONE `argusLoad()` fires them concurrently — the wall clock is the slowest
 * reading). No endpoint of its own; the `#[ArgusLoad]` carries only the batch's `timeout:`, read from the ROOT.
 *
 * @property ArgusWebPageExtract[] $elements
 * @method ArgusWebPageExtract[] getElements()
 */
#[ArgusLoad(cacheLevel: ArgusCache::CACHELEVEL_NONE, cacheTtl: 0, timeout: ArgusWebPageExtract::EXTRACTION_TIMEOUT_SECONDS)]
class ArgusWebPageExtracts extends WebPageExtracts
{
    use ArgusTrait;
}
