<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Infrastructure\Cache\Cache;
use LogicException;
use RuntimeException;

/**
 * A shared cache in memory: values and their TTLs by key. A miss reads `false`, as the framework's cache answers it.
 * With {@see $failsEveryCall} every read, write and delete throws.
 */
class InMemorySharedCache extends Cache
{
    /** @var array<string, mixed> */
    public array $valuesByKey = [];

    /** @var array<string, int|null> */
    public array $ttlSecondsByKey = [];

    public bool $failsEveryCall = false;

    public function __construct()
    {
        parent::__construct([]);
    }

    public function getCacheAdapter(): never
    {
        throw new LogicException('The in-memory shared cache has no adapter.');
    }

    public function get(string ...$keys): mixed
    {
        $this->failWhenAsked();

        return array_key_exists($keys[0], $this->valuesByKey) ? $this->valuesByKey[$keys[0]] : false;
    }

    public function set(string $key, mixed $value, ?int $ttl = null, bool $deferred = false): void
    {
        $this->failWhenAsked();
        $this->valuesByKey[$key] = $value;
        $this->ttlSecondsByKey[$key] = $ttl;
    }

    public function delete(string $key): void
    {
        $this->failWhenAsked();
        unset($this->valuesByKey[$key], $this->ttlSecondsByKey[$key]);
    }

    protected function failWhenAsked(): void
    {
        if ($this->failsEveryCall) {
            throw new RuntimeException('cache unavailable');
        }
    }
}
