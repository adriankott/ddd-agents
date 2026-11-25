<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\AgentReapStuckConversationsCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Voice\Support\AgentVoiceTestConfiguration;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The stale voice-call sweep of the reaper, the default of its channel-session pass: while the voice channel is
 * switched off it reads nothing and prints nothing; switched on it prints every call the sweep closed, with its answer
 * time in UTC, and one line with the count, before the reaper's summary line; it runs with the entity rights lifted; a
 * sweep that throws is reported as the failed channel-session pass, releases the sweep's lock and lets the later
 * passes run; a run that starts while a sweep is active skips the sweep and runs its other passes.
 */
class AgentReapStaleVoiceCallsSweepTest extends UnitTestCase
{
    /** @var int The moment the fixture calls are placed around */
    public const int NOW_UNIX_TIME = 1_800_000_000;

    public const string SUMMARY_LINE_OF_AN_EMPTY_RUN = "Reaper: dispatched 0 resume(s); stuck=0, timed-out batches=0, settled-unfolded=0, expired=0.\n";

    /** @var array<string, mixed>|null The config values before the test declared the voice channel */
    protected ?array $configValuesBeforeTheTest = null;

    protected ?string $timeZoneBeforeTheTest = null;

    protected RecoveryPassLog $recoveryPassLog;

