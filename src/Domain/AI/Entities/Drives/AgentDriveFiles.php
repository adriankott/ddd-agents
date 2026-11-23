<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Files and folders of a drive, in the order the store answered them.
 *
 * @property AgentDriveFile[] $elements
 * @method AgentDriveFile|null first()
 * @method AgentDriveFile|null getByUniqueKey(string $uniqueKey)
 * @method AgentDriveFile[] getElements()
 */
class AgentDriveFiles extends ObjectSet
{
    public function toLlmMarkdown(): string
    {
        $fileLines = [];
        foreach ($this->getElements() as $agentDriveFile) {
            $fileLines[] = '- ' . $agentDriveFile->buildLlmLine();
        }
        return $fileLines === [] ? '(no files)' : implode("\n", $fileLines);
    }
}
