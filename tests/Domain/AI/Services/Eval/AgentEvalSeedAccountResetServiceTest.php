<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountResetReport;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Dialect\DialectResolver;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountResetReportOfAnApplication;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;

/**
 * The clean start of a seed account without a database ({@see AgentEvalSeedAccountResetServiceDouble}): the refusal of
 * an account that is no evaluation account, the bounded wait for the account's runs in flight, the purge scoped to the
 * leased account between the application's two steps, and the count of the runs in flight on both engines.
 */
class AgentEvalSeedAccountResetServiceTest extends UnitTestCase
{
    public function testTheApplicationsStepsSurroundThePurgeAndTheReportListsThemInThatOrder(): void
    {
        $agentEvalSeedAccountResetService = new AgentEvalSeedAccountResetServiceDouble();
        $agentEvalSeedAccountResetService->leftoverDraftIds = [31, 32];
        $agentEvalSeedAccountResetService->notesToWipe = 4;
        $seedAgentSpace = self::buildAgentSpace(501);

        $agentEvalSeedAccountResetReport = $agentEvalSeedAccountResetService->resetAccountForCleanStart(self::buildAccount(7001), $seedAgentSpace);

        self::assertSame(
            [AgentEvalSeedAccountResetServiceDouble::STEP_BEFORE_PURGE, AgentEvalSeedAccountResetServiceDouble::STEP_PURGE, AgentEvalSeedAccountResetServiceDouble::STEP_AFTER_PURGE],
            $agentEvalSeedAccountResetService->stepsRun
        );
        self::assertSame($seedAgentSpace, $agentEvalSeedAccountResetService->seedAgentSpaceSeenBeforePurge, 'the application keeps what belongs to the seed');
        self::assertInstanceOf(AgentEvalSeedAccountResetReportOfAnApplication::class, $agentEvalSeedAccountResetReport, 'the application\'s report');
        self::assertSame(7001, $agentEvalSeedAccountResetReport->accountId);
        self::assertSame(3, $agentEvalSeedAccountResetReport->purgedConversationTrees);
        self::assertSame(120, $agentEvalSeedAccountResetReport->purgedConversationRows);
        self::assertSame(1, $agentEvalSeedAccountResetReport->keptConversationTreesInFlight);
        self::assertSame(AIConversationEvalPurgeReport::STOPPED_NO_TREE_LEFT, $agentEvalSeedAccountResetReport->conversationPurgeStoppedBecause);
        self::assertSame([
            'deleted 2 leftover draft(s): 31, 32',
            'purged 3 earlier conversation tree(s) with 120 dependent row(s)',
            '⚠ kept 1 conversation tree(s) with a run in flight',
            'wiped 4 notes',
        ], $agentEvalSeedAccountResetReport->buildProgressLines());
    }

    public function testThePurgeIsAWritingRunOverTheLeasedAccountAndEveryTreeCreatedBeforeNow(): void
    {
        $agentEvalSeedAccountResetService = new AgentEvalSeedAccountResetServiceDouble();
        $agentEvalSeedAccountResetService->keptAgentSpaceIds = [501, 502];

        $agentEvalSeedAccountResetService->resetAccountForCleanStart(self::buildAccount(7002));

        $purgeSettings = $agentEvalSeedAccountResetService->purgeSettings;
        self::assertNotNull($purgeSettings);
        self::assertFalse($purgeSettings->dryRun);
        self::assertSame([7002], $purgeSettings->accountIds, 'the purge is scoped to the leased account');
        self::assertSame(0, $purgeSettings->olderThanMinutes, 'every tree created before now');
        self::assertSame(1, $purgeSettings->freshHeartbeatMinutes, 'the lease excludes a concurrent case: only a run still beating is spared');
        self::assertSame(180, $purgeSettings->maxSeconds);
        self::assertSame(20, $purgeSettings->pauseMs);
        self::assertSame([501, 502], $purgeSettings->keptAgentSpaceIds, 'conversations on a kept agent space never go');
    }

    public function testAKeptTreeWithAFreshHeartbeatCountsAsKeptInFlight(): void
    {
        $agentEvalSeedAccountResetService = new AgentEvalSeedAccountResetServiceDouble();
        $agentEvalSeedAccountResetService->treesSkippedRunInFlight = 1;
        $agentEvalSeedAccountResetService->treesSkippedFreshHeartbeat = 2;

        $agentEvalSeedAccountResetReport = $agentEvalSeedAccountResetService->resetAccountForCleanStart(self::buildAccount(7001));

        self::assertSame(3, $agentEvalSeedAccountResetReport->keptConversationTreesInFlight);
    }

