<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentReapStuckConversationsCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The reaper runs its passes in order — expiry, stuck conversations, timed-out batches, the application's channel
 * sessions, the settle pass, unfolded parents, unreported sub-agent run ends — each step as the account of the
 * conversation it works for, skips a conversation without an account instead of acting as the account of the step
 * before, runs every pass with the entity rights lifted, reports a failing candidate and goes on with the next, and
 * keeps its name, options, defaults and printed lines.
 */
class AgentReapStuckConversationsCommandTest extends UnitTestCase
{
    public const int ACCOUNT_ID_BEFORE_RUN = 99;

    protected ?Account $accountBeforeTest = null;

    protected RecoveryPassLog $recoveryPassLog;

    protected ReaperConversationsServiceDouble $conversationsServiceDouble;

    protected ReaperDelegationBatchesServiceDouble $delegationBatchesServiceDouble;

    protected ReaperSettlingAgentServiceDouble $agentServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        self::setSingletonInstance(AuthService::class, new CountingAuthServiceDouble());
        $this->recoveryPassLog = new RecoveryPassLog();
        $this->conversationsServiceDouble = new ReaperConversationsServiceDouble();
        $this->delegationBatchesServiceDouble = new ReaperDelegationBatchesServiceDouble($this->recoveryPassLog);
        $this->agentServiceDouble = new ReaperSettlingAgentServiceDouble($this->recoveryPassLog);
        // the rights apply before the run, so a pass that runs with them lifted is told apart
        DDDService::instance()->createEntityRightsRestrictionsStateSnapshot();
        DBEntity::setApplyRightsRestrictions(true);
    }

    protected function tearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        AuthService::instance()->setAccount($this->accountBeforeTest);
        self::resetSingletonInstance(AuthService::class);
        parent::tearDown();
    }

    public function testTheCommandKeepsItsNameOptionsAndDefaults(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentReapStuckConversationsCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:agent:reap', explode('|', $asCommandAttributes[0]->newInstance()->name)[0], 'the old name stays an alias');

        $agentReapStuckConversationsCommand = new AgentReapStuckConversationsCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $agentReapStuckConversationsCommand);
        $commandDefinition = $agentReapStuckConversationsCommand->getDefinition();
        self::assertSame('300', $commandDefinition->getOption('hung-after')->getDefault());
        self::assertSame('1800', $commandDefinition->getOption('revive-max-age')->getDefault());
        self::assertTrue($commandDefinition->getOption('settle-only')->isValueRequired());
        self::assertNull($commandDefinition->getOption('settle-only')->getDefault());
        self::assertSame(['hung-after', 'revive-max-age', 'settle-only'], array_keys($commandDefinition->getOptions()));
        self::assertSame(600, AgentReapStuckConversationsCommand::PURPOSE_SETTLE_QUIET_SECONDS);
    }

    public function testEveryPassRunsInOrderAsTheAccountOfItsConversation(): void
    {
        $this->scriptOneCandidateOfEveryPass();
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->purposeNamesClosedWithoutAnOwnerTurn = ['Test.Purpose'];
        $accountBeforeRun = $this->authenticateAccountWithId(self::ACCOUNT_ID_BEFORE_RUN);

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertSame(
            ['resume:1@11', 'timeout:30@13', 'resume:3@13', 'channel-sessions@13', 'settle:6@16', 'resume:4@14', 'notify:40@15'],
            $this->recoveryPassLog->entries
        );
        self::assertStringContainsString('Reaper: expired conversation 9 (stuck past revive-max-age) → FAILED', $display);
        self::assertStringContainsString('Reaper: dispatched resume for stuck conversation 1', $display);
        self::assertStringContainsString('Reaper: conversation 2 has no resolvable account — skipped (never dispatch under a foreign impersonation)', $display);
        self::assertStringContainsString('Reaper: timed out batch 30 → woke parent 3', $display);
        self::assertStringContainsString('Reaper: batch 31 has no parent with a resolvable account — skipped (foreign-impersonation guard)', $display);
        self::assertStringContainsString('Reaper: settled purposed conversation 6 → closed', $display);
        self::assertStringContainsString('Reaper: re-fired settled-but-unfolded parent 4', $display);
        self::assertStringContainsString('Reaper: wrote the lost run-end notification of deadline 40', $display);
        self::assertStringEndsWith("Reaper: dispatched 3 resume(s); stuck=2, timed-out batches=1, settled-unfolded=1, expired=1.\n", $display);
        self::assertSame(['expiryReviveMaxAge' => 1800, 'stuckHungAfter' => 300, 'stuckReviveMaxAge' => 1800], $this->conversationsServiceDouble->askedWindows);
        self::assertSame($accountBeforeRun, AuthService::instance()->getAccount(), 'the account of before the run is authenticated again');
    }

    public function testTheWindowsOfTheOptionsReachTheQueries(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        $commandTester->execute(['--hung-after' => '600', '--revive-max-age' => '3600']);

        self::assertSame(['expiryReviveMaxAge' => 3600, 'stuckHungAfter' => 600, 'stuckReviveMaxAge' => 3600], $this->conversationsServiceDouble->askedWindows);
        self::assertStringContainsString('Reaper: dispatched 0 resume(s); stuck=0, timed-out batches=0, settled-unfolded=0, expired=0.', $commandTester->getDisplay());
    }

    public function testAFailingChannelSessionSweepIsReportedAndTheLaterPassesRun(): void
    {
        $this->scriptOneCandidateOfEveryPass();
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->channelSessionsSweepThrows = true;

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Reaper: stale channel-session sweep failed: ' . AgentReapStuckConversationsProbeCommand::CHANNEL_SESSIONS_FAILURE_MESSAGE, $commandTester->getDisplay());
        self::assertContains('resume:4@14', $this->recoveryPassLog->entries);
        self::assertContains('notify:40@15', $this->recoveryPassLog->entries);
    }

    public function testEveryPassRunsWithTheEntityRightsLiftedAndTheRightsApplyAgainAfterTheRun(): void
    {
        $this->scriptOneCandidateOfEveryPass();
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->purposeNamesClosedWithoutAnOwnerTurn = ['Test.Purpose'];

        (new CommandTester($probeCommand))->execute([]);

        self::assertCount(7, $this->recoveryPassLog->rightsAppliedAtEntries);
        self::assertSame([false], array_values(array_unique($this->recoveryPassLog->rightsAppliedAtEntries)), 'no pass runs with the rights applied');
        self::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights apply again after the run');
    }

    public function testAFailingCandidateIsReportedAndTheNextOneIsWoken(): void
    {
        $this->conversationsServiceDouble->stuckConversationIds = [8, 1];
        $this->conversationsServiceDouble->failingConversationIds = [8];
        $this->conversationsServiceDouble->conversationsById[1] = $this->buildConversation(1, 11);

        $commandTester = new CommandTester($this->buildProbeCommand());
        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Reaper: failed to wake conversation 8: ' . ReaperConversationsServiceDouble::LOOKUP_FAILURE_MESSAGE, $commandTester->getDisplay());
        self::assertSame(['resume:1@11', 'channel-sessions@11'], $this->recoveryPassLog->entries);
        self::assertStringEndsWith("Reaper: dispatched 1 resume(s); stuck=2, timed-out batches=0, settled-unfolded=0, expired=0.\n", $commandTester->getDisplay());
    }

    public function testATimedOutBatchWhoseTimeoutNamesNoParentToWakeIsCountedAndWakesNothing(): void
    {
        $this->conversationsServiceDouble->conversationsById[3] = $this->buildConversation(3, 13);
        $this->delegationBatchesServiceDouble->timedOutBatchIds = [32];
        $this->delegationBatchesServiceDouble->parentConversationIdsByBatchId = [32 => 3];
        $this->delegationBatchesServiceDouble->wokenParentIdsByTimedOutBatchId = [32 => null];

        $commandTester = new CommandTester($this->buildProbeCommand());
        $commandTester->execute([]);

        self::assertSame(['timeout:32@13', 'channel-sessions@13'], $this->recoveryPassLog->entries, 'timed out as the account of the parent, no resume');
        self::assertStringNotContainsString('timed out batch 32', $commandTester->getDisplay());
        self::assertStringEndsWith("Reaper: dispatched 0 resume(s); stuck=0, timed-out batches=1, settled-unfolded=0, expired=0.\n", $commandTester->getDisplay());
    }

    public function testTheSettleOnlyPassTouchesNothingElse(): void
    {
        $this->scriptOneCandidateOfEveryPass();

        $commandTester = new CommandTester($this->buildProbeCommand());
        $exitCode = $commandTester->execute(['--settle-only' => '6']);

        self::assertSame(0, $exitCode);
        self::assertSame(['settle:6@16'], $this->recoveryPassLog->entries);
        self::assertSame([], $this->conversationsServiceDouble->askedWindows, 'no expiry, no stuck query');
        self::assertSame("Reaper: settled purposed conversation 6 → closed\nReaper: settle-only pass for conversation 6 — closed=1.\n", $commandTester->getDisplay());
    }

    public function testASettledConversationThatStaysOpenIsReportedAsOpen(): void
    {
        $this->conversationsServiceDouble->conversationsById[7] = $this->buildConversation(7, 17);

        $commandTester = new CommandTester($this->buildProbeCommand());
        $commandTester->execute(['--settle-only' => '7']);

        self::assertStringContainsString('Reaper: purposed conversation 7 left open (goal not reached, completion not durable yet, or supervisor work pending)', $commandTester->getDisplay());
        self::assertStringContainsString('Reaper: settle-only pass for conversation 7 — closed=0.', $commandTester->getDisplay());
    }

    /**
     * Expired 9; stuck 1 (account 11) and 2 (no account); timed-out batch 30 (parent 3, account 13, woken) and 31 (no
     * parent); settle candidate 6 (account 16, closed by the settle); unfolded parent 4 (account 14); unreported
     * deadline 40 (parent 5, account 15).
     */
    protected function scriptOneCandidateOfEveryPass(): void
    {
        $this->conversationsServiceDouble->expiredConversationIds = [9];
        $this->conversationsServiceDouble->stuckConversationIds = [1, 2];
        $this->conversationsServiceDouble->quietIdlePurposedConversationIds = [6];
        foreach ([1 => 11, 2 => null, 3 => 13, 4 => 14, 5 => 15, 6 => 16] as $conversationId => $accountId) {
            $this->conversationsServiceDouble->conversationsById[$conversationId] = $this->buildConversation($conversationId, $accountId);
        }
        $this->delegationBatchesServiceDouble->timedOutBatchIds = [30, 31];
        $this->delegationBatchesServiceDouble->parentConversationIdsByBatchId = [30 => 3, 40 => 5];
        $this->delegationBatchesServiceDouble->wokenParentIdsByTimedOutBatchId = [30 => 3];
        $this->delegationBatchesServiceDouble->settledUnfoldedParentIds = [4];
        $this->delegationBatchesServiceDouble->unreportedDeadlineIds = [40];
        $this->agentServiceDouble->conversationIdsClosedBySettle = [6];
    }

    protected function buildProbeCommand(): AgentReapStuckConversationsProbeCommand
    {
        return new AgentReapStuckConversationsProbeCommand(
            $this->recoveryPassLog,
            $this->conversationsServiceDouble,
            $this->delegationBatchesServiceDouble,
            $this->agentServiceDouble,
            new ReaperDelegationNotificationsServiceDouble($this->recoveryPassLog)
        );
    }

    protected function buildConversation(int $conversationId, ?int $accountId): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        $conversation->runState = AIConversationRunState::IDLE;
        $conversation->accountId = $accountId;
        $conversation->account = $accountId !== null ? $this->buildAccountWithId($accountId) : null;
        return $conversation;
    }

    protected function buildAccountWithId(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;
        return $account;
    }

    protected function authenticateAccountWithId(int $accountId): Account
    {
        $account = $this->buildAccountWithId($accountId);
        AuthService::instance()->setAccount($account);
        return $account;
    }
}
