<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The ranked hits of ONE search query, in the engine's order ({@see WebSearchHit}).
 *
 * @property WebSearchHit[] $elements
 * @method WebSearchHit[] getElements()
 */
class WebSearchHits extends ObjectSet
{
}
