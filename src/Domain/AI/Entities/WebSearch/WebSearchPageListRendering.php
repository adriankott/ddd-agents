<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The merged page list of a search as the agent reads it ({@see WebSearchMergedHits::buildPageListRenderingWithinBudget()}):
 * the numbered page blocks that fit the render budget, and how many pages were left out.
 */
class WebSearchPageListRendering extends ValueObject
{
    /** The numbered page blocks, separated by blank lines. */
    public string $markdown = '';

    /** How many pages did not fit the render budget. */
    public int $omittedPageCount = 0;
}
