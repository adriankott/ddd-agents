<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Replacing\HostReplacing;

use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;

/** An application's own widget under the wire name the package also ships, not extending the package class. */
class ImageGalleryConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'ImageGallery';

    public const bool REPLACES_PACKAGE_WIDGET = true;

    protected function getWidgetFields(): array
    {
        return [];
    }
}
