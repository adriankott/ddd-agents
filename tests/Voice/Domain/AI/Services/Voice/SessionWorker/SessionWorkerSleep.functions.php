<?php

declare(strict_types=1);

/**
 * `sleep()` in the namespace of the session worker: the worker's unqualified `sleep(1)` (the wait for a held attach
 * lease) resolves to this function. It sleeps for real unless a test records the sleeps
 * ({@see \DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker\RecordedSessionWorkerSleeps}). Loaded by
 * {@see \DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionWorkerTestCase}.
 */

namespace DDD\Domain\AI\Services\Voice\SessionWorker;

use DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker\RecordedSessionWorkerSleeps;

if (!function_exists(__NAMESPACE__ . '\\sleep')) {
    function sleep(int $seconds): int
    {
        return RecordedSessionWorkerSleeps::sleepOrRecord($seconds);
    }
}
