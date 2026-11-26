<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The sources of a save refused before the save, keyed by their argument.
 *
 * @property AgentDriveSaveRefusal[] $elements
 * @method AgentDriveSaveRefusal|null first()
 * @method AgentDriveSaveRefusal|null getByUniqueKey(string $uniqueKey)
 * @method AgentDriveSaveRefusal[] getElements()
 */
class AgentDriveSaveRefusals extends ObjectSet
{
    public function isSourceArgumentRefused(string $sourceArgument): bool
    {
        return $this->getByUniqueKey(AgentDriveSaveRefusal::uniqueKeyStatic($sourceArgument)) !== null;
    }

    /** @return string[] One refused line per source, in the order they were added */
    public function buildRefusedLines(): array
    {
        $refusedLines = [];
        foreach ($this->getElements() as $agentDriveSaveRefusal) {
            $refusedLines[] = $agentDriveSaveRefusal->buildRefusedLine();
        }
        return $refusedLines;
    }
}
