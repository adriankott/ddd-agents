<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The processes of a workspace session.
 *
 * @property AgentSandboxProcess[] $elements
 * @method AgentSandboxProcess|null first()
 * @method AgentSandboxProcess|null getByUniqueKey(string $uniqueKey)
 * @method AgentSandboxProcess[] getElements()
 */
class AgentSandboxProcesses extends ObjectSet
{
}
