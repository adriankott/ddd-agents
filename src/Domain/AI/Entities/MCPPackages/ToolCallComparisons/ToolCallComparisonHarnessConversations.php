<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The harness conversations of a comparison run ({@see ToolCallComparisonHarnessConversation}), one per fixture name,
 * in the order the harness created them (the root conversations first).
 *
 * @property ToolCallComparisonHarnessConversation[] $elements
 * @method ToolCallComparisonHarnessConversation[] getElements()
 * @method ToolCallComparisonHarnessConversation|null first()
 * @method ToolCallComparisonHarnessConversation|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonHarnessConversations extends ObjectSet
{
    /** The conversation created for the fixture `$name`; null when none was. */
    public function findConversationByName(string $name): ?AIConversation
    {
        return $this->getByUniqueKey(ToolCallComparisonHarnessConversation::buildUniqueKeyOfName($name))?->conversation;
    }
}
