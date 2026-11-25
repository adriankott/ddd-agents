<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationStateReason;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Presentation\Mcp\AI\TaskScheduleRecordingWritesDouble;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the state sweep does with one schedule ({@see AIConversationSchedulesService::applyAutomationStateSweepToSchedule()}):
 * it reads the eligibility of the scope fresh, writes the status together with the reason in one targeted write,
 * re-arms the anchor from now when the scope is eligible again, applies a pause or a close only when the application's
 * eligibility service refuses automation under its operation name (the answer that decides the fire and the resume),
 * and on a close for an ended scope cancels the runs of the schedule. A dry run asks the application nothing.
 *
 * Which status and reason a schedule gets for which state is the rule object's table
 * ({@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\AIConversationScheduleTransitionTest}); the printing of the
 * sweep is in the test of its command.
 */
class AIConversationSchedulesServiceStateSweepTest extends IntegrationTestCase
{
    protected const int SCHEDULE_ID = 4430;

    protected const int ACCOUNT_ID = 34;

    protected const int AGENT_SPACE_ID = 7;

    protected const string ZONE = 'Europe/Berlin';

    protected const string KIND_RECURRING = 'a recurring schedule';

    protected const string KIND_ONE_TIME_AHEAD = 'a one-time task whose moment lies ahead';

    protected const string KIND_ONE_TIME_PASSED = 'a one-time task whose moment has passed';

    protected AutomationEligibilityServiceRecordingQuestionsDouble $eligibilityService;

