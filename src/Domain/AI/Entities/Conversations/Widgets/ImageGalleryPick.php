<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * What the owner picked on an {@see ImageGalleryConversationWidget}: the letters of the tiles and the file references
 * under them, in letter order. Read by the widget's own reader; reads nothing.
 */
class ImageGalleryPick extends ConversationWidgetAnswer
{
    /** @var string[] The picked letters (`A`, `C`), letter order */
    public array $letters = [];

    /** @var string[] The file references of the picked tiles (`media/901`), in the order of {@see $letters} */
    public array $fileReferences = [];
}
