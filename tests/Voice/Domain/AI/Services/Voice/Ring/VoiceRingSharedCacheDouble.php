<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\SharedCacheWithCountersDouble;
use RuntimeException;

/**
 * The package's shared cache in memory ({@see SharedCacheWithCountersDouble}), which fails with a LogicException
 * on demand, made to fail with a RuntimeException as well: a cache backend's own failure (a lost connection) is no
 * logic error, and the ring service must survive either kind.
 */
class VoiceRingSharedCacheDouble extends SharedCacheWithCountersDouble
{
    /** @var bool When true every read and write throws a RuntimeException instead */
    public bool $failsWithRuntimeException = false;

    protected function failWhenAsked(): void
    {
        if ($this->failsWithRuntimeException) {
            throw new RuntimeException('cache failed');
        }
        parent::failWhenAsked();
    }
}
