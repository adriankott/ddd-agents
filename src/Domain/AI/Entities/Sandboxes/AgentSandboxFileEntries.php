<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The entries of one workspace directory.
 *
 * @property AgentSandboxFileEntry[] $elements
 * @method AgentSandboxFileEntry|null first()
 * @method AgentSandboxFileEntry|null getByUniqueKey(string $uniqueKey)
 * @method AgentSandboxFileEntry[] getElements()
 */
class AgentSandboxFileEntries extends ObjectSet
{
}
