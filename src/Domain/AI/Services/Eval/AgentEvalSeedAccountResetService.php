<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountResetReport;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Doctrine\DBAL\ArrayParameterType;
use RuntimeException;

/**
 * The CLEAN START of a seed account before an evaluation case: what an earlier case left on the account is removed,
 * so the case meets the account as a new owner would. Runs while the case holds the account's lease
 * ({@see AgentEvalSeedAccountLeasesService}), so no other case works on the account at the same time.
 *
 * The order of {@see resetAccountForCleanStart()}:
 *  1. the refusal of an account that is no configured evaluation account ({@see AgentEvalAccountsService});
 *  2. a bounded wait until no run of the account is in flight — a background run an earlier case left (a delegation
 *     child, a scheduled run) that is still running could write after the reset. A job still queued in the broker has
 *     no run state to wait for;
 *  3. {@see resetHostAccountStateBeforePurge()} — the application's state that must go before the conversations;
 *  4. the account's conversation trees created before now, with every dependent row, through
 *     {@see AIConversationEvalPurgeService::purgeEvalConversations()} for this one account (a tree with a run in flight or
 *     a fresh heartbeat stays and is reported); conversations on the agent spaces of {@see findKeptAgentSpaceIds()} are
 *     never purged;
 *  5. {@see resetHostAccountStateAfterPurge()} — the application's account state that goes after the conversations.
 *
 * What the clean start removed is reported on {@see newResetReport()}; an application that resets more of the account
 * returns its subclass of the report there and fills its fields in the two steps it implements.
 */
class AgentEvalSeedAccountResetService extends Service
{
    /** @var int Time budget of the conversation purge inside a clean start, in seconds. */
    public const int CONVERSATION_PURGE_MAX_SECONDS = 180;

    /** @var int Pause after every purge write of a clean start (milliseconds) — the account is leased, the rows are few. */
    public const int CONVERSATION_PURGE_PAUSE_MS = 20;

    /**
     * @var int A heartbeat younger than this keeps a tree in the clean start, and counts as a run in flight in the wait:
     * the lease already excludes a concurrent case, so only a run that is really still beating is spared.
     */
    public const int CONVERSATION_PURGE_FRESH_HEARTBEAT_MINUTES = 1;

    /** @var int Upper bound of the wait for the account's runs in flight before the clean start, in seconds. */
    public const int RUNS_IN_FLIGHT_MAX_WAIT_SECONDS = 60;

    /** @var int Seconds between two checks of the wait. */
    public const int RUNS_IN_FLIGHT_POLL_SECONDS = 5;

    /**
     * Clean-start the account. `$seedAgentSpace` is the space the case works from, handed to
     * {@see resetHostAccountStateBeforePurge()} so the application keeps what belongs to the seed itself.
     *
     * @throws RuntimeException when the account is no configured evaluation account
     */
    public function resetAccountForCleanStart(Account $account, ?AgentSpace $seedAgentSpace = null): AgentEvalSeedAccountResetReport
    {
        $accountId = (int)($account->id ?? 0);
        if (!in_array($accountId, $this->findConfiguredEvalAccountIds(), true)) {
            throw new RuntimeException($this->buildRefusalOfAnAccountThatIsNoEvalAccount($accountId));
        }
        $agentEvalSeedAccountResetReport = $this->newResetReport();
        $agentEvalSeedAccountResetReport->accountId = $accountId;
        $this->waitForRunsInFlightOfAccount($account, $agentEvalSeedAccountResetReport);
        $this->resetHostAccountStateBeforePurge($account, $seedAgentSpace, $agentEvalSeedAccountResetReport);
        $this->purgeEarlierConversations($account, $agentEvalSeedAccountResetReport);
        $this->resetHostAccountStateAfterPurge($account, $agentEvalSeedAccountResetReport);
        return $agentEvalSeedAccountResetReport;
    }

    /** The report a clean start fills; an application that resets more returns its subclass with its fields. */
    protected function newResetReport(): AgentEvalSeedAccountResetReport
    {
        return AgentEvalSeedAccountResetReport::newInstance();
    }

