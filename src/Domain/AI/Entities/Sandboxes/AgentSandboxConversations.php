<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The newest root conversations of a workspace's agent space.
 *
 * @property AgentSandboxConversation[] $elements
 * @method AgentSandboxConversation|null first()
 * @method AgentSandboxConversation|null getByUniqueKey(string $uniqueKey)
 * @method AgentSandboxConversation[] getElements()
 */
class AgentSandboxConversations extends ObjectSet
{
}
