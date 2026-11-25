<?php

declare(strict_types=1);

/**
 * The handler polls for its call with an unqualified `usleep()`: this function of the handler's namespace answers that
 * call, so a test records the wait instead of sleeping (RecordedAttachHandlerSleeps). Outside a recording it sleeps.
 */

namespace DDD\Domain\AI\MessageHandlers\Voice\SessionWorker;

use DDD\Tests\Agents\Voice\Domain\AI\MessageHandlers\Voice\SessionWorker\RecordedAttachHandlerSleeps;

if (!function_exists(__NAMESPACE__ . '\\usleep')) {
    function usleep(int $microseconds): void
    {
        RecordedAttachHandlerSleeps::sleepOrRecord($microseconds);
    }
}
