<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\LoopExits;

use DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease\RunReleaseRealtimeSignalServiceDouble;

/** The realtime signal of the loop-exit stage: settling the in-flight signal is recorded, nothing is sent. */
class LoopExitRealtimeSignalServiceDouble extends RunReleaseRealtimeSignalServiceDouble
{
    public function settleInFlightSignal(): void
    {
        $this->eventLog->record('realtime settleInFlightSignal');
    }
}
