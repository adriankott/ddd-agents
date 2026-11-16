<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The clean start's count of an account's runs in flight against the database, on both engines: a conversation of
 * the account counts when its run state is not terminal, when it holds a run token or when its heartbeat is younger
 * than the clean start's window (UTC, bound as a parameter); an idle conversation with an old heartbeat and every
 * conversation of another account do not.
 */
class AgentEvalSeedAccountResetRunsInFlightDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 7001;

    protected const int OTHER_ACCOUNT_ID = 7002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'first@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'second@example.test']);
    }

    public function testRunStateTokenAndFreshHeartbeatCountAndNothingElse(): void
    {
        $this->insertConversation(1, self::ACCOUNT_ID, ['runState' => AIConversationRunState::IDLE, 'runHeartbeatAt' => $this->formatUtcMomentSecondsAgo(3600)]);
        $this->insertConversation(2, self::ACCOUNT_ID, ['runState' => AIConversationRunState::RUNNING]);
        $this->insertConversation(3, self::ACCOUNT_ID, ['runState' => AIConversationRunState::IDLE, 'runToken' => 'token-3']);
        $this->insertConversation(4, self::ACCOUNT_ID, ['runState' => AIConversationRunState::IDLE, 'runHeartbeatAt' => $this->formatUtcMomentSecondsAgo(10)]);
        $this->insertConversation(5, self::ACCOUNT_ID, ['runState' => AIConversationRunState::IDLE, 'runHeartbeatAt' => $this->formatUtcMomentSecondsAgo(300)]);
        $this->insertConversation(6, self::OTHER_ACCOUNT_ID, ['runState' => AIConversationRunState::RUNNING, 'runToken' => 'token-6']);

        self::assertSame(3, $this->buildProbe()->countRunsInFlightOfAccountForTest($this->buildAccount(self::ACCOUNT_ID)));
        self::assertSame(1, $this->buildProbe()->countRunsInFlightOfAccountForTest($this->buildAccount(self::OTHER_ACCOUNT_ID)));
    }

    public function testAnAccountWithoutConversationsHasNoRunInFlight(): void
    {
        self::assertSame(0, $this->buildProbe()->countRunsInFlightOfAccountForTest($this->buildAccount(self::ACCOUNT_ID)));
    }

    /** @param array<string, mixed> $runColumns */
    protected function insertConversation(int $conversationId, int $accountId, array $runColumns): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => $conversationId, 'accountId' => $accountId] + $runColumns);
    }

    protected function formatUtcMomentSecondsAgo(int $seconds): string
    {
        return gmdate(AIConversationEvalPurgeSettings::CUTOFF_MOMENT_FORMAT, time() - $seconds);
    }

    protected function buildProbe(): AgentEvalSeedAccountResetServiceRunsInFlightProbe
    {
        return new AgentEvalSeedAccountResetServiceRunsInFlightProbe();
    }

    protected function buildAccount(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;
        return $account;
    }
}
