<?php

declare(strict_types=1);

namespace ExampleApplication\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;

/**
 * An application's own embeddable gallery under the package's wire name, outside the module namespace as a real
 * application's class is; loaded by the test that uses it (no autoloader maps this namespace).
 */
class ImageGalleryConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'ImageGallery';

    public const bool REPLACES_PACKAGE_WIDGET = true;

    public const bool EMBEDDABLE = true;

    public const string EMBEDDED_SIZE_CLASS = 'compact';

    protected function getWidgetFields(): array
    {
        return [];
    }
}
