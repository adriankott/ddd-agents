<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The Argus requests of one compared case ({@see ToolCallComparisonArgusRequest}), in the order they were sent; two
 * equal requests both stay.
 *
 * @property ToolCallComparisonArgusRequest[] $elements
 * @method ToolCallComparisonArgusRequest[] getElements()
 * @method ToolCallComparisonArgusRequest|null first()
 * @method ToolCallComparisonArgusRequest|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonArgusRequests extends ObjectSet
{
    /**
     * The stored document of every request, in order ({@see ToolCallComparisonArgusRequest::toArray()}).
     *
     * @return array
     */
    public function toArray(): array
    {
        $storedRequests = [];
        foreach ($this->getElements() as $toolCallComparisonArgusRequest) {
            $storedRequests[] = $toolCallComparisonArgusRequest->toArray();
        }
        return $storedRequests;
    }
}
