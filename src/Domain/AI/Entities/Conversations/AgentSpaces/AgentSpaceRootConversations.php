<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\AgentSpaces;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Root conversations of one agent space, newest change first.
 *
 * @property AgentSpaceRootConversation[] $elements
 * @method AgentSpaceRootConversation|null first()
 * @method AgentSpaceRootConversation|null getByUniqueKey(string $uniqueKey)
 * @method AgentSpaceRootConversation[] getElements()
 */
class AgentSpaceRootConversations extends ObjectSet
{
}