    /** The refusal text of an account that is no configured evaluation account. */
    protected function buildRefusalOfAnAccountThatIsNoEvalAccount(int $accountId): string
    {
        return "Account $accountId is no configured eval account — its state is never reset.";
    }

    /**
     * The application's state of the account that goes BEFORE the conversation purge (state the conversations'
     * dependants point at, for example). Nothing by default.
     */
    protected function resetHostAccountStateBeforePurge(
        Account $account,
        ?AgentSpace $seedAgentSpace,
        AgentEvalSeedAccountResetReport $agentEvalSeedAccountResetReport
    ): void {
    }

    /** The application's state of the account that goes AFTER the conversation purge. Nothing by default. */
    protected function resetHostAccountStateAfterPurge(Account $account, AgentEvalSeedAccountResetReport $agentEvalSeedAccountResetReport): void
    {
    }

    /**
     * The agent spaces whose conversations a clean start never purges, whatever their account (spaces other evaluation
     * programs keep their history on). None by default.
     *
     * @return int[]
     */
    protected function findKeptAgentSpaceIds(): array
    {
        return [];
    }

    /** The account's conversation trees created before now, with every dependent row; the outcome on the report. */
    protected function purgeEarlierConversations(Account $account, AgentEvalSeedAccountResetReport $agentEvalSeedAccountResetReport): void
    {
        $aIConversationEvalPurgeReport = $this->runConversationPurge($this->buildCleanStartPurgeSettings($account));
        $agentEvalSeedAccountResetReport->purgedConversationTrees = $aIConversationEvalPurgeReport->treesPurged;
        $agentEvalSeedAccountResetReport->purgedConversationRows = $aIConversationEvalPurgeReport->getRowsTotal();
        $agentEvalSeedAccountResetReport->keptConversationTreesInFlight = $aIConversationEvalPurgeReport->treesSkippedRunInFlight
            + $aIConversationEvalPurgeReport->treesSkippedFreshHeartbeat;
        $agentEvalSeedAccountResetReport->conversationPurgeStoppedBecause = $aIConversationEvalPurgeReport->stoppedBecause;
    }

    /**
     * The settings of the clean start's purge: a writing run over this one account, every tree created before now, the
     * clean start's time budget, pause and heartbeat window, the kept agent spaces.
     */
    protected function buildCleanStartPurgeSettings(Account $account): AIConversationEvalPurgeSettings
    {
        $aIConversationEvalPurgeSettings = AIConversationEvalPurgeSettings::newInstance();
        $aIConversationEvalPurgeSettings->dryRun = false;
        $aIConversationEvalPurgeSettings->accountIds = [(int)$account->id];
        $aIConversationEvalPurgeSettings->olderThanMinutes = 0;
        $aIConversationEvalPurgeSettings->maxSeconds = static::CONVERSATION_PURGE_MAX_SECONDS;
        $aIConversationEvalPurgeSettings->pauseMs = static::CONVERSATION_PURGE_PAUSE_MS;
        $aIConversationEvalPurgeSettings->freshHeartbeatMinutes = static::CONVERSATION_PURGE_FRESH_HEARTBEAT_MINUTES;
        $aIConversationEvalPurgeSettings->keptAgentSpaceIds = $this->findKeptAgentSpaceIds();
        return $aIConversationEvalPurgeSettings;
    }

    /** One run of the evaluation purge with the clean start's settings. */
    protected function runConversationPurge(AIConversationEvalPurgeSettings $aIConversationEvalPurgeSettings): AIConversationEvalPurgeReport
    {
        /** @var AIConversationEvalPurgeService $aIConversationEvalPurgeService */
        $aIConversationEvalPurgeService = DDDService::instance()->getService(AIConversationEvalPurgeService::class);
        return $aIConversationEvalPurgeService->purgeEvalConversations($aIConversationEvalPurgeSettings);
    }

