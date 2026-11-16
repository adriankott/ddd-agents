<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The harness conversations of a comparison script ({@see ToolCallComparisonConversationFixture}), one per name, in
 * script order.
 *
 * @property ToolCallComparisonConversationFixture[] $elements
 * @method ToolCallComparisonConversationFixture[] getElements()
 * @method ToolCallComparisonConversationFixture|null first()
 * @method ToolCallComparisonConversationFixture|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonConversationFixtures extends ObjectSet
{
    /** The fixture named `$name`; null when the script declares none of that name. */
    public function findFixtureByName(string $name): ?ToolCallComparisonConversationFixture
    {
        return $this->getByUniqueKey(ToolCallComparisonConversationFixture::buildUniqueKeyOfName($name));
    }
}
