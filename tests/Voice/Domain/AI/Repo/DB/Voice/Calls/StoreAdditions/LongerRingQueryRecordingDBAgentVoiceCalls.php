<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Repo\DB\Voice\Calls\StoreAdditions;

/**
 * The call table's set repository of an application whose own medium rings longer than the configured ring length:
 * it answers the ring window of a row without a deadline itself, and leaves the query of the open calls to the base.
 */
class LongerRingQueryRecordingDBAgentVoiceCalls extends QueryRecordingDBAgentVoiceCalls
{
    public const int LONGER_RING_SECONDS = 300;

    protected function getRingSecondsWithoutDeadline(): int
    {
        return static::LONGER_RING_SECONDS;
    }
}
