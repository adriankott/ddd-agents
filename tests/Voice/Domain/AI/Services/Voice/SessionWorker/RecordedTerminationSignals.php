<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

/**
 * A SIGTERM handler that only records: the handler a consumer had before the call, which the worker gives the signal
 * back to after draining it. Installed for one test; the test case puts the run's own handler back.
 */
class RecordedTerminationSignals
{
    /** @var int[] The signal numbers received, in order */
    public static array $receivedSignals = [];

    public static function recordSignal(int $signalNumber): void
    {
        static::$receivedSignals[] = $signalNumber;
    }
}