    protected OwnerReportDeliveryServiceRecordingCancellationsDouble $ownerReportDeliveryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eligibilityService = new AutomationEligibilityServiceRecordingQuestionsDouble();
        $this->ownerReportDeliveryService = new OwnerReportDeliveryServiceRecordingCancellationsDouble();
        $this->replaceService(AgentAutomationEligibilityService::class, $this->eligibilityService);
        $this->replaceService(AIConversationOwnerReportDeliveryService::class, $this->ownerReportDeliveryService);
    }

    // ------------------------------------------------------------------ a pause and a close

    /**
     * @param string|null $signalSource the source that decided the state; null: the account
     * @param string|null $stateReasonBefore the reason the schedule carries; null: the owner or nobody paused it
     * @param string[] $expectedCancellations
     */
    #[DataProvider('provideSchedulesThePlatformPausesOrCloses')]
    public function testAPauseOrACloseWritesTheStatusWithItsReasonAndAsksTheApplication(
        string $status,
        ?string $stateReasonBefore,
        string $state,
        ?string $signalSource,
        string $expectedStatus,
        string $expectedReason,
        array $expectedCancellations
    ): void {
        $this->eligibilityService->fixtureState = $state;
        $this->eligibilityService->fixtureSignalSource = $signalSource;
        $schedule = static::buildSchedule(self::KIND_RECURRING, $status, $stateReasonBefore);
        $anchorBefore = $schedule->nextRunAt;

        $transition = (new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule);

        self::assertSame($expectedStatus, $transition?->targetStatus);
        self::assertSame($expectedReason, $transition->stateReason);
        self::assertSame(['status,stateReason'], $schedule->recordedWrites, 'the status and its reason in one write');
        self::assertSame($expectedStatus, $schedule->status);
        self::assertSame($expectedReason, $schedule->stateReason);
        self::assertSame($anchorBefore, $schedule->nextRunAt);
        self::assertSame(['account 34, read fresh'], $this->eligibilityService->recordedReads);
        self::assertSame(
            ['scheduleStateSweep:' . $expectedStatus . ' of schedule 4430 on a scope that is ' . $state],
            $this->eligibilityService->recordedQuestions
        );
        self::assertSame($expectedCancellations, $this->ownerReportDeliveryService->recordedCancellations);
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string, 3: string|null, 4: string, 5: string, 6: string[]}>
     */
    public static function provideSchedulesThePlatformPausesOrCloses(): array
    {
        $active = AIConversationScheduleStatus::ACTIVE;
        $paused = AIConversationScheduleStatus::PAUSED;
        $cancelled = AIConversationScheduleStatus::CANCELLED;
        $suspended = AgentAutomationEligibility::STATE_SUSPENDED;
        $ended = AgentAutomationEligibility::STATE_ENDED;
        $erased = AgentAutomationEligibility::STATE_ERASED;
        $theSpace = AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE;
        return [
            'active, the account suspended: paused' => [$active, null, $suspended, null, $paused, AgentAutomationStateReason::ACCOUNT_SUSPENDED, []],
            'active, the space suspended: paused' => [$active, null, $suspended, $theSpace, $paused, AgentAutomationStateReason::AGENT_SPACE_SUSPENDED, []],
            'active, the account ended: cancelled and its runs cancelled' => [
                $active, null, $ended, null, $cancelled, AgentAutomationStateReason::ACCOUNT_ENDED, ['schedule 4430 for ACCOUNT_ENDED'],
            ],
            'active, the space ended: cancelled and its runs cancelled' => [
                $active, null, $ended, $theSpace, $cancelled, AgentAutomationStateReason::AGENT_SPACE_ENDED, ['schedule 4430 for AGENT_SPACE_ENDED'],
            ],
            'active, the account erased: cancelled and its runs cancelled' => [
                $active, null, $erased, null, $cancelled, AgentAutomationStateReason::ACCOUNT_ERASED, ['schedule 4430 for ACCOUNT_ERASED'],
            ],
            'paused by the owner, the account ended: cancelled' => [
                $paused, null, $ended, null, $cancelled, AgentAutomationStateReason::ACCOUNT_ENDED, ['schedule 4430 for ACCOUNT_ENDED'],
            ],
            'paused by the platform, the account ended: cancelled with the new reason' => [
                $paused, AgentAutomationStateReason::ACCOUNT_SUSPENDED, $ended, null, $cancelled, AgentAutomationStateReason::ACCOUNT_ENDED, ['schedule 4430 for ACCOUNT_ENDED'],
            ],
        ];
    }

    /**
     * @param string|null $stateReasonBefore
     */
    #[DataProvider('provideSchedulesThePlatformWouldPauseOrClose')]
    public function testAPauseOrACloseTheApplicationDoesNotRefuseLeavesTheScheduleAsItIs(string $status, ?string $stateReasonBefore, string $state, string $expectedQuestion): void
    {
        $this->eligibilityService->fixtureState = $state;
        $this->eligibilityService->fixtureRefuses = false;
        $schedule = static::buildSchedule(self::KIND_RECURRING, $status, $stateReasonBefore);

        self::assertNull((new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule));

        self::assertSame([], $schedule->recordedWrites, 'the application lets the scope run: no pause, no close');
        self::assertSame($status, $schedule->status);
        self::assertSame($stateReasonBefore, $schedule->stateReason ?? null);
        self::assertSame([$expectedQuestion], $this->eligibilityService->recordedQuestions);
        self::assertSame([], $this->ownerReportDeliveryService->recordedCancellations, 'no run is cancelled');
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string, 3: string}>
     */
    public static function provideSchedulesThePlatformWouldPauseOrClose(): array
    {
        $active = AIConversationScheduleStatus::ACTIVE;
        $paused = AIConversationScheduleStatus::PAUSED;
        $suspended = AgentAutomationEligibility::STATE_SUSPENDED;
        $ended = AgentAutomationEligibility::STATE_ENDED;
        return [
            'active, the account suspended' => [$active, null, $suspended, 'scheduleStateSweep:PAUSED of schedule 4430 on a scope that is SUSPENDED'],
            'active, the account ended' => [$active, null, $ended, 'scheduleStateSweep:CANCELLED of schedule 4430 on a scope that is ENDED'],
            'paused by the owner, the account erased' => [
                $paused, null, AgentAutomationEligibility::STATE_ERASED, 'scheduleStateSweep:CANCELLED of schedule 4430 on a scope that is ERASED',
            ],
            'paused by the platform, the account ended' => [
                $paused, AgentAutomationStateReason::ACCOUNT_SUSPENDED, $ended, 'scheduleStateSweep:CANCELLED of schedule 4430 on a scope that is ENDED',
            ],
        ];
    }

    // ------------------------------------------------------------------ the re-arming

    public function testAScheduleThePlatformPausedIsReArmedFromNowWhenTheScopeIsEligibleAgain(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING, AIConversationScheduleStatus::PAUSED, AgentAutomationStateReason::ACCOUNT_SUSPENDED);
        $schedule->nextRunAt = new DateTime('2020-01-06 06:00:00');

        $transition = (new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule);

        self::assertSame(AIConversationScheduleStatus::ACTIVE, $transition?->targetStatus);
        self::assertSame(['status,stateReason,nextRunAt'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        self::assertNull($schedule->stateReason, 'the reason is cleared');
        $nextFiringInTheZone = (new \DateTimeImmutable('@' . $schedule->nextRunAt->getTimestamp()))->setTimezone(new \DateTimeZone(static::ZONE));
        self::assertGreaterThan(time(), $schedule->nextRunAt->getTimestamp(), 'the time of the suspension fires no backlog');
        self::assertSame('Monday 07:00', $nextFiringInTheZone->format('l H:i'));
        self::assertSame([], $this->eligibilityService->recordedQuestions, 'a re-arming is no refusal: the application is not asked');
        self::assertSame([], $this->ownerReportDeliveryService->recordedCancellations);
    }

    public function testAReArmedOneTimeTaskIsAnchoredOnItsMoment(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME_AHEAD, AIConversationScheduleStatus::PAUSED, AgentAutomationStateReason::ACCOUNT_SUSPENDED);
        $schedule->nextRunAt = new DateTime('2020-01-06 06:00:00');

        (new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule);

        self::assertSame(['status,stateReason,nextRunAt'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        self::assertSame($schedule->oneTimeAt->getTimestamp(), $schedule->nextRunAt->getTimestamp());
    }

    public function testAPlatformPausedOneTimeTaskWhoseMomentPassedIsClosedAsExpiredWithoutAReason(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME_PASSED, AIConversationScheduleStatus::PAUSED, AgentAutomationStateReason::ACCOUNT_SUSPENDED);
        $anchorBefore = $schedule->nextRunAt;

        $transition = (new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule);

        self::assertSame(AIConversationScheduleStatus::EXPIRED, $transition?->targetStatus);
        self::assertSame(['status,stateReason'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::EXPIRED, $schedule->status);
        self::assertNull($schedule->stateReason);
        self::assertSame($anchorBefore, $schedule->nextRunAt);
        self::assertSame([], $this->ownerReportDeliveryService->recordedCancellations, 'a close without a reason cancels no run');
    }

    // ------------------------------------------------------------------ a schedule the sweep leaves as it is

    /**
     * @param string|null $stateReasonBefore
     */
    #[DataProvider('provideSchedulesTheSweepLeaves')]
    public function testAScheduleTheSweepLeavesIsNotWrittenAndTheApplicationIsNotAsked(string $status, ?string $stateReasonBefore, string $state): void
    {
        $this->eligibilityService->fixtureState = $state;
        $schedule = static::buildSchedule(self::KIND_RECURRING, $status, $stateReasonBefore);

        self::assertNull((new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule));

        self::assertSame([], $schedule->recordedWrites);
        self::assertSame($status, $schedule->status);
        self::assertSame(['account 34, read fresh'], $this->eligibilityService->recordedReads);
        self::assertSame([], $this->eligibilityService->recordedQuestions);
        self::assertSame([], $this->ownerReportDeliveryService->recordedCancellations);
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function provideSchedulesTheSweepLeaves(): array
    {
        $suspended = AgentAutomationEligibility::STATE_SUSPENDED;
        $ended = AgentAutomationEligibility::STATE_ENDED;
        $eligible = AgentAutomationEligibility::STATE_ELIGIBLE;
        return [
            'active on an eligible scope' => [AIConversationScheduleStatus::ACTIVE, null, $eligible],
            'paused by the owner on an eligible scope' => [AIConversationScheduleStatus::PAUSED, null, $eligible],
            'paused by the owner on a suspended scope' => [AIConversationScheduleStatus::PAUSED, null, $suspended],
            'paused by the platform while the scope stays suspended' => [AIConversationScheduleStatus::PAUSED, AgentAutomationStateReason::ACCOUNT_SUSPENDED, $suspended],
            'cancelled on an ended scope' => [AIConversationScheduleStatus::CANCELLED, AgentAutomationStateReason::ACCOUNT_ENDED, $ended],
            'done on an ended scope' => [AIConversationScheduleStatus::DONE, null, $ended],
            'expired on an ended scope' => [AIConversationScheduleStatus::EXPIRED, null, $ended],
            'archived on an ended scope' => [AIConversationScheduleStatus::ARCHIVED, null, $ended],
        ];
    }

    public function testAScheduleThatIsNotStoredIsNotEvenRead(): void
    {
        $this->eligibilityService->fixtureState = AgentAutomationEligibility::STATE_ENDED;
        $schedule = new TaskScheduleRecordingWritesDouble();
        $schedule->accountId = static::ACCOUNT_ID;
        $schedule->schedule = '0 7 * * 1';

        self::assertNull((new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule));

        self::assertSame([], $this->eligibilityService->recordedReads);
        self::assertSame([], $schedule->recordedWrites);
    }

    public function testThePlatformsTransitionOfAScheduleThatIsNotStoredChangesNothing(): void
    {
        $schedule = new TaskScheduleRecordingWritesDouble();

        (new AIConversationSchedulesService())->transitionStatusForAutomationState(
            $schedule,
            AIConversationScheduleStatus::PAUSED,
            AgentAutomationStateReason::ACCOUNT_SUSPENDED
        );

        self::assertSame([], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
    }

    // ------------------------------------------------------------------ the scope and the dry run

    public function testAScheduleOfASpaceIsSweptOnTheEligibilityOfItsSpace(): void
    {
        $this->eligibilityService->fixtureState = AgentAutomationEligibility::STATE_SUSPENDED;
        $this->eligibilityService->fixtureSignalSource = AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE;
        $schedule = static::buildSchedule(self::KIND_RECURRING, AIConversationScheduleStatus::ACTIVE, null);
        $agentSpace = new AgentSpace();
        $agentSpace->id = static::AGENT_SPACE_ID;
        $schedule->agentSpaceId = static::AGENT_SPACE_ID;
        $schedule->agentSpace = $agentSpace;

        (new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule);

        self::assertSame(['space 7, read fresh'], $this->eligibilityService->recordedReads);
        self::assertSame(AgentAutomationStateReason::AGENT_SPACE_SUSPENDED, $schedule->stateReason);
    }

    public function testADryRunAnswersTheTransitionWritesNothingAndAsksTheApplicationNothing(): void
    {
        $this->eligibilityService->fixtureState = AgentAutomationEligibility::STATE_ENDED;
        $schedule = static::buildSchedule(self::KIND_RECURRING, AIConversationScheduleStatus::ACTIVE, null);

        $transition = (new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule, dryRun: true);

        self::assertSame(AIConversationScheduleStatus::CANCELLED, $transition?->targetStatus);
        self::assertSame(AgentAutomationStateReason::ACCOUNT_ENDED, $transition->stateReason);
        self::assertSame([], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        self::assertSame([], $this->ownerReportDeliveryService->recordedCancellations, 'no run is cancelled');
        self::assertTrue($transition->appliesOnlyWhenTheApplicationRefuses, 'the close would be applied only on a refusal');
        self::assertSame([], $this->eligibilityService->recordedQuestions, 'a dry run asks the application nothing: it logs no refusal for a row left alone');
    }

    public function testADryRunOfAReArmingNeedsNoRefusal(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING, AIConversationScheduleStatus::PAUSED, AgentAutomationStateReason::ACCOUNT_SUSPENDED);

        $transition = (new AIConversationSchedulesService())->applyAutomationStateSweepToSchedule($schedule, dryRun: true);

        self::assertSame(AIConversationScheduleStatus::ACTIVE, $transition?->targetStatus);
        self::assertFalse($transition->appliesOnlyWhenTheApplicationRefuses);
        self::assertSame([], $schedule->recordedWrites);
        self::assertSame([], $this->eligibilityService->recordedQuestions);
    }

    // ------------------------------------------------------------------ a status the owner or the platform writes without a reason

    /**
     * A status written by {@see AIConversationSchedulesService::transitionStatus()} is no platform state: a reason left in
     * the row is cleared in the same write, so the row is never told "closed by the platform" afterwards.
     *
     * @param string[] $expectedWrites
     */
    #[DataProvider('provideStatusWritesOverAPlatformReason')]
    public function testAStatusWrittenWithoutAReasonClearsAReasonLeftInTheRow(string $statusBefore, string $writtenStatus, array $expectedWrites): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING, $statusBefore, AgentAutomationStateReason::ACCOUNT_SUSPENDED);

        (new AIConversationSchedulesService())->transitionStatus($schedule, $writtenStatus);

        self::assertSame($expectedWrites, $schedule->recordedWrites);
        self::assertSame($writtenStatus, $schedule->status);
        self::assertNull($schedule->stateReason);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string[]}>
     */
    public static function provideStatusWritesOverAPlatformReason(): array
    {
        $paused = AIConversationScheduleStatus::PAUSED;
        return [
            'the owner cancels a schedule the platform paused' => [$paused, AIConversationScheduleStatus::CANCELLED, ['status,stateReason']],
            'the owner archives a schedule the platform paused' => [$paused, AIConversationScheduleStatus::ARCHIVED, ['status,stateReason']],
            'an active row with a leftover reason expires' => [AIConversationScheduleStatus::ACTIVE, AIConversationScheduleStatus::EXPIRED, ['status,stateReason']],
            'a row with a leftover reason becomes active' => [$paused, AIConversationScheduleStatus::ACTIVE, ['status,nextRunAt,stateReason']],
        ];
    }

    public function testAStatusWrittenOnARowWithoutAReasonWritesTheStatusAlone(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING, AIConversationScheduleStatus::PAUSED, null);

        (new AIConversationSchedulesService())->transitionStatus($schedule, AIConversationScheduleStatus::CANCELLED);

        self::assertSame(['status'], $schedule->recordedWrites);
    }

    // ------------------------------------------------------------------ the stage

    /**
     * A stored schedule of the account, without a space, in the zone {@see ZONE}.
     *
     * @param string|null $stateReason the reason the platform transitioned it with; null: none
     */
    protected static function buildSchedule(string $kind, string $status, ?string $stateReason): TaskScheduleRecordingWritesDouble
    {
        $schedule = new TaskScheduleRecordingWritesDouble();
        $schedule->id = static::SCHEDULE_ID;
        $schedule->accountId = static::ACCOUNT_ID;
        $schedule->status = $status;
        $schedule->timezone = static::ZONE;
        if ($kind === self::KIND_RECURRING) {
            $schedule->schedule = '0 7 * * 1';
            $schedule->nextRunAt = new DateTime('2099-07-06 05:00:00');
        } else {
            $moment = $kind === self::KIND_ONE_TIME_AHEAD ? '2099-07-01 10:00:00' : '2020-07-01 10:00:00';
            $schedule->maxIterations = 1;
            $schedule->oneTimeAt = new DateTime($moment);
            $schedule->nextRunAt = new DateTime($moment);
        }
        if ($stateReason !== null) {
            $schedule->stateReason = $stateReason;
        }
        return $schedule;
    }
}
