<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The registered client tool request types, keyed by name.
 *
 * @property AgentClientToolRequestType[] $elements
 * @method AgentClientToolRequestType[] getElements()
 * @method AgentClientToolRequestType|null getByUniqueKey(string $uniqueKey)
 */
class AgentClientToolRequestTypes extends ObjectSet
{
    public function findRequestType(string $name): ?AgentClientToolRequestType
    {
        return $this->getByUniqueKey(AgentClientToolRequestType::uniqueKeyStatic($name));
    }

    /** Whether requests of the type are sent: registered and switched on */
    public function isEnabledRequestType(string $name): bool
    {
        return $this->findRequestType($name)?->isEnabled === true;
    }
}
