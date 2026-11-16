<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Libs\RecordedRuns;

use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunIdSource;
use DDD\Domain\AI\Entities\RecordedRuns\RecordedRunNowSource;
use DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;

/**
 * The pinned sources of a recorded piece of work: installed for the work and put back afterwards (also on a throw,
 * and nested), the same moment and seed read the same "now" and mint the same ids, and without a source the wall clock
 * and the random generator answer.
 */
class RecordedRunClockAndIdSourcesTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        RecordedRunClockAndIdSources::$installedNowSource = null;
        RecordedRunClockAndIdSources::$installedIdSource = null;
        parent::tearDown();
    }

    /** @return string[] three ids and the moment, minted under the given sources */
    protected function mintUnderPinnedSources(DateTime $pinnedMoment, string $seed): array
    {
        $sourcesInstalledBefore = RecordedRunClockAndIdSources::installPinnedSources(RecordedRunNowSource::pinnedAt($pinnedMoment), RecordedRunIdSource::seeded($seed));
        try {
            return [
                RecordedRunClockAndIdSources::mintHexToken(8),
                RecordedRunClockAndIdSources::mintUuid(),
                RecordedRunClockAndIdSources::mintHexToken(4),
                RecordedRunClockAndIdSources::readNow()->format('Y-m-d H:i:s'),
            ];
        } finally {
            RecordedRunClockAndIdSources::restoreInstalledSources($sourcesInstalledBefore);
        }
    }

    public function testTheSameMomentAndSeedMintTheSameIdsAndReadTheSameNow(): void
    {
        $pinnedMoment = new DateTime('2026-03-04 05:06:07');

        $recordedIds = $this->mintUnderPinnedSources($pinnedMoment, 'case-1');
        $replayedIds = $this->mintUnderPinnedSources($pinnedMoment, 'case-1');

        self::assertSame($recordedIds, $replayedIds);
        self::assertSame('2026-03-04 05:06:07', $recordedIds[3]);
        self::assertNotSame($recordedIds, $this->mintUnderPinnedSources($pinnedMoment, 'case-2'), 'another seed mints other ids');
        self::assertNull(RecordedRunClockAndIdSources::$installedIdSource, 'nothing stays installed');
        self::assertNull(RecordedRunClockAndIdSources::$installedNowSource);
    }

    public function testNestedSourcesPutBackTheOuterOnesAlsoOnAThrow(): void
    {
        $outerNowSource = RecordedRunNowSource::pinnedAt(new DateTime('2026-01-01 00:00:00'));
        $outerIdSource = RecordedRunIdSource::seeded('outer');
        $sourcesOfTheProcess = RecordedRunClockAndIdSources::installPinnedSources($outerNowSource, $outerIdSource);
        try {
            $sourcesInstalledBefore = RecordedRunClockAndIdSources::installPinnedSources(RecordedRunNowSource::pinnedAt(new DateTime('2026-02-02 00:00:00')), RecordedRunIdSource::seeded('inner'));
            try {
                self::assertSame('2026-02-02', RecordedRunClockAndIdSources::readNow()->format('Y-m-d'));
                throw new RuntimeException('the inner work fails');
            } catch (RuntimeException) {
                // the inner work failed; its sources go anyway
            } finally {
                RecordedRunClockAndIdSources::restoreInstalledSources($sourcesInstalledBefore);
            }
            self::assertSame($outerNowSource, RecordedRunClockAndIdSources::$installedNowSource);
            self::assertSame($outerIdSource, RecordedRunClockAndIdSources::$installedIdSource);
        } finally {
            RecordedRunClockAndIdSources::restoreInstalledSources($sourcesOfTheProcess);
        }
        self::assertNull(RecordedRunClockAndIdSources::$installedNowSource);
        self::assertNull(RecordedRunClockAndIdSources::$installedIdSource);
    }

    public function testWithoutASourceTheWallClockAndTheRandomGeneratorAnswer(): void
    {
        self::assertNotSame(RecordedRunClockAndIdSources::mintHexToken(8), RecordedRunClockAndIdSources::mintHexToken(8));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', RecordedRunClockAndIdSources::mintUuid());
        self::assertLessThan(5, abs(time() - RecordedRunClockAndIdSources::readNow()->getTimestamp()));
    }
}
