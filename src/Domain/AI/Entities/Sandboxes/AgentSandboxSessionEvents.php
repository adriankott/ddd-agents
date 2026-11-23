<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The newest entries of a session's log, newest first.
 *
 * @property AgentSandboxSessionEvent[] $elements
 * @method AgentSandboxSessionEvent|null first()
 * @method AgentSandboxSessionEvent|null getByUniqueKey(string $uniqueKey)
 * @method AgentSandboxSessionEvent[] getElements()
 */
class AgentSandboxSessionEvents extends ObjectSet
{
}
