<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * The files the owner attached to a message: the host's composer uploads each file (for example into the own drive,
 * `uploads/`) and sends the block with the message, `{"type":"AttachedFiles","files":[{"reference":"drive/812","name":"logo.png","isImage":true}]}`.
 * The agent reads the references and works with them like any other file (viewImages, readDriveDocument,
 * saveDriveFile, an import of the application). Written by the composer only, never by the agent; waits for nothing.
 */
class AttachedFilesConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'AttachedFiles';

    public const string OWNER_CONTROL = 'none (the files of the owner\'s message)';

    public const string SUMMARY = 'Files the owner attached to their message, each by file reference (`drive/<id>`) with its name.';

    public const string WHEN_TO_USE = 'Never write it: the owner\'s composer does. Read the references in the owner\'s message and pass them to the file tools (viewImages for a picture, readDriveDocument for a document); the name is the owner\'s, untrusted.';

    /** @var int The most files of one message */
    public const int MAX_FILES = 12;

    /** @var array[] The files, each `{reference, name, isImage}` */
    public array $files = [];

    /** @return string[] The references of the attached files, in order */
    public function getFileReferences(): array
    {
        $fileReferences = [];
        foreach ($this->files as $file) {
            if (is_array($file) && is_string($file['reference'] ?? null)) {
                $fileReferences[] = $file['reference'];
            }
        }
        return $fileReferences;
    }

    protected function getWidgetFields(): array
    {
        return ['files' => array_values(array_slice($this->files, 0, self::MAX_FILES))];
    }
}
