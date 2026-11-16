<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One harness conversation of a comparison run: the conversation the harness created for a fixture of the script,
 * under the fixture's name.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonHarnessConversation extends ValueObject
{
    /** The fixture name calls refer to. */
    public string $name = '';

    /** The conversation this run created for it. */
    public ?AIConversation $conversation = null;

    public static function forConversation(string $name, AIConversation $conversation): static
    {
        $toolCallComparisonHarnessConversation = static::newInstance();
        $toolCallComparisonHarnessConversation->name = $name;
        $toolCallComparisonHarnessConversation->conversation = $conversation;
        return $toolCallComparisonHarnessConversation;
    }

    /** The key {@see ToolCallComparisonHarnessConversations} holds the conversation of `$name` under. */
    public static function buildUniqueKeyOfName(string $name): string
    {
        return self::class . '_' . $name;
    }

    public function uniqueKey(): string
    {
        return static::buildUniqueKeyOfName($this->name);
    }
}
