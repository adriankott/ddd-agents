<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Realtime;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The registered realtime message types, keyed by name.
 *
 * @property AgentRealtimeMessageType[] $elements
 * @method AgentRealtimeMessageType[] getElements()
 * @method AgentRealtimeMessageType|null getByUniqueKey(string $uniqueKey)
 */
class AgentRealtimeMessageTypes extends ObjectSet
{
    public function findMessageType(string $name): ?AgentRealtimeMessageType
    {
        return $this->getByUniqueKey(AgentRealtimeMessageType::uniqueKeyStatic($name));
    }
}
