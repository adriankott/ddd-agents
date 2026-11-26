<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Replacing;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;

/** Puts a registry of a test's choosing in place of the discovered one, and back. */
class InstalledConversationWidgetClasses extends ConversationWidgetClasses
{
    /**
     * @param array<string, class-string>|null $widgetClassesByWidgetType null = discover again
     * @return void
     */
    public static function installWidgetClassesByWidgetType(?array $widgetClassesByWidgetType): void
    {
        static::$widgetClassesByWidgetType = $widgetClassesByWidgetType;
    }
}
