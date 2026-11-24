<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentDeliverOwedReportsAndResumeOwedTurnsCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;

/**
 * The recovery cron runs its three passes and then the application's passes, delivers and resumes each conversation
 * as its own account, skips a conversation without one, pushes a failing conversation to the back, writes nothing in
 * a dry run, and prints the summary with the application's counts and the billing entity's word. Every pass runs with
 * the entity rights lifted; a run that finds the previous one still holding the lock ends at once.
 */
class AgentDeliverOwedReportsAndResumeOwedTurnsCommandTest extends UnitTestCase
{
    protected ?Account $accountBeforeTest = null;

    protected RecoveryPassLog $recoveryPassLog;

    protected OwedRunsConversationsServiceDouble $conversationsServiceDouble;

    protected OwedRunsAgentServiceDouble $agentServiceDouble;

    protected OwedRunsOwnerReportDeliveryServiceDouble $ownerReportDeliveryServiceDouble;

    protected OwedRunsRunClaimsServiceDouble $runClaimsServiceDouble;

    protected OwedRunsMessagesServiceDouble $messagesServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        self::setSingletonInstance(AuthService::class, new CountingAuthServiceDouble());
        $this->recoveryPassLog = new RecoveryPassLog();
        $this->conversationsServiceDouble = new OwedRunsConversationsServiceDouble($this->recoveryPassLog);
        $this->agentServiceDouble = new OwedRunsAgentServiceDouble($this->recoveryPassLog);
        $this->ownerReportDeliveryServiceDouble = new OwedRunsOwnerReportDeliveryServiceDouble($this->recoveryPassLog);
        $this->runClaimsServiceDouble = new OwedRunsRunClaimsServiceDouble();
        $this->messagesServiceDouble = new OwedRunsMessagesServiceDouble();
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
        $asCommandAttributes = (new ReflectionClass(AgentDeliverOwedReportsAndResumeOwedTurnsCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:agent:deliver-owed-reports-and-resume-owed-turns', explode('|', $asCommandAttributes[0]->newInstance()->name)[0], 'the old name stays an alias');

        $deliverOwedCommand = new AgentDeliverOwedReportsAndResumeOwedTurnsCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $deliverOwedCommand);
        $commandDefinition = $deliverOwedCommand->getDefinition();
        self::assertSame(['quiet-seconds', 'batch', 'dry-run'], array_keys($commandDefinition->getOptions()));
        self::assertSame('300', $commandDefinition->getOption('quiet-seconds')->getDefault());
        self::assertSame('50', $commandDefinition->getOption('batch')->getDefault());
        self::assertFalse($commandDefinition->getOption('dry-run')->acceptValue());
    }

    public function testEveryPassActsAsTheAccountOfItsConversationAndTheApplicationPassesFollow(): void
    {
        $this->scriptCandidatesOfEveryPass();

        $commandTester = new CommandTester($this->buildProbeCommand());
        $exitCode = $commandTester->execute(['--batch' => '20', '--quiet-seconds' => '600']);
        $display = $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertSame(
            [
                'deliver:1@11', 'deliver:4@14', 'deferReport:4@14',
                'clearMarker:5@14', 'resume:6@16', 'resume:7@17', 'deferTurn:7@17',
                'deferTurn:8@18', 'resume:8@18',
                'applicationPasses:20:real@18',
            ],
            $this->recoveryPassLog->entries
        );
        self::assertSame(['reports:-/20', 'continuations:600/20', 'inboundLeftIdle:600/20'], $this->conversationsServiceDouble->askedSelections);
        self::assertSame([30, 30, 30, 30, 30], $this->messagesServiceDouble->askedLimits, 'the newest rows of every open conversation of passes 2 and 3');
        self::assertStringContainsString('OwedReports: delivered the owed report for conversation 1', $display);
        self::assertStringContainsString('OwedReports: conversation 2 — obligation closed (' . OwedRunsOwnerReportDeliveryServiceDouble::SUPPRESSION_REASON . ')', $display);
        self::assertStringContainsString('OwedReports: conversation 3 has no resolvable account — skipped (never deliver under a foreign impersonation)', $display);
        self::assertStringContainsString('OwedReports: failed for conversation 4: ' . OwedRunsAgentServiceDouble::FAILURE_MESSAGE, $display);
        self::assertStringContainsString('OwedTurns: conversation 5 ended normally — cleared the handover marker', $display);
        self::assertStringContainsString('OwedTurns: re-fired the lost continuation for conversation 6', $display);
        self::assertStringContainsString('OwedTurns: failed for conversation 7: ' . OwedRunsAgentServiceDouble::FAILURE_MESSAGE, $display);
        self::assertStringContainsString('LostResumes: re-fired the lost resume for conversation 8', $display);
        self::assertStringNotContainsString('conversation 9', $display, 'a conversation a worker claimed meanwhile is left to it');
        self::assertStringNotContainsString('conversation 10', $display, 'a closed conversation is left alone');
        self::assertStringEndsWith(
            "Owed-run recovery: 1 report(s) delivered, 1 obligation(s) closed/deferred for an inactive subscription or account, 1 turn(s) re-fired, 1 marker(s) cleared, 1 lost resume(s) recovered, "
            . AgentDeliverOwedReportsAndResumeOwedTurnsProbeCommand::APPLICATION_COUNT_PHRASE . ".\n",
            $display
        );
    }

