<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\AI\Services\Conversations\Schedules\ScheduleBudgetPotsService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a claimed occurrence of a schedule does when it is spawned: the run it starts and as whom, and what each guard
 * before the start writes. The guards run in this order: a previous run still in progress, the space, the
 * eligibility of the scope, the budget pot. The words of the skips and the pot's payer are in
 * {@see AIConversationSchedulesServiceOccurrenceSkipReasonsTest}, {@see AIConversationSchedulesServiceRedeclaredSkipWordsTest}
 * and {@see AIConversationSchedulesServiceBudgetPayerTest}.
 */
class AIConversationSchedulesServiceOccurrenceSpawnTest extends IntegrationTestCase
{
    protected const int SCHEDULE_ID = 4430;

    protected const int ACCOUNT_ID = 34;

    protected const int AGENT_SPACE_ID = 7;

    protected AutomationEligibilityServiceWithScriptedStateDouble $eligibilityService;

    protected ScheduleBudgetPotsServiceWithScriptedPotDouble $scheduleBudgetPotsService;

    protected AgentServiceRecordingDrivesDouble $agentService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eligibilityService = new AutomationEligibilityServiceWithScriptedStateDouble();
        $this->scheduleBudgetPotsService = new ScheduleBudgetPotsServiceWithScriptedPotDouble();
        $this->agentService = new AgentServiceRecordingDrivesDouble();
        $this->replaceService(AgentAutomationEligibilityService::class, $this->eligibilityService);
        $this->replaceService(ScheduleBudgetPotsService::class, $this->scheduleBudgetPotsService);
        $this->replaceService(AIConversationAgentService::class, $this->agentService);
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$entityServices = [];
    }

    #[DataProvider('provideKindsAndReportChannels')]
    public function testAClaimedOccurrenceStartsARunAsTheAccountOfTheSchedule(bool $oneTime, string $reportChannel): void
    {
        $this->scriptPreviousRun(null);
        $schedulesService = static::buildSchedulesServiceOfASpace();
        $schedule = static::buildClaimedSchedule($oneTime);
        $schedule->reportChannel = $reportChannel;

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule($schedule);

        self::assertSame(AgentServiceRecordingDrivesDouble::DRIVEN_CONVERSATION_ID, $occurrenceSpawn->runConversation?->id);
        self::assertNull($occurrenceSpawn->skipReason);
        self::assertNull($occurrenceSpawn->skipEligibility);
        self::assertSame(
            [
                [
                    'scope' => AIConversationDriveScope::ADMIN,
                    // a new conversation per occurrence
                    'conversationId' => null,
                    'agentSpaceId' => static::AGENT_SPACE_ID,
                    'message' => $schedule->composeSeedUserMessageText(null, null),
                    'async' => true,
                    'initiatorType' => AIConversation::INITIATOR_TYPE_SCHEDULE,
                    'scheduleId' => static::SCHEDULE_ID,
                    // the account the schedule is stored for, not the owner of its space
                    'runAsAccountId' => static::ACCOUNT_ID,
                    'purposeName' => AIConversationPurpose::SCHEDULE_RUN,
                    'reportChannel' => $reportChannel,
                ],
            ],
            $this->agentService->recordedDrives
        );
        self::assertSame([], $schedulesService->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
    }

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function provideKindsAndReportChannels(): array
    {
        return [
            'a recurring schedule reporting by email' => [false, AIConversationSchedule::REPORT_CHANNEL_EMAIL],
            'a one-time schedule reporting in the chat' => [true, AIConversationSchedule::REPORT_CHANNEL_CHAT],
        ];
    }

    public function testAScheduleWithoutASpaceStartsItsRunAtAccountLevel(): void
    {
        $this->scriptPreviousRun(null);
        $schedulesService = new SchedulesServiceRecordingOccurrenceWritesDouble();
        $schedule = static::buildClaimedSchedule(false, withAgentSpace: false);

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule($schedule);

        self::assertNotNull($occurrenceSpawn->runConversation);
        self::assertCount(1, $this->agentService->recordedDrives);
        self::assertNull($this->agentService->recordedDrives[0]['agentSpaceId']);
        self::assertSame(static::ACCOUNT_ID, $this->agentService->recordedDrives[0]['runAsAccountId']);
        self::assertSame([], $schedulesService->recordedWrites);
    }

    #[DataProvider('provideFinishedRunStates')]
    public function testAPreviousRunThatIsFinishedDoesNotHoldTheNextOneBack(string $runStateOfPreviousRun): void
    {
        $previousRun = new AIConversation();
        $previousRun->runState = $runStateOfPreviousRun;
        $this->scriptPreviousRun($previousRun);
        $schedulesService = static::buildSchedulesServiceOfASpace();

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule(static::buildClaimedSchedule(false));

        self::assertNotNull($occurrenceSpawn->runConversation);
        self::assertCount(1, $this->agentService->recordedDrives);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideFinishedRunStates(): array
    {
        return [
            'idle' => [AIConversationRunState::IDLE],
            'completed' => [AIConversationRunState::COMPLETED],
            'failed' => [AIConversationRunState::FAILED],
        ];
    }

    #[DataProvider('providePreviousRunsInProgress')]
    public function testAPreviousRunInProgressIsSkippedBeforeTheScopeAndThePotAreRead(string $runStateOfPreviousRun, bool $owesATurn): void
    {
        $previousRun = new AIConversation();
        $previousRun->runState = $runStateOfPreviousRun;
        if ($owesATurn) {
            $previousRun->agentTurnOwedAt = new DateTime('2026-10-01 09:00:00');
        }
        $this->scriptPreviousRun($previousRun);
        $schedulesService = static::buildSchedulesServiceOfASpace();

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule(static::buildClaimedSchedule(false));

        self::assertNull($occurrenceSpawn->runConversation);
        self::assertSame(AIConversationSchedulesService::OCCURRENCE_SKIP_REASON_PREVIOUS_RUN_IN_PROGRESS, $occurrenceSpawn->skipReason);
        // the claimed iteration is given back (a schedule with a run cap loses no run); the anchor is not re-armed:
        // the next slot is the next chance
        self::assertSame(['refund'], $schedulesService->recordedWrites);
        self::assertSame(0, $this->eligibilityService->eligibilityReadsCount);
        self::assertSame(0, $this->scheduleBudgetPotsService->potReadsCount);
        self::assertSame([], $this->agentService->recordedDrives);
    }

    public function testAOneTimeScheduleWhosePreviousRunIsInProgressIsSkippedWithoutARefund(): void
    {
        $previousRun = new AIConversation();
        $previousRun->runState = AIConversationRunState::RUNNING;
        $this->scriptPreviousRun($previousRun);
        $schedulesService = static::buildSchedulesServiceOfASpace();

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule(static::buildClaimedSchedule(true));

        self::assertSame(AIConversationSchedulesService::OCCURRENCE_SKIP_REASON_PREVIOUS_RUN_IN_PROGRESS, $occurrenceSpawn->skipReason);
        // a one-time schedule has no later slot to give the occurrence back to, as with the other skips
        self::assertSame([], $schedulesService->recordedWrites);
        self::assertSame([], $this->agentService->recordedDrives);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function providePreviousRunsInProgress(): array
    {
        return [
            'running' => [AIConversationRunState::RUNNING, false],
            'waiting for its delegates' => [AIConversationRunState::AWAITING_DELEGATES, false],
            'queued' => [AIConversationRunState::QUEUED, false],
            'idle, a turn still owed' => [AIConversationRunState::IDLE, true],
        ];
    }

    /**
     * @param string[] $expectedWrites
     */
    #[DataProvider('provideKindsWithTheWritesOfAScopeThatEnded')]
    public function testAScopeThatEndedIsSkippedBeforeThePotIsRead(bool $oneTime, array $expectedWrites): void
    {
        $this->scriptPreviousRun(null);
        $this->eligibilityService->fixtureState = AgentAutomationEligibility::STATE_ENDED;
        $schedulesService = static::buildSchedulesServiceOfASpace();
        $schedule = static::buildClaimedSchedule($oneTime);

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule($schedule);

        self::assertNull($occurrenceSpawn->runConversation);
        self::assertSame(AIConversationSchedulesService::OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE, $occurrenceSpawn->skipReason);
        self::assertTrue($occurrenceSpawn->skipEligibility?->isEnded());
        // the spawn closes nothing: the state sweep closes the schedules of a scope that ended
        self::assertSame($expectedWrites, $schedulesService->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        self::assertSame(0, $this->scheduleBudgetPotsService->potReadsCount);
        self::assertSame([], $this->agentService->recordedDrives);
    }

    /**
     * @return array<string, array{0: bool, 1: string[]}>
     */
    public static function provideKindsWithTheWritesOfAScopeThatEnded(): array
    {
        return [
            'a recurring schedule gets its occurrence back, without firing again now' => [false, ['refund']],
            'a one-time schedule is left as it is' => [true, []],
        ];
    }

    /**
     * @param string[] $expectedWrites
     */
    #[DataProvider('provideKindsWithTheWritesOfAUsedUpPot')]
    public function testAUsedUpPotStartsNoRunAndTellsTheOwner(bool $oneTime, array $expectedWrites, string $expectedStatus): void
    {
        $this->scriptPreviousRun(null);
        $this->scheduleBudgetPotsService->fixturePotIsUsedUp = true;
        $schedulesService = static::buildSchedulesServiceOfASpace();
        $schedule = static::buildClaimedSchedule($oneTime);

        $occurrenceSpawn = $schedulesService->spawnRunConversationForClaimedSchedule($schedule);

        self::assertNull($occurrenceSpawn->runConversation);
        self::assertSame(AIConversationSchedulesService::OCCURRENCE_SKIP_REASON_BUDGET_POT_EXHAUSTED, $occurrenceSpawn->skipReason);
        self::assertNull($occurrenceSpawn->skipEligibility);
        self::assertSame($expectedWrites, $schedulesService->recordedWrites);
        self::assertSame($expectedStatus, $schedule->status);
        self::assertSame([], $this->agentService->recordedDrives);
    }

    /**
     * @return array<string, array{0: bool, 1: string[], 2: string}>
     */
    public static function provideKindsWithTheWritesOfAUsedUpPot(): array
    {
        return [
            'a recurring schedule gets its occurrence back and waits for the next one' => [
                false,
                ['notice of the used-up pot', 'refund'],
                AIConversationScheduleStatus::ACTIVE,
            ],
            'a one-time schedule expires: a timed task a month late is worth nothing' => [
                true,
                ['notice of the used-up pot', 'status ' . AIConversationScheduleStatus::EXPIRED],
                AIConversationScheduleStatus::EXPIRED,
            ],
        ];
    }

    public function testASpawnAfterASkipCarriesNothingOfTheSkip(): void
    {
        $this->scriptPreviousRun(null);
        $this->eligibilityService->fixtureState = AgentAutomationEligibility::STATE_SUSPENDED;
        $schedulesService = static::buildSchedulesServiceOfASpace();

        $skippedOccurrence = $schedulesService->spawnRunConversationForClaimedSchedule(static::buildClaimedSchedule(false));
        $this->eligibilityService->fixtureState = AgentAutomationEligibility::STATE_ELIGIBLE;
        $spawnedOccurrence = $schedulesService->spawnRunConversationForClaimedSchedule(static::buildClaimedSchedule(false));

        self::assertTrue($skippedOccurrence->skipEligibility?->isSuspended());
        // the answer of the same service to the next occurrence: a run, and nothing of the skip before it
        self::assertNotNull($spawnedOccurrence->runConversation);
        self::assertNull($spawnedOccurrence->skipReason);
        self::assertNull($spawnedOccurrence->skipEligibility);
    }

    /** The newest earlier run of every schedule; null = none. Once per test: a service of the container is replaced once. */
    protected function scriptPreviousRun(?AIConversation $previousRun): void
    {
        $this->replaceService(AIConversationsService::class, new ConversationsServiceWithScheduleRunDouble($previousRun));
        StaticRegistry::$entityServices = [];
    }

    protected static function buildSchedulesServiceOfASpace(): SchedulesServiceRecordingOccurrenceWritesDouble
    {
        $schedulesService = new SchedulesServiceRecordingOccurrenceWritesDouble();
        $schedulesService->fixtureAgentSpace = new AgentSpace();
        $schedulesService->fixtureAgentSpace->id = static::AGENT_SPACE_ID;
        return $schedulesService;
    }

    /** A schedule of the space (or of no space) whose occurrence was just claimed, with its account attached. */
    protected static function buildClaimedSchedule(bool $oneTime, bool $withAgentSpace = true): AIConversationSchedule
    {
        $schedule = new AIConversationSchedule();
        $schedule->id = static::SCHEDULE_ID;
        $schedule->accountId = static::ACCOUNT_ID;
        if ($withAgentSpace) {
            $schedule->agentSpaceId = static::AGENT_SPACE_ID;
        }
        $schedule->account = Account::newInstance();
        $schedule->account->id = static::ACCOUNT_ID;
        $schedule->task = 'Collect the open support requests and summarise each with a proposed next step.';
        $schedule->reportChannel = AIConversationSchedule::REPORT_CHANNEL_EMAIL;
        if ($oneTime) {
            $schedule->oneTimeAt = new DateTime('2026-10-01 09:00:00');
        } else {
            $schedule->schedule = '0 7 * * 1';
        }
        return $schedule;
    }
}
