<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The drive mounts of one conversation, the own drive first.
 *
 * @property AgentDriveMount[] $elements
 * @method AgentDriveMount|null first()
 * @method AgentDriveMount|null getByUniqueKey(string $uniqueKey)
 * @method AgentDriveMount[] getElements()
 */
class AgentDriveMounts extends ObjectSet
{
    /** The agent space's own drive, null when no store gives one */
    public function findOwnDrive(): ?AgentDriveMount
    {
        foreach ($this->getElements() as $agentDriveMount) {
            if ($agentDriveMount->isOwnDrive) {
                return $agentDriveMount;
            }
        }
        return null;
    }

    /** The mount below `drivespaces/<mountName>/`, null when there is none */
    public function findByMountName(string $mountName): ?AgentDriveMount
    {
        foreach ($this->getElements() as $agentDriveMount) {
            if (!$agentDriveMount->isOwnDrive && $agentDriveMount->mountName === $mountName) {
                return $agentDriveMount;
            }
        }
        return null;
    }

    /** One state-note line per mount */
    public function buildStateLines(): string
    {
        $stateLines = [];
        foreach ($this->getElements() as $agentDriveMount) {
            $stateLines[] = $agentDriveMount->buildStateLine();
        }
        return implode("\n", $stateLines);
    }
}
