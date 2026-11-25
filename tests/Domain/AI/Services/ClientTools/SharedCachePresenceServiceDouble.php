<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService;
use DDD\Infrastructure\Cache\Cache;

/** The presences in a given shared cache */
class SharedCachePresenceServiceDouble extends AgentClientPresenceService
{
    public ?Cache $sharedCacheDouble = null;

    public function __construct(?Cache $sharedCacheDouble)
    {
        $this->sharedCacheDouble = $sharedCacheDouble;
    }

    protected function getSharedCache(): ?Cache
    {
        return $this->sharedCacheDouble;
    }
}
