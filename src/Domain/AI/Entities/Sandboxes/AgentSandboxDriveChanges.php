<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The changes of a workspace the drive has not received yet.
 *
 * @property AgentSandboxDriveChange[] $elements
 * @method AgentSandboxDriveChange|null first()
 * @method AgentSandboxDriveChange|null getByUniqueKey(string $uniqueKey)
 * @method AgentSandboxDriveChange[] getElements()
 */
class AgentSandboxDriveChanges extends ObjectSet
{
}
