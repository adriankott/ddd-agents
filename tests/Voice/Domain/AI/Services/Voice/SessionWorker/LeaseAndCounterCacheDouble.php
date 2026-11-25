<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Infrastructure\Cache\Apc;
use LogicException;

/**
 * The shared cache group the session worker reads through `Cache::instance(Cache::CACHE_GROUP_REDIS)`: its locks (the
 * attach lease) and counters (the billed-seconds baseline) in memory. The worker has no method that hands it the
 * cache, so the test puts this double into the framework's instance registry under the group's name; it extends the
 * class the package kernel resolves for that group ({@see Apc}), because the registry's factory answers `static`.
 *
 * `$lockAnswers` scripts the lease: each `acquireLock()` takes the next answer (null = held by another worker, a
 * string = the token handed out); when the script is used up, a lock is handed out once and refused while held.
 */
class LeaseAndCounterCacheDouble extends Apc
{
    /** @var array<int, string|null> The scripted answers of `acquireLock()`, in order */
    public array $lockAnswers = [];

    /** @var array<string, string> The held locks: key => token */
    public array $lockTokensByKey = [];

    /** @var array<int, array{key: string, ttlSeconds: int}> */
    public array $lockAcquireRequests = [];

    /** @var array<int, array{key: string, token: string, ttlSeconds: int}> */
    public array $lockRefreshRequests = [];

    /** @var array<int, array{key: string, token: string}> */
    public array $lockReleaseRequests = [];

    /** @var bool The answer of `refreshLock()` */
    public bool $refreshKeepsTheLock = true;

    /** @var array<string, int> The counters by key */
    public array $countersByKey = [];

    /** @var array<int, array{key: string, by: int, ttlSeconds: int|null}> */
    public array $counterIncrements = [];

    /** @var bool Every call throws, as an unreachable cache does */
    public bool $failsEveryCall = false;

    public function __construct()
    {
        parent::__construct([]);
    }

    public function getCacheAdapter(): never
    {
        throw new LogicException('The lease and counter double has no adapter.');
    }

    public function acquireLock(string $key, int $ttlSeconds): ?string
    {
        $this->failWhenAsked();
        $this->lockAcquireRequests[] = ['key' => $key, 'ttlSeconds' => $ttlSeconds];
        if ($this->lockAnswers !== []) {
            $lockAnswer = array_shift($this->lockAnswers);
            if ($lockAnswer !== null) {
                $this->lockTokensByKey[$key] = $lockAnswer;
            }

            return $lockAnswer;
        }
        if (isset($this->lockTokensByKey[$key])) {
            return null;
        }

        return $this->lockTokensByKey[$key] = 'lease-token-' . count($this->lockAcquireRequests);
    }

    public function refreshLock(string $key, string $token, int $ttlSeconds): bool
    {
        $this->failWhenAsked();
        $this->lockRefreshRequests[] = ['key' => $key, 'token' => $token, 'ttlSeconds' => $ttlSeconds];

        return $this->refreshKeepsTheLock;
    }

    public function releaseLock(string $key, string $token): bool
    {
        $this->failWhenAsked();
        $this->lockReleaseRequests[] = ['key' => $key, 'token' => $token];
        if (($this->lockTokensByKey[$key] ?? null) !== $token) {
            return false;
        }
        unset($this->lockTokensByKey[$key]);

        return true;
    }

    public function getCounter(string $key): int
    {
        $this->failWhenAsked();

        return $this->countersByKey[$key] ?? 0;
    }

    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        $this->failWhenAsked();
        $this->counterIncrements[] = ['key' => $key, 'by' => $by, 'ttlSeconds' => $ttl];

        return $this->countersByKey[$key] = ($this->countersByKey[$key] ?? 0) + $by;
    }

    protected function failWhenAsked(): void
    {
        if ($this->failsEveryCall) {
            throw new LogicException('shared cache unreachable');
        }
    }
}
