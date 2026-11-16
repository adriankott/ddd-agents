<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Tool calls of a comparison script ({@see ToolCallComparisonToolCall}) in script order: the cases, the fixture calls,
 * or the `before` calls of one case. Each call is held under the key of its own object, so two calls with the same id
 * both stay (the script's validation names the duplicate).
 *
 * @property ToolCallComparisonToolCall[] $elements
 * @method ToolCallComparisonToolCall[] getElements()
 * @method ToolCallComparisonToolCall|null first()
 * @method ToolCallComparisonToolCall|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonToolCalls extends ObjectSet
{
    /** @return string[] the ids of the calls, in order */
    public function getIds(): array
    {
        $ids = [];
        foreach ($this->getElements() as $toolCallComparisonToolCall) {
            $ids[] = $toolCallComparisonToolCall->id;
        }
        return $ids;
    }
}
