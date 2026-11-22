<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The facts read off ONE page for an extraction task ({@see WebPageExtractFact}), in the order the extractor gave them.
 *
 * @property WebPageExtractFact[] $elements
 * @method WebPageExtractFact[] getElements()
 */
class WebPageExtractFacts extends ObjectSet
{
}