    public function testTheResetWaitsBoundedForTheAccountsRunsInFlight(): void
    {
        $agentEvalSeedAccountResetService = new AgentEvalSeedAccountResetServiceDouble();
        $agentEvalSeedAccountResetService->runsInFlightAnswers = [2, 1, 0];

        $agentEvalSeedAccountResetReport = $agentEvalSeedAccountResetService->resetAccountForCleanStart(self::buildAccount(7001));

        self::assertSame([5, 5], $agentEvalSeedAccountResetService->pauses);
        self::assertSame(10, $agentEvalSeedAccountResetReport->waitedSecondsForRunsInFlight);
        self::assertSame(0, $agentEvalSeedAccountResetReport->runsStillInFlightAfterWait);

        $stuckService = new AgentEvalSeedAccountResetServiceDouble();
        $stuckService->runsInFlightAnswers = array_fill(0, 30, 1);
        $stuckReport = $stuckService->resetAccountForCleanStart(self::buildAccount(7001));
        self::assertSame(60, $stuckReport->waitedSecondsForRunsInFlight, 'the wait is bounded');
        self::assertSame(1, $stuckReport->runsStillInFlightAfterWait);
        self::assertNotNull($stuckService->purgeSettings, 'the reset goes on; the running tree is kept by the purge');
        self::assertSame("waited 60 s for the account's runs in flight — 1 still running", $stuckReport->buildProgressLines()[0]);
    }

    public function testAnAccountThatIsNoConfiguredEvalAccountIsNeverReset(): void
    {
        $agentEvalSeedAccountResetService = new AgentEvalSeedAccountResetServiceDouble();
        $agentEvalSeedAccountResetService->runsInFlightAnswers = [1];

        try {
            $agentEvalSeedAccountResetService->resetAccountForCleanStart(self::buildAccount(9999), self::buildAgentSpace(501));
            self::fail('an account outside the evaluation accounts was reset');
        } catch (RuntimeException $refusal) {
            self::assertSame('Account 9999 is no configured eval account — its state is never reset.', $refusal->getMessage());
        }
        self::assertSame([], $agentEvalSeedAccountResetService->stepsRun, 'no step of the application, no purge');
        self::assertNull($agentEvalSeedAccountResetService->purgeSettings);
        self::assertSame([1], $agentEvalSeedAccountResetService->runsInFlightAnswers, 'not even the wait');
    }

    public function testWithoutAnApplicationTheModulesReportHoldsThePurgeAndACleanAccountSaysSo(): void
    {
        $agentEvalSeedAccountResetService = new AgentEvalSeedAccountResetServiceDouble();
        $agentEvalSeedAccountResetService->actsAsApplication = false;

        $agentEvalSeedAccountResetReport = $agentEvalSeedAccountResetService->resetAccountForCleanStart(self::buildAccount(7001));

        self::assertSame(AgentEvalSeedAccountResetReport::class, $agentEvalSeedAccountResetReport::class);
        self::assertSame([AgentEvalSeedAccountResetServiceDouble::STEP_PURGE], $agentEvalSeedAccountResetService->stepsRun, 'the module has no step before or after the purge');

        $cleanAccountService = new AgentEvalSeedAccountResetServiceDouble();
        $cleanAccountService->actsAsApplication = false;
        $cleanAccountService->purgedTrees = 0;
        $cleanAccountService->treesSkippedRunInFlight = 0;
        $cleanAccountService->purgedRowsByStep = [];
        self::assertSame(['seed account 7001 was clean'], $cleanAccountService->resetAccountForCleanStart(self::buildAccount(7001))->buildProgressLines());
    }

    public function testTheRunsInFlightCountQuotesEveryNameOnBothEngines(): void
    {
        $agentEvalSeedAccountResetService = new AgentEvalSeedAccountResetServiceDouble();
        $conversationTableName = DBAIConversationModel::getTableName();

        self::assertSame(
            "SELECT COUNT(*) FROM `{$conversationTableName}` WHERE"
            . ' `accountId` = :accountId AND (`runState` IN (:nonTerminalRunStates)'
            . ' OR `runToken` IS NOT NULL OR `runHeartbeatAt` > :freshHeartbeatCutoff)',
            $agentEvalSeedAccountResetService->buildRunsInFlightCountSql(DialectResolver::forDriver('pdo_mysql'))
        );
        self::assertSame(
            "SELECT COUNT(*) FROM \"{$conversationTableName}\" WHERE"
            . ' "accountId" = :accountId AND ("runState" IN (:nonTerminalRunStates)'
            . ' OR "runToken" IS NOT NULL OR "runHeartbeatAt" > :freshHeartbeatCutoff)',
            $agentEvalSeedAccountResetService->buildRunsInFlightCountSql(DialectResolver::forDriver('pdo_pgsql'))
        );
    }

    public function testTheHeartbeatCutoffIsOneMinuteBeforeNowInUtc(): void
    {
        $agentEvalSeedAccountResetService = new AgentEvalSeedAccountResetServiceDouble();
        $agentEvalSeedAccountResetService->clockUnixTimestamp = 1767225600; // 2026-01-01 00:00:00 UTC

        self::assertSame('2025-12-31 23:59:00', $agentEvalSeedAccountResetService->buildFreshHeartbeatCutoff());
    }

    protected static function buildAccount(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;
        return $account;
    }

    protected static function buildAgentSpace(int $agentSpaceId): AgentSpace
    {
        $agentSpace = AgentSpace::newInstance();
        $agentSpace->id = $agentSpaceId;
        return $agentSpace;
    }
}
