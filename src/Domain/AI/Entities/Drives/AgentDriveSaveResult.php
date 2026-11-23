<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a save into a drive space did: the files it wrote (new nodes or new versions) and one line per file it did
 * not write, with the reason (missing, a conflict with a newer version, too large, the quota).
 */
class AgentDriveSaveResult extends ValueObject
{
    public ?AgentDriveFiles $savedFiles = null;

    /** @var string[] One line per file that was not written: path and reason */
    public array $refusedLines = [];

    public function hasSavedFiles(): bool
    {
        return $this->savedFiles !== null && $this->savedFiles->count() > 0;
    }
}
