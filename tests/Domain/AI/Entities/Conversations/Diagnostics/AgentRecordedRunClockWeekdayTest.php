<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Recorded-run comparison: the clock signal names the weekday beside its timestamp ("now = 2026-10-19 21:04 (Monday)");
 * a replay on the next day read as a difference on every model call. The weekday beside a
 * timestamp or a date is a placeholder like the timestamp; a weekday in prose stays.
 */
class AgentRecordedRunClockWeekdayTest extends UnitTestCase
{
    public function testTheWeekdayBesideTheClockMovesWithTheReplayDay(): void
    {
        $idNormalization = AgentRecordedRunIdNormalization::withHighWaterMarks([], []);
        $recorded = $idNormalization->normalizeText('[SIGNAL:clock] now = 2026-10-19 21:04:11 (Monday)');
        $replayed = $idNormalization->normalizeText('[SIGNAL:clock] now = 2026-10-20 00:58:02 (Tuesday)');

        self::assertSame('[SIGNAL:clock] now = <timestamp> (<weekday>)', $recorded);
        self::assertSame($recorded, $replayed);
        self::assertSame(
            $idNormalization->normalizeText('[SIGNAL:clock] now = 2026-11-15 01:29:25+01:00 (Sunday) · yesterday = 2026-11-14 (Saturday) · tomorrow = 2026-11-16 (Monday) · this week (Mon–Sun) = 2026-11-09 … 2026-11-15 · last week = 2026-11-02 … 2026-11-08 · this month = November 2026 (2026-11-01 … 2026-11-30) · last month = October 2026 (2026-10-01 … 2026-10-31)'),
            $idNormalization->normalizeText('[SIGNAL:clock] now = 2026-11-17 09:00:00+01:00 (Tuesday) · yesterday = 2026-11-16 (Monday) · tomorrow = 2026-11-18 (Wednesday) · this week (Mon–Sun) = 2026-11-16 … 2026-11-22 · last week = 2026-11-09 … 2026-11-15 · this month = December 2026 (2026-12-01 … 2026-12-31) · last month = November 2026 (2026-11-01 … 2026-11-30)'),
            'the days and weeks of the clock move with the replay day too'
        );
        self::assertSame('open on Monday and Tuesday', $idNormalization->normalizeText('open on Monday and Tuesday'), 'prose keeps its weekdays');
    }
}
