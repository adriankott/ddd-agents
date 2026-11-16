<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\SeedAccounts;

use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountResetReport;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The progress lines of the clean start of a seed account: the wait, the application's lines before the purge, the
 * purge, the application's lines after it — in that order — or one line that the account was clean.
 */
class AgentEvalSeedAccountResetReportTest extends UnitTestCase
{
    public function testTheLinesOfTheWaitAndThePurgeInTheirOrder(): void
    {
        $agentEvalSeedAccountResetReport = AgentEvalSeedAccountResetReport::newInstance();
        $agentEvalSeedAccountResetReport->accountId = 7;
        $agentEvalSeedAccountResetReport->waitedSecondsForRunsInFlight = 60;
        $agentEvalSeedAccountResetReport->runsStillInFlightAfterWait = 1;
        $agentEvalSeedAccountResetReport->purgedConversationTrees = 3;
        $agentEvalSeedAccountResetReport->purgedConversationRows = 120;
        $agentEvalSeedAccountResetReport->keptConversationTreesInFlight = 1;

        self::assertSame([
            "waited 60 s for the account's runs in flight — 1 still running",
            'purged 3 earlier conversation tree(s) with 120 dependent row(s)',
            '⚠ kept 1 conversation tree(s) with a run in flight',
        ], $agentEvalSeedAccountResetReport->buildProgressLines());

        $agentEvalSeedAccountResetReport->runsStillInFlightAfterWait = 0;
        self::assertSame(["waited 60 s for the account's runs in flight"], $agentEvalSeedAccountResetReport->buildWaitLines());
    }

    public function testACleanAccountGetsOneLine(): void
    {
        $agentEvalSeedAccountResetReport = AgentEvalSeedAccountResetReport::newInstance();
        $agentEvalSeedAccountResetReport->accountId = 7;

        self::assertSame(['seed account 7 was clean'], $agentEvalSeedAccountResetReport->buildProgressLines());
    }

    public function testTheApplicationsLinesSurroundThePurgeLines(): void
    {
        $agentEvalSeedAccountResetReport = new AgentEvalSeedAccountResetReportOfAnApplication();
        $agentEvalSeedAccountResetReport->accountId = 7;
        $agentEvalSeedAccountResetReport->waitedSecondsForRunsInFlight = 5;
        $agentEvalSeedAccountResetReport->purgedConversationTrees = 1;
        $agentEvalSeedAccountResetReport->deletedDraftIds = [41, 42];
        $agentEvalSeedAccountResetReport->wipedNotes = 2;

        self::assertSame([
            "waited 5 s for the account's runs in flight",
            'deleted 2 leftover draft(s): 41, 42',
            'purged 1 earlier conversation tree(s) with 0 dependent row(s)',
            'wiped 2 notes',
        ], $agentEvalSeedAccountResetReport->buildProgressLines());

        $nothingButTheApplicationsPart = new AgentEvalSeedAccountResetReportOfAnApplication();
        $nothingButTheApplicationsPart->wipedNotes = 1;
        self::assertSame(['wiped 1 notes'], $nothingButTheApplicationsPart->buildProgressLines(), 'an application line alone is no clean start');
    }
}
