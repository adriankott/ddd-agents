<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

/**
 * The sleeps of the session worker while a test records them. The worker waits for a held attach lease with PHP's
 * `sleep(1)` and offers no method a test could override for it; `SessionWorkerSleep.functions.php` declares `sleep()`
 * in the worker's own namespace, and PHP calls that one for the worker's unqualified `sleep()`. It forwards to the real
 * `sleep()` unless a test switched recording on: then the seconds are kept here and nothing waits.
 */
class RecordedSessionWorkerSleeps
{
    public static bool $recordsInsteadOfSleeping = false;

    /** @var int[] The seconds of every recorded sleep, in order */
    public static array $sleptSeconds = [];

    public static function startRecording(): void
    {
        static::$recordsInsteadOfSleeping = true;
        static::$sleptSeconds = [];
    }

    public static function stopRecording(): void
    {
        static::$recordsInsteadOfSleeping = false;
        static::$sleptSeconds = [];
    }

    public static function sleepOrRecord(int $seconds): int
    {
        if (!static::$recordsInsteadOfSleeping) {
            return \sleep($seconds);
        }
        static::$sleptSeconds[] = $seconds;

        return 0;
    }
}
