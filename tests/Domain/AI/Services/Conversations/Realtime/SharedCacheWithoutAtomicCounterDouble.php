<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime;

use BadMethodCallException;

/** A shared cache in memory with short locks but no atomic counter, like Core's backends without one */
class SharedCacheWithoutAtomicCounterDouble extends SharedCacheWithCountersDouble
{
    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        throw new BadMethodCallException(static::class . ' does not support atomic counter operations.');
    }
}
