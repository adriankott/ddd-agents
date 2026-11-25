<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime;

use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\InMemoryCacheGroupDouble;

/** A shared cache in memory with Redis' atomic counter and short locks */
class SharedCacheWithCountersDouble extends InMemoryCacheGroupDouble
{
    /** @var array<string, string> The held locks by key */
    public array $lockTokensByKey = [];

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        $this->failWhenAsked();
        $value = (int)($this->valuesByKey[$key] ?? 0) + $by;
        $this->valuesByKey[$key] = $value;
        if (!isset($this->ttlSecondsByKey[$key]) && $ttl !== null) {
            $this->ttlSecondsByKey[$key] = $ttl;
        }
        return $value;
    }

    public function acquireLock(string $key, int $ttlSeconds): ?string
    {
        if (isset($this->lockTokensByKey[$key])) {
            return null;
        }
        return $this->lockTokensByKey[$key] = bin2hex(random_bytes(8));
    }

    public function releaseLock(string $key, string $token): bool
    {
        if (($this->lockTokensByKey[$key] ?? null) !== $token) {
            return false;
        }
        unset($this->lockTokensByKey[$key]);
        return true;
    }
}
