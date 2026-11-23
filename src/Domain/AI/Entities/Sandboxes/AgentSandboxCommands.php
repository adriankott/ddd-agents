<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The last commands of a workspace session, newest first.
 *
 * @property AgentSandboxCommand[] $elements
 * @method AgentSandboxCommand|null first()
 * @method AgentSandboxCommand|null getByUniqueKey(string $uniqueKey)
 * @method AgentSandboxCommand[] getElements()
 */
class AgentSandboxCommands extends ObjectSet
{
}