    protected ReaperStaleVoiceCallsServiceDouble $staleVoiceCallsServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timeZoneBeforeTheTest = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        $this->recoveryPassLog = new RecoveryPassLog();
        $this->staleVoiceCallsServiceDouble = new ReaperStaleVoiceCallsServiceDouble($this->recoveryPassLog);
        // the rights apply before the run, so a sweep that runs with them lifted is told apart
        DDDService::instance()->createEntityRightsRestrictionsStateSnapshot();
        DBEntity::setApplyRightsRestrictions(true);
    }

    protected function tearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        if ($this->configValuesBeforeTheTest !== null) {
            AgentVoiceTestConfiguration::restoreConfigValues($this->configValuesBeforeTheTest);
        }
        date_default_timezone_set((string)$this->timeZoneBeforeTheTest);
        parent::tearDown();
    }

    public function testTheSweepHasALockNameOfItsOwn(): void
    {
        self::assertSame('app:agent:reap:stale-voice-calls', AgentReapStuckConversationsCommand::STALE_VOICE_CALLS_LOCK_NAME);
        self::assertNotSame((new AgentReapStuckConversationsCommand())->getName(), AgentReapStuckConversationsCommand::STALE_VOICE_CALLS_LOCK_NAME);
    }

    public function testWhileTheVoiceChannelIsSwitchedOffTheSweepReadsNothingAndPrintsNothing(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand($this->staleVoiceCallsServiceDouble));
        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame(0, $this->staleVoiceCallsServiceDouble->sweepCount);
        self::assertSame(['channel-sessions@-'], $this->recoveryPassLog->entries, 'the pass ran, the sweep did not');
        self::assertSame(self::SUMMARY_LINE_OF_AN_EMPTY_RUN, $commandTester->getDisplay());
    }

    public function testSwitchedOnTheSweepPrintsEveryClosedCallWithItsAnswerInUtcAndTheCountBeforeTheSummaryLine(): void
    {
        $this->switchTheVoiceChannelOn();
        $this->staleVoiceCallsServiceDouble->closedCalls = [$this->buildClosedCallWithSession(), $this->buildClosedCallWithoutSession()];

        $commandTester = new CommandTester($this->buildProbeCommand($this->staleVoiceCallsServiceDouble));
        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            "Reaper: closed stale voice call 7 (conversation 40, provider session live_session_1, answered 2027-01-15T05:58:20Z)\n"
            . "Reaper: closed stale voice call 8 (conversation none, provider session none, answered unknown)\n"
            . "Reaper: closed 2 stale voice call(s) (still marked running 120 minutes after their answer).\n"
            . self::SUMMARY_LINE_OF_AN_EMPTY_RUN,
            $commandTester->getDisplay()
        );
        self::assertSame(1, $this->staleVoiceCallsServiceDouble->sweepCount);
    }

    public function testASweepThatClosesNothingPrintsTheCountWithTheStaleAgeOfTheCallsService(): void
    {
        $this->switchTheVoiceChannelOn();
        $this->staleVoiceCallsServiceDouble->staleAfterMinutes = 180;

        $commandTester = new CommandTester($this->buildProbeCommand($this->staleVoiceCallsServiceDouble));
        $commandTester->execute([]);

        self::assertSame(
            "Reaper: closed 0 stale voice call(s) (still marked running 180 minutes after their answer).\n" . self::SUMMARY_LINE_OF_AN_EMPTY_RUN,
            $commandTester->getDisplay()
        );
    }

    public function testTheSweepRunsWithTheEntityRightsLiftedAndTheRightsApplyAgainAfterTheRun(): void
    {
        $this->switchTheVoiceChannelOn();

        (new CommandTester($this->buildProbeCommand($this->staleVoiceCallsServiceDouble)))->execute([]);

        self::assertSame(['channel-sessions@-', 'voice-sweep@-'], $this->recoveryPassLog->entries);
        self::assertSame([false, false], $this->recoveryPassLog->rightsAppliedAtEntries);
        self::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights apply again after the run');
    }

    public function testAFailingSweepIsReportedReleasesItsLockAndTheLaterPassesRun(): void
    {
        $this->switchTheVoiceChannelOn();
        $this->staleVoiceCallsServiceDouble->sweepFailure = new InternalErrorException('the call store is gone');

        $commandTester = new CommandTester($this->buildProbeCommand($this->staleVoiceCallsServiceDouble));
        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode, 'a pass that fails is reported and the run goes on');
        self::assertSame(
            "Reaper: stale channel-session sweep failed: the call store is gone\n" . self::SUMMARY_LINE_OF_AN_EMPTY_RUN,
            $commandTester->getDisplay()
        );

        $this->staleVoiceCallsServiceDouble->sweepFailure = null;
        $secondCommandTester = new CommandTester($this->buildProbeCommand($this->staleVoiceCallsServiceDouble));
        $secondCommandTester->execute([]);

        self::assertSame(2, $this->staleVoiceCallsServiceDouble->sweepCount, 'the lock of the failed sweep was released');
        self::assertStringContainsString('Reaper: closed 0 stale voice call(s)', $secondCommandTester->getDisplay());
    }

    public function testARunThatStartsWhileASweepIsActiveSkipsTheSweepAndRunsItsOtherPasses(): void
    {
        $this->switchTheVoiceChannelOn();
        $staleVoiceCallsServiceDoubleOfTheSecondRun = new ReaperStaleVoiceCallsServiceDouble($this->recoveryPassLog);
        $this->staleVoiceCallsServiceDouble->commandRunDuringTheSweep = $this->buildProbeCommand($staleVoiceCallsServiceDoubleOfTheSecondRun);

        $commandTester = new CommandTester($this->buildProbeCommand($this->staleVoiceCallsServiceDouble));
        $commandTester->execute([]);

        self::assertSame(1, $this->staleVoiceCallsServiceDouble->sweepCount);
        self::assertSame(0, $staleVoiceCallsServiceDoubleOfTheSecondRun->sweepCount, 'two sweeps over the same calls would write each call end twice');
        self::assertSame(
            "Reaper: stale voice-call sweep skipped (a previous run is still closing calls).\n" . self::SUMMARY_LINE_OF_AN_EMPTY_RUN,
            $this->staleVoiceCallsServiceDouble->displayOfTheCommandRunDuringTheSweep
        );
        self::assertStringContainsString('Reaper: closed 0 stale voice call(s)', $commandTester->getDisplay());
    }

    protected function switchTheVoiceChannelOn(): void
    {
        $configValuesBefore = AgentVoiceTestConfiguration::declareAgentVoiceConfiguration(AgentVoiceTestConfiguration::buildSwitchedOnConfiguration());
        $this->configValuesBeforeTheTest ??= $configValuesBefore;
    }

    protected function buildProbeCommand(AgentVoiceCallsService $agentVoiceCallsService): AgentReapStuckConversationsProbeCommand
    {
        $probeCommand = new AgentReapStuckConversationsProbeCommand(
            $this->recoveryPassLog,
            new ReaperConversationsServiceDouble(),
            new ReaperDelegationBatchesServiceDouble($this->recoveryPassLog),
            new ReaperSettlingAgentServiceDouble($this->recoveryPassLog),
            new ReaperDelegationNotificationsServiceDouble($this->recoveryPassLog)
        );
        $probeCommand->agentVoiceCallsServiceDouble = $agentVoiceCallsService;
        return $probeCommand;
    }

    /** A call answered 7300 seconds before "now", on a conversation and with a provider session. */
    protected function buildClosedCallWithSession(): AgentVoiceCall
    {
        $closedCall = new AgentVoiceCall();
        $closedCall->id = 7;
        $closedCall->accountId = 12;
        $closedCall->aiConversationId = 40;
        $closedCall->providerCallId = 'live_session_1';
        $closedCall->startedAt = DateTime::fromTimestamp(self::NOW_UNIX_TIME - 7300);
        $closedCall->endedAt = DateTime::fromTimestamp(self::NOW_UNIX_TIME);
        $closedCall->endReason = AgentVoiceCallsService::END_REASON_REAPED_STALE;
        return $closedCall;
    }

    /** A call without a conversation, a provider session or an answer time. */
    protected function buildClosedCallWithoutSession(): AgentVoiceCall
    {
        $closedCall = new AgentVoiceCall();
        $closedCall->id = 8;
        $closedCall->accountId = 13;
        $closedCall->endedAt = DateTime::fromTimestamp(self::NOW_UNIX_TIME);
        $closedCall->endReason = AgentVoiceCallsService::END_REASON_REAPED_STALE;
        return $closedCall;
    }
}
