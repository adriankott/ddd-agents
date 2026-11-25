<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\MessageHandlers\Voice\SessionWorker;

/**
 * The waits of the attach handler between two lookups of the call: recorded instead of slept while a test runs
 * ({@see startRecording()}), real sleeps otherwise. Reached through the `usleep()` of the handler's namespace
 * (SessionAttachSleep.functions.php).
 */
class RecordedAttachHandlerSleeps
{
    public static bool $recordsInsteadOfSleeping = false;

    /** @var int[] The microseconds of every wait, in order */
    public static array $sleptMicroseconds = [];

    public static function startRecording(): void
    {
        static::$recordsInsteadOfSleeping = true;
        static::$sleptMicroseconds = [];
    }

    public static function stopRecording(): void
    {
        static::$recordsInsteadOfSleeping = false;
    }

    public static function sleepOrRecord(int $microseconds): void
    {
        if (!static::$recordsInsteadOfSleeping) {
            \usleep($microseconds);

            return;
        }
        static::$sleptMicroseconds[] = $microseconds;
    }
}
