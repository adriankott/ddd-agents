<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

/**
 * The clock source and the id source installed in the process at one moment
 * ({@see \DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources}): what
 * {@see \DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources::installPinnedSources()} replaced, handed back to
 * {@see \DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources::restoreInstalledSources()} in a `finally`. Null
 * stands for "none installed" (the wall clock, the random generator).
 *
 * @phpstan-consistent-constructor
 */
class RecordedRunInstalledSources
{
    /** The clock source installed at that moment; null = the wall clock. */
    public ?RecordedRunNowSource $nowSource = null;

    /** The id source installed at that moment; null = the random generator. */
    public ?RecordedRunIdSource $idSource = null;

    /**
     * @param RecordedRunNowSource|null $recordedRunNowSource
     * @param RecordedRunIdSource|null $recordedRunIdSource
     * @return static
     */
    public static function forSources(?RecordedRunNowSource $recordedRunNowSource, ?RecordedRunIdSource $recordedRunIdSource): static
    {
        $recordedRunInstalledSources = new static();
        $recordedRunInstalledSources->nowSource = $recordedRunNowSource;
        $recordedRunInstalledSources->idSource = $recordedRunIdSource;
        return $recordedRunInstalledSources;
    }
}
