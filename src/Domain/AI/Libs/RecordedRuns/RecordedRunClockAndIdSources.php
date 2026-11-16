<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Libs\RecordedRuns;

use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunIdSource;
use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunInstalledSources;
use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunNowSource;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The clock source and the id source a recorded or replayed run installs, and the one place a switched site reads
 * "now" or mints an id: the wall clock and the random generator in every normal run; the pinned sources while a
 * recorded or replayed piece of work runs between {@see installPinnedSources()} and {@see restoreInstalledSources()}. A
 * comparison harness installs the sources for exactly one unit of work and restores the sources that were installed
 * before in a `finally`, also on a throw. Which sites read through it is the application's choice; an application
 * subclass at the same path documents them.
 *
 * A static library, not a service: never fetched from the container, never in a `Services` namespace. A write path
 * may call it on every row, and a container lookup there would break every worker whose compiled container predates
 * the class. The installed sources are process state: a worker's state resetter puts them back to null.
 */
class RecordedRunClockAndIdSources
{
    /** The pinned clock of this moment; null outside a recorded or replayed tool call (every normal run). */
    public static ?RecordedRunNowSource $installedNowSource = null;

    /** The pinned id source of this moment; null outside a recorded or replayed tool call. */
    public static ?RecordedRunIdSource $installedIdSource = null;

    /**
     * Installs the given sources and returns the sources installed before, which the caller hands to
     * {@see restoreInstalledSources()} in a `finally` once its piece of work ran:
     *
     *     $sourcesInstalledBefore = RecordedRunClockAndIdSources::installPinnedSources($nowSource, $idSource);
     *     try {
     *         // the recorded or replayed piece of work
     *     } finally {
     *         RecordedRunClockAndIdSources::restoreInstalledSources($sourcesInstalledBefore);
     *     }
     *
     * @param RecordedRunNowSource $recordedRunNowSource
     * @param RecordedRunIdSource $recordedRunIdSource
     * @return RecordedRunInstalledSources the sources installed before
     */
    public static function installPinnedSources(RecordedRunNowSource $recordedRunNowSource, RecordedRunIdSource $recordedRunIdSource): RecordedRunInstalledSources
    {
        $sourcesInstalledBefore = RecordedRunInstalledSources::forSources(static::$installedNowSource, static::$installedIdSource);
        static::$installedNowSource = $recordedRunNowSource;
        static::$installedIdSource = $recordedRunIdSource;
        return $sourcesInstalledBefore;
    }

    /**
     * Installs the sources {@see installPinnedSources()} returned: the ones installed before its piece of work.
     *
     * @param RecordedRunInstalledSources $sourcesInstalledBefore
     * @return void
     */
    public static function restoreInstalledSources(RecordedRunInstalledSources $sourcesInstalledBefore): void
    {
        static::$installedNowSource = $sourcesInstalledBefore->nowSource;
        static::$installedIdSource = $sourcesInstalledBefore->idSource;
    }

    /** "Now": the pinned moment while a source is installed, else the wall clock. */
    public static function readNow(): DateTime
    {
        return static::$installedNowSource?->takeReading() ?? new DateTime();
    }

    /** A token of $byteCount random bytes as hex, or the pinned source's next one. */
    public static function mintHexToken(int $byteCount): string
    {
        return static::$installedIdSource?->mintHexToken($byteCount) ?? bin2hex(random_bytes(max(1, $byteCount)));
    }

    /** A random version-4 UUID, or the pinned source's next one. */
    public static function mintUuid(): string
    {
        if (static::$installedIdSource !== null) {
            return static::$installedIdSource->mintUuid();
        }
        $uuidBytes = random_bytes(16);
        $uuidBytes[6] = chr((ord($uuidBytes[6]) & 0x0f) | 0x40);
        $uuidBytes[8] = chr((ord($uuidBytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuidBytes), 4));
    }
}