    public function testEveryPassRunsWithTheEntityRightsLiftedAndTheRightsApplyAgainAfterTheRun(): void
    {
        $this->scriptCandidatesOfEveryPass();

        (new CommandTester($this->buildProbeCommand()))->execute([]);

        self::assertCount(10, $this->recoveryPassLog->rightsAppliedAtEntries);
        self::assertSame([false], array_values(array_unique($this->recoveryPassLog->rightsAppliedAtEntries)), 'no pass runs with the rights applied, the application\'s included');
        self::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights apply again after the run');
    }

    public function testARunThatFindsThePreviousRunStillActiveSkipsTheTick(): void
    {
        $this->scriptCandidatesOfEveryPass();
        $previousRunLock = (new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore()))
            ->createLock(AgentDeliverOwedReportsAndResumeOwedTurnsProbeCommand::PROBE_COMMAND_NAME);
        self::assertTrue($previousRunLock->acquire());

        try {
            $commandTester = new CommandTester($this->buildProbeCommand());
            $exitCode = $commandTester->execute([]);
        } finally {
            $previousRunLock->release();
        }

        self::assertSame(0, $exitCode);
        self::assertSame("Owed-run recovery: a previous run is still active — skipping this tick.\n", $commandTester->getDisplay());
        self::assertSame([], $this->recoveryPassLog->entries, 'no pass ran');
        self::assertSame([], $this->conversationsServiceDouble->askedSelections, 'no candidate was read');
    }

    public function testTheDryRunListsTheCandidatesAndWritesNothing(): void
    {
        $this->scriptCandidatesOfEveryPass();

        $commandTester = new CommandTester($this->buildProbeCommand());
        $commandTester->execute(['--dry-run' => true]);
        $display = $commandTester->getDisplay();

        self::assertSame(['applicationPasses:50:dry@-'], $this->recoveryPassLog->entries, 'no delivery, no resume, no marker write, no deferral');
        self::assertStringContainsString('OwedReports (dry-run): conversation 1 owes a report', $display);
        self::assertStringContainsString('OwedReports (dry-run): conversation 2 owes a report', $display, 'the scope is not judged in a dry run');
        self::assertStringContainsString('OwedTurns: conversation 5 ended normally — cleared the handover marker', $display);
        self::assertStringContainsString('OwedTurns (dry-run): conversation 6 owes a turn', $display);
        self::assertStringContainsString('LostResumes (dry-run): conversation 8 has an unanswered owner message and no owed marker', $display);
        self::assertStringContainsString('Owed-run recovery: 0 report(s) delivered, 0 obligation(s) closed/deferred', $display);
        self::assertStringContainsString('1 marker(s) cleared', $display);
    }

    public function testALostResumeWithCountedAttemptsAndNothingUnansweredIsLeftAlone(): void
    {
        $answeredConversation = $this->buildConversation(8, 18);
        $answeredConversation->resumeAttempts = 2;
        $answeredConversation->hasUnansweredInbound = false;
        $this->conversationsServiceDouble->conversationsById[8] = $answeredConversation;
        $this->conversationsServiceDouble->conversationIdsWithInboundLeftIdle = [8];

        $commandTester = new CommandTester($this->buildProbeCommand());
        $commandTester->execute([]);

        self::assertSame(['applicationPasses:50:real@-'], $this->recoveryPassLog->entries);
    }

    /**
     * Pass 1: 1 (account 11), 2 (an ended scope), 3 (no account), 4 (the delivery fails). Pass 2: 5 (ended on a final
     * text), 6 (account 16), 7 (the resume fails). Pass 3: 8 (account 18), 9 (claimed by a worker), 10 (closed).
     */
    protected function scriptCandidatesOfEveryPass(): void
    {
        foreach ([1 => 11, 2 => 12, 3 => null, 4 => 14, 5 => 15, 6 => 16, 7 => 17, 8 => 18, 9 => 19, 10 => 20] as $conversationId => $accountId) {
            $this->conversationsServiceDouble->conversationsById[$conversationId] = $this->buildConversation($conversationId, $accountId);
        }
        $this->conversationsServiceDouble->conversationsById[5]->endsOnFinalAssistantText = true;
        $this->conversationsServiceDouble->conversationsById[10]->runState = AIConversationRunState::COMPLETED;
        $this->conversationsServiceDouble->conversationIdsOwingReports = [1, 2, 3, 4];
        $this->conversationsServiceDouble->conversationIdsOwingContinuations = [5, 6, 7];
        $this->conversationsServiceDouble->conversationIdsWithInboundLeftIdle = [8, 9, 10];
        $this->ownerReportDeliveryServiceDouble->conversationIdsOfIneligibleScopes = [2];
        $this->agentServiceDouble->failingConversationIds = [4, 7];
        $this->runClaimsServiceDouble->claimedConversationIds = [9];
    }

    protected function buildProbeCommand(): AgentDeliverOwedReportsAndResumeOwedTurnsProbeCommand
    {
        return new AgentDeliverOwedReportsAndResumeOwedTurnsProbeCommand(
            $this->recoveryPassLog,
            $this->conversationsServiceDouble,
            $this->agentServiceDouble,
            $this->ownerReportDeliveryServiceDouble,
            $this->runClaimsServiceDouble,
            $this->messagesServiceDouble
        );
    }

    protected function buildConversation(int $conversationId, ?int $accountId): OwedRunsScriptedTailConversationDouble
    {
        $conversation = new OwedRunsScriptedTailConversationDouble();
        $conversation->id = $conversationId;
        $conversation->runState = AIConversationRunState::IDLE;
        $conversation->accountId = $accountId;
        $conversation->suppressionReason = null;
        if ($accountId === null) {
            $conversation->account = null;
            return $conversation;
        }
        $account = Account::newInstance();
        $account->id = $accountId;
        $conversation->account = $account;
        return $conversation;
    }
}