    /** @return int[] the evaluation accounts of the one source, {@see AgentEvalAccountsService::getEvalAccountIds()} */
    protected function findConfiguredEvalAccountIds(): array
    {
        /** @var AgentEvalAccountsService $agentEvalAccountsService */
        $agentEvalAccountsService = DDDService::instance()->getService(AgentEvalAccountsService::class);
        return $agentEvalAccountsService->getEvalAccountIds();
    }

    /**
     * Wait — at most {@see RUNS_IN_FLIGHT_MAX_WAIT_SECONDS} — until no conversation of the account has a run in flight
     * or a fresh heartbeat. What is still in flight afterwards is kept by the purge and reported.
     */
    protected function waitForRunsInFlightOfAccount(Account $account, AgentEvalSeedAccountResetReport $agentEvalSeedAccountResetReport): void
    {
        $waitedSeconds = 0;
        $runsInFlight = $this->countRunsInFlightOfAccount($account);
        while ($runsInFlight > 0 && $waitedSeconds < static::RUNS_IN_FLIGHT_MAX_WAIT_SECONDS) {
            $this->pauseSeconds(static::RUNS_IN_FLIGHT_POLL_SECONDS);
            $waitedSeconds += static::RUNS_IN_FLIGHT_POLL_SECONDS;
            $runsInFlight = $this->countRunsInFlightOfAccount($account);
        }
        $agentEvalSeedAccountResetReport->waitedSecondsForRunsInFlight = $waitedSeconds;
        $agentEvalSeedAccountResetReport->runsStillInFlightAfterWait = $runsInFlight;
    }

    /**
     * The account's conversations whose run is in flight (a non-terminal run state, a run token) or that wrote a run
     * heartbeat within {@see CONVERSATION_PURGE_FRESH_HEARTBEAT_MINUTES}. The heartbeat column holds UTC; the cutoff is
     * computed here in UTC and bound, never taken from the server clock.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    protected function countRunsInFlightOfAccount(Account $account): int
    {
        return (int)EntityManagerFactory::getInstance()->getConnection()->fetchOne(
            $this->buildRunsInFlightCountSql(EntityManagerFactory::getDialect()),
            [
                'accountId' => (int)$account->id,
                'nonTerminalRunStates' => AIConversationRunState::NON_TERMINAL,
                'freshHeartbeatCutoff' => $this->buildFreshHeartbeatCutoff(),
            ],
            ['nonTerminalRunStates' => ArrayParameterType::STRING]
        );
    }

    /**
     * The UTC moment after which a run heartbeat counts as fresh: {@see CONVERSATION_PURGE_FRESH_HEARTBEAT_MINUTES}
     * before now, in the format the conversation columns store their moments in.
     */
    public function buildFreshHeartbeatCutoff(): string
    {
        return gmdate(
            AIConversationEvalPurgeSettings::CUTOFF_MOMENT_FORMAT,
            $this->currentUnixTimestamp() - static::CONVERSATION_PURGE_FRESH_HEARTBEAT_MINUTES * 60
        );
    }

    /**
     * The count of {@see countRunsInFlightOfAccount()}, every name quoted through the dialect; parameters `accountId`,
     * `nonTerminalRunStates` (a list) and `freshHeartbeatCutoff` (a UTC moment).
     */
    public function buildRunsInFlightCountSql(SqlDialect $sqlDialect): string
    {
        return 'SELECT COUNT(*) FROM ' . $sqlDialect->quote(DBAIConversationModel::getTableName())
            . ' WHERE ' . $sqlDialect->quote('accountId') . ' = :accountId'
            . ' AND (' . $sqlDialect->quote('runState') . ' IN (:nonTerminalRunStates)'
            . ' OR ' . $sqlDialect->quote('runToken') . ' IS NOT NULL'
            . ' OR ' . $sqlDialect->quote('runHeartbeatAt') . ' > :freshHeartbeatCutoff)';
    }

    /** @phpstan-impure the wall clock */
    protected function currentUnixTimestamp(): int
    {
        return time();
    }

    protected function pauseSeconds(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
