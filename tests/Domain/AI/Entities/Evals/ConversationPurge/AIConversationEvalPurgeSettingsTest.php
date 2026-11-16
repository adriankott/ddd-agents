<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The knobs of one purge run: the capped time budget, the two UTC cutoffs (computed here from the clock, never by the
 * database) and the first console line of the run.
 */
class AIConversationEvalPurgeSettingsTest extends UnitTestCase
{
    /** 2026-03-12 12:00:00 UTC */
    protected const int NOW_UNIX_TIMESTAMP = 1773316800;

    public function testTheBudgetIsCappedSoACronRunEndsInsideTheTickerTimeout(): void
    {
        $settings = AIConversationEvalPurgeSettings::newInstance();
        self::assertSame(240, $settings->maxSeconds, 'the default is the cap');
        $settings->maxSeconds = 1000;
        self::assertSame(240, $settings->getEffectiveMaxSeconds());
        $settings->maxSeconds = 0;
        self::assertSame(1, $settings->getEffectiveMaxSeconds());
        $settings->maxSeconds = 180;
        self::assertSame(180, $settings->getEffectiveMaxSeconds(), 'a smaller budget (the clean start of a seed account) stays');
        // the worst unchecked tail after the last budget check: one write (statement cap 20 s) and one pause (10 s)
        self::assertLessThan(300, AIConversationEvalPurgeSettings::MAXIMUM_SECONDS + 20 + 10);
    }

    public function testTheCutoffsAreUtcMomentsBeforeNow(): void
    {
        self::assertSame('2026-03-12 12:00:00', gmdate('Y-m-d H:i:s', self::NOW_UNIX_TIMESTAMP));
        $settings = AIConversationEvalPurgeSettings::newInstance();

        self::assertSame('2026-03-10 12:00:00', $settings->buildActivityCutoff(self::NOW_UNIX_TIMESTAMP), '48 hours by default');
        self::assertSame('2026-03-12 11:30:00', $settings->buildFreshHeartbeatCutoff(self::NOW_UNIX_TIMESTAMP), '30 minutes by default');

        $settings->olderThanHours = 0;
        self::assertSame('2026-03-12 11:00:00', $settings->buildActivityCutoff(self::NOW_UNIX_TIMESTAMP), 'at least one hour');
        $settings->olderThanMinutes = 0;
        self::assertSame('2026-03-12 12:00:00', $settings->buildActivityCutoff(self::NOW_UNIX_TIMESTAMP), 'minutes replace hours; 0 = every tree created before now');
        $settings->olderThanMinutes = 90;
        self::assertSame('2026-03-12 10:30:00', $settings->buildActivityCutoff(self::NOW_UNIX_TIMESTAMP));
        $settings->freshHeartbeatMinutes = 0;
        self::assertSame('2026-03-12 11:59:00', $settings->buildFreshHeartbeatCutoff(self::NOW_UNIX_TIMESTAMP), 'at least one minute');
    }

    public function testTheAccountsLineNamesTheDryRunAndTheEvalOnlySelection(): void
    {
        $settings = AIConversationEvalPurgeSettings::newInstance();
        self::assertSame('[dry run] accounts: 70, 71', $settings->buildAccountsLine([70, 71]));

        $settings->dryRun = false;
        $settings->evalOnly = true;
        self::assertSame('accounts: 70 — eval-marked conversation trees only', $settings->buildAccountsLine([70]));
    }
}
