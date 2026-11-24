<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Infrastructure\Cache\Cache;
use LogicException;

/**
 * A cache group in memory, shared between the "processes" of a test: what one write puts in, a later read finds. Records
 * the batch calls (one read per call, the TTLs of writes and extensions) so a test can check the round trips.
 */
class InMemoryCacheGroupDouble extends Cache
{
    /** @var array<string, mixed> The values by key */
    public array $valuesByKey = [];

    /** @var array<string, int> The TTL of the last write or extension by key */
    public array $ttlSecondsByKey = [];

    /** @var int The batch reads (getMultiple) */
    public int $batchReads = 0;

    /** @var int The batch writes (setMultiple) */
    public int $batchWrites = 0;

    /** @var string[] The keys of every TTL extension, in order */
    public array $extendedKeys = [];

    public bool $failsEveryCall = false;

    public function __construct()
    {
        parent::__construct([]);
    }

    public function getCacheAdapter(): never
    {
        throw new LogicException('The in-memory cache group has no adapter.');
    }

    public function get(string ...$keys): mixed
    {
        $hits = $this->getMultiple($keys);
        return count($keys) === 1 ? ($hits[$keys[0]] ?? null) : $hits;
    }

    public function set(string $key, mixed $value, ?int $ttl = null, bool $deferred = false): void
    {
        $this->setMultiple([$key => $value], $ttl ?? $this->ttl);
    }

    public function getMultiple(array $keys): array
    {
        $this->failWhenAsked();
        $this->batchReads++;
        return array_intersect_key($this->valuesByKey, array_flip($keys));
    }

    public function setMultiple(array $valuesByKey, int $ttlSeconds): void
    {
        $this->failWhenAsked();
        $this->batchWrites++;
        foreach ($valuesByKey as $key => $value) {
            $this->valuesByKey[(string)$key] = $value;
            $this->ttlSecondsByKey[(string)$key] = $ttlSeconds;
        }
    }

    public function extendTtlOfKeys(array $keys, int $ttlSeconds): void
    {
        $this->failWhenAsked();
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->valuesByKey)) {
                $this->ttlSecondsByKey[$key] = $ttlSeconds;
                $this->extendedKeys[] = $key;
            }
        }
    }

    public function delete(string $key): void
    {
        unset($this->valuesByKey[$key], $this->ttlSecondsByKey[$key]);
    }

    public function clear(): void
    {
        $this->valuesByKey = [];
        $this->ttlSecondsByKey = [];
    }

    protected function failWhenAsked(): void
    {
        if ($this->failsEveryCall) {
            throw new LogicException('cache unavailable');
        }
    }
}
