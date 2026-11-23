<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * The file card: files of a drive store shown in the chat (name, type, size, date, open and download). The block
 * carries only the store and the file references (`drive/<id>`); the component hydrates them through the store's
 * reads under the viewer's rights ({@see \DDD\Domain\AI\Services\Drives\AgentDriveStoresService::findFileCards()}),
 * so a card never holds a URL and a viewer without the right sees no file. Written by `showDriveFiles`.
 */
class DriveFilesConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'DriveFiles';

    public const string SUMMARY = 'Files of the drive as cards in the chat (name, type, size, date; open and download for people with the right).';

    public const string WHEN_TO_USE = 'The owner should open or download files you made or found: call showDriveFiles with their paths; never paste a link or the content instead.';

    public const string OWNER_CONTROL = 'open and download per file';

    public const bool READS_WIDGET_SELF_HYDRATION_KEY = true;

    /** @var int The most files of one card */
    public const int MAX_FILES = 20;

    /** @var string The drive store that holds the files */
    public string $store = '';

    /** @var string[] The file references, e.g. `drive/812`, at most 20 */
    public array $references = [];

    /** @var string|null A headline above the cards (at most 160 characters) */
    public ?string $title = null;

    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'store' => $this->store,
            'references' => array_values(array_slice($this->references, 0, self::MAX_FILES)),
            'title' => $this->title,
        ]);
    }
}
