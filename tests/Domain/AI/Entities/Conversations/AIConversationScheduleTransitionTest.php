<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationStateReason;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleTransition;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * The legality table of every schedule operation ({@see AIConversationScheduleTransition::decide()}): what each
 * operation answers on a schedule in each of the six statuses, per kind of schedule, and the whole answer — allowed
 * or the refusal reason, the status it moves to, the delete action, the state reason, the re-arming of the fire
 * anchor, the seconds until a manual fire.
 *
 * A cell is written as the test describes a transition ({@see describeTransition()}): `allowed`, `allowed → PAUSED`,
 * `allowed → ARCHIVED (ARCHIVE)`, `allowed → ACTIVE, fire anchor re-armed`, `refused: STATUS`,
 * `refused: MANUAL_FIRE_INTERVAL (3000 s)`.
 */
class AIConversationScheduleTransitionTest extends UnitTestCase
{
    protected const string NOW = '2030-01-01 12:00:00';

    /** @var string[] The six statuses, in the order of the cells of a table row */
    protected const array STATUSES = [
        AIConversationScheduleStatus::ACTIVE,
        AIConversationScheduleStatus::PAUSED,
        AIConversationScheduleStatus::EXPIRED,
        AIConversationScheduleStatus::DONE,
        AIConversationScheduleStatus::CANCELLED,
        AIConversationScheduleStatus::ARCHIVED,
    ];

    /** A recurring schedule that never ran. */
    protected const string KIND_RECURRING = 'recurring';

    /** A recurring schedule with one firing used. */
    protected const string KIND_RECURRING_RAN = 'recurringRan';

    /** A recurring schedule whose ttl passed. */
    protected const string KIND_RECURRING_EXPIRED = 'recurringExpired';

    /** A recurring schedule whose runs are used up (three of three). */
    protected const string KIND_RECURRING_RUNS_USED_UP = 'recurringRunsUsedUp';

    /** A recurring schedule fired ten minutes ago. */
    protected const string KIND_RECURRING_RECENTLY_FIRED = 'recurringRecentlyFired';

    /** A recurring schedule with done criteria. */
    protected const string KIND_RECURRING_WITH_DONE_CRITERIA = 'recurringWithDoneCriteria';

    /** A recurring schedule the platform transitioned (a state reason is set). */
    protected const string KIND_RECURRING_CLOSED_BY_PLATFORM_STATE = 'recurringClosedByPlatformState';

    /** A one-time schedule whose moment lies a day ahead; it never ran. */
    protected const string KIND_ONE_TIME = 'oneTime';

    /** A one-time schedule whose moment passed a day ago; it never ran. */
    protected const string KIND_ONE_TIME_PAST = 'oneTimePast';

    /** A one-time schedule whose moment is this very second; it never ran. */
    protected const string KIND_ONE_TIME_NOW = 'oneTimeNow';

    /** A one-time schedule that ran (its single run is used). */
    protected const string KIND_ONE_TIME_RAN = 'oneTimeRan';

    /** A one-time schedule with a passed moment the platform transitioned. */
    protected const string KIND_ONE_TIME_PAST_CLOSED_BY_PLATFORM_STATE = 'oneTimePastClosedByPlatformState';

    protected const string ALLOWED = 'allowed';

    protected const string ALLOWED_RE_ARMED = 'allowed, fire anchor re-armed';

    protected const string TO_PAUSED = 'allowed → ' . AIConversationScheduleStatus::PAUSED;

    protected const string TO_ACTIVE_RE_ARMED = 'allowed → ' . AIConversationScheduleStatus::ACTIVE . ', fire anchor re-armed';

    protected const string TO_CANCELLED = 'allowed → ' . AIConversationScheduleStatus::CANCELLED;

    protected const string TO_DONE = 'allowed → ' . AIConversationScheduleStatus::DONE;

    protected const string TO_EXPIRED = 'allowed → ' . AIConversationScheduleStatus::EXPIRED;

    protected const string HARD_DELETE = 'allowed (' . AIConversationSchedule::DELETE_ACTION_HARD_DELETE . ')';

    protected const string KEEP_HISTORY = 'allowed (' . AIConversationSchedule::DELETE_ACTION_KEEP_HISTORY . ')';

    protected const string ARCHIVE = 'allowed → ' . AIConversationScheduleStatus::ARCHIVED . ' (' . AIConversationSchedule::DELETE_ACTION_ARCHIVE . ')';

    /** Refused for the status of the schedule. */
    protected const string NO = 'refused: ' . AIConversationScheduleTransition::REFUSAL_STATUS;

    protected const string NO_PLATFORM = 'refused: ' . AIConversationScheduleTransition::REFUSAL_CLOSED_BY_PLATFORM_STATE;

    protected const string NO_MOMENT_PASSED = 'refused: ' . AIConversationScheduleTransition::REFUSAL_ONE_TIME_MOMENT_PASSED;

    protected const string NO_CRON = 'refused: ' . AIConversationScheduleTransition::REFUSAL_ONE_TIME_HAS_NO_CRON;

    protected const string NO_BARE_ZONE = 'refused: ' . AIConversationScheduleTransition::REFUSAL_ONE_TIME_ZONE_NEEDS_NEW_MOMENT;

    protected const string NO_MOMENT_ON_RECURRING = 'refused: ' . AIConversationScheduleTransition::REFUSAL_RECURRING_HAS_NO_ONE_TIME_MOMENT;

    protected const string NO_ALREADY_RAN = 'refused: ' . AIConversationScheduleTransition::REFUSAL_ONE_TIME_ALREADY_RAN;

    protected const string NO_ONE_TIME_BY_HAND = 'refused: ' . AIConversationScheduleTransition::REFUSAL_ONE_TIME_FIRES_AT_ITS_MOMENT;

    protected const string NO_EXPIRED = 'refused: ' . AIConversationScheduleTransition::REFUSAL_EXPIRED;

    protected const string NO_FIRED_TEN_MINUTES_AGO = 'refused: ' . AIConversationScheduleTransition::REFUSAL_MANUAL_FIRE_INTERVAL . ' (3000 s)';

    protected const string NO_NOT_ONE_TIME = 'refused: ' . AIConversationScheduleTransition::REFUSAL_NOT_ONE_TIME;

    protected const string NO_NOT_DUE = 'refused: ' . AIConversationScheduleTransition::REFUSAL_ONE_TIME_MOMENT_NOT_DUE;

    protected const string NO_CRITERIA = 'refused: ' . AIConversationScheduleTransition::REFUSAL_NO_DONE_CRITERIA;

    protected const string NO_NOT_EXPIRED = 'refused: ' . AIConversationScheduleTransition::REFUSAL_NOT_EXPIRED;

    protected const string LEFT = 'refused: ' . AIConversationScheduleTransition::REFUSAL_STATE_SWEEP_LEAVES_ROW;

    /**
     * operation → kind of schedule → the answer per status, in the order of {@see STATUSES}
     * (ACTIVE, PAUSED, EXPIRED, DONE, CANCELLED, ARCHIVED).
     *
     * @var array<string, array<string, string[]>>
     */
    protected const array EXPECTED_BY_OPERATION_AND_KIND = [
        // only an ACTIVE schedule pauses, a one-time one that has not run yet included
        AIConversationScheduleTransition::OPERATION_PAUSE => [
            self::KIND_RECURRING => [self::TO_PAUSED, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME => [self::TO_PAUSED, self::NO, self::NO, self::NO, self::NO, self::NO],
        ],
        // only a PAUSED schedule the owner paused resumes; a one-time one only while its moment lies ahead; one whose
        // lifetime is over is closed by the resume; a schedule the platform paused or cancelled names that state, any
        // other status with a reason left in the row is refused for its status
        AIConversationScheduleTransition::OPERATION_RESUME => [
            self::KIND_RECURRING => [self::NO, self::TO_ACTIVE_RE_ARMED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING_EXPIRED => [self::NO, self::TO_EXPIRED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING_RUNS_USED_UP => [self::NO, self::TO_EXPIRED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME => [self::NO, self::TO_ACTIVE_RE_ARMED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME_PAST => [self::NO, self::NO_MOMENT_PASSED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME_NOW => [self::NO, self::NO_MOMENT_PASSED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING_CLOSED_BY_PLATFORM_STATE => [self::NO, self::NO_PLATFORM, self::NO, self::NO, self::NO_PLATFORM, self::NO],
            self::KIND_ONE_TIME_PAST_CLOSED_BY_PLATFORM_STATE => [self::NO, self::NO_PLATFORM, self::NO, self::NO, self::NO_PLATFORM, self::NO],
        ],
        // a live schedule cancels; a final status stays
        AIConversationScheduleTransition::OPERATION_CANCEL => [
            self::KIND_RECURRING => [self::TO_CANCELLED, self::TO_CANCELLED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME => [self::TO_CANCELLED, self::TO_CANCELLED, self::NO, self::NO, self::NO, self::NO],
        ],
        // the archive policy has no status rule: never ran → gone; a recurring one that ran → archived; a one-time
        // one that ran stays as history
        AIConversationScheduleTransition::OPERATION_DELETE => [
            self::KIND_RECURRING => [self::HARD_DELETE, self::HARD_DELETE, self::HARD_DELETE, self::HARD_DELETE, self::HARD_DELETE, self::HARD_DELETE],
            self::KIND_ONE_TIME => [self::HARD_DELETE, self::HARD_DELETE, self::HARD_DELETE, self::HARD_DELETE, self::HARD_DELETE, self::HARD_DELETE],
            self::KIND_RECURRING_RAN => [self::ARCHIVE, self::ARCHIVE, self::ARCHIVE, self::ARCHIVE, self::ARCHIVE, self::ARCHIVE],
            self::KIND_ONE_TIME_RAN => [self::KEEP_HISTORY, self::KEEP_HISTORY, self::KEEP_HISTORY, self::KEEP_HISTORY, self::KEEP_HISTORY, self::KEEP_HISTORY],
        ],
        // a live schedule is edited; an ACTIVE one re-arms its fire anchor
        AIConversationScheduleTransition::OPERATION_EDIT => [
            self::KIND_RECURRING => [self::ALLOWED_RE_ARMED, self::ALLOWED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME => [self::ALLOWED_RE_ARMED, self::ALLOWED, self::NO, self::NO, self::NO, self::NO],
        ],
        // a cron belongs to a live recurring schedule; the status is told before the kind
        AIConversationScheduleTransition::OPERATION_EDIT_CRON => [
            self::KIND_RECURRING => [self::ALLOWED_RE_ARMED, self::ALLOWED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME => [self::NO_CRON, self::NO_CRON, self::NO, self::NO, self::NO, self::NO],
        ],
        // the zone of a one-time schedule moves only together with a new moment
        AIConversationScheduleTransition::OPERATION_EDIT_TIMEZONE => [
            self::KIND_RECURRING => [self::ALLOWED_RE_ARMED, self::ALLOWED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME => [self::NO_BARE_ZONE, self::NO_BARE_ZONE, self::NO, self::NO, self::NO, self::NO],
        ],
        AIConversationScheduleTransition::OPERATION_EDIT_TIMEZONE_WITH_NEW_MOMENT => [
            self::KIND_RECURRING => [self::ALLOWED_RE_ARMED, self::ALLOWED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME => [self::ALLOWED_RE_ARMED, self::ALLOWED, self::NO, self::NO, self::NO, self::NO],
        ],
        // a new moment belongs to a live one-time schedule that has not run yet
        AIConversationScheduleTransition::OPERATION_EDIT_ONE_TIME_MOMENT => [
            self::KIND_ONE_TIME => [self::ALLOWED_RE_ARMED, self::ALLOWED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME_PAST => [self::ALLOWED_RE_ARMED, self::ALLOWED, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING => [self::NO_MOMENT_ON_RECURRING, self::NO_MOMENT_ON_RECURRING, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME_RAN => [self::NO_ALREADY_RAN, self::NO_ALREADY_RAN, self::NO, self::NO, self::NO, self::NO],
        ],
        // a person fires an ACTIVE recurring schedule now; a one-time one never, whatever its status
        AIConversationScheduleTransition::OPERATION_RUN_NOW => [
            self::KIND_RECURRING => [self::ALLOWED, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME => [self::NO_ONE_TIME_BY_HAND, self::NO_ONE_TIME_BY_HAND, self::NO_ONE_TIME_BY_HAND, self::NO_ONE_TIME_BY_HAND, self::NO_ONE_TIME_BY_HAND, self::NO_ONE_TIME_BY_HAND],
            self::KIND_RECURRING_EXPIRED => [self::NO_EXPIRED, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING_RECENTLY_FIRED => [self::NO_FIRED_TEN_MINUTES_AGO, self::NO, self::NO, self::NO, self::NO, self::NO],
        ],
        // the first firing of a schedule created to run at once: ACTIVE, one-time, its moment due
        AIConversationScheduleTransition::OPERATION_START_NOW => [
            self::KIND_ONE_TIME_PAST => [self::ALLOWED, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME_NOW => [self::ALLOWED, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME => [self::NO_NOT_DUE, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING => [self::NO_NOT_ONE_TIME, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING_EXPIRED => [self::NO_EXPIRED, self::NO, self::NO, self::NO, self::NO, self::NO],
            // its single run is used (cap 1), so it is expired before "already fired" is asked; ALREADY_FIRED is
            // told to a one-time schedule without a cap (testAOneTimeScheduleWithoutACapThatFiredIsNotStartedAgain)
            self::KIND_ONE_TIME_RAN => [self::NO_EXPIRED, self::NO, self::NO, self::NO, self::NO, self::NO],
        ],
        // only an ACTIVE schedule expires; the status is told before the expiry, so a paused or final schedule past
        // its ttl or its runs never moves
        AIConversationScheduleTransition::OPERATION_EXPIRE => [
            self::KIND_RECURRING_EXPIRED => [self::TO_EXPIRED, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME_RAN => [self::TO_EXPIRED, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING => [self::NO_NOT_EXPIRED, self::NO, self::NO, self::NO, self::NO, self::NO],
        ],
        // done criteria close an ACTIVE schedule; without criteria that is told before the status
        AIConversationScheduleTransition::OPERATION_DONE_BY_CRITERIA => [
            self::KIND_RECURRING_WITH_DONE_CRITERIA => [self::TO_DONE, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING => [self::NO_CRITERIA, self::NO_CRITERIA, self::NO_CRITERIA, self::NO_CRITERIA, self::NO_CRITERIA, self::NO_CRITERIA],
        ],
        // the single run of an ACTIVE one-time schedule closes it; a paused one stays paused
        AIConversationScheduleTransition::OPERATION_DONE_AFTER_ONE_TIME_RUN => [
            self::KIND_ONE_TIME => [self::TO_DONE, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_ONE_TIME_RAN => [self::TO_DONE, self::NO, self::NO, self::NO, self::NO, self::NO],
            self::KIND_RECURRING => [self::NO_NOT_ONE_TIME, self::NO_NOT_ONE_TIME, self::NO_NOT_ONE_TIME, self::NO_NOT_ONE_TIME, self::NO_NOT_ONE_TIME, self::NO_NOT_ONE_TIME],
        ],
    ];

    #[DataProvider('provideCellsOfTheStatusTable')]
    public function testAnOperationOnAScheduleInAStatus(string $operation, string $kind, string $status, string $expectedTransition): void
    {
        $transition = static::buildSchedule($kind, $status)->buildTransitionTo($operation, new DateTime(static::NOW));

        self::assertSame($operation, $transition->operation);
        self::assertSame($expectedTransition, static::describeTransition($transition));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function provideCellsOfTheStatusTable(): array
    {
        $cells = [];
        foreach (static::EXPECTED_BY_OPERATION_AND_KIND as $operation => $expectedByKind) {
            foreach ($expectedByKind as $kind => $expectedByStatusIndex) {
                foreach (static::STATUSES as $statusIndex => $status) {
                    $cells["{$operation} on {$status}/{$kind}"] = [$operation, $kind, $status, $expectedByStatusIndex[$statusIndex]];
                }
            }
        }
        return $cells;
    }

    #[DataProvider('provideResumesWithTheEligibilityOfTheScope')]
    public function testAResumeGivenTheEligibilityOfTheScope(string $kind, string $status, string $stateOfTheScope, string $expectedTransition): void
    {
        $transition = static::buildSchedule($kind, $status)->buildTransitionTo(
            AIConversationScheduleTransition::OPERATION_RESUME,
            new DateTime(static::NOW),
            static::buildEligibility($stateOfTheScope, false)
        );

        self::assertSame($expectedTransition, static::describeTransition($transition));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function provideResumesWithTheEligibilityOfTheScope(): array
    {
        $refusedForTheScope = 'refused: ' . AIConversationScheduleTransition::REFUSAL_SCOPE_NOT_ELIGIBLE;
        return [
            'an eligible scope' => [self::KIND_RECURRING, AIConversationScheduleStatus::PAUSED, AgentAutomationEligibility::STATE_ELIGIBLE, self::TO_ACTIVE_RE_ARMED],
            'a suspended scope' => [self::KIND_RECURRING, AIConversationScheduleStatus::PAUSED, AgentAutomationEligibility::STATE_SUSPENDED, $refusedForTheScope],
            'a scope that ended' => [self::KIND_RECURRING, AIConversationScheduleStatus::PAUSED, AgentAutomationEligibility::STATE_ENDED, $refusedForTheScope],
            'an erased scope' => [self::KIND_RECURRING, AIConversationScheduleStatus::PAUSED, AgentAutomationEligibility::STATE_ERASED, $refusedForTheScope],
            'the status is told before the scope' => [self::KIND_RECURRING, AIConversationScheduleStatus::ACTIVE, AgentAutomationEligibility::STATE_SUSPENDED, self::NO],
            'the passed moment is told before the scope' => [self::KIND_ONE_TIME_PAST, AIConversationScheduleStatus::PAUSED, AgentAutomationEligibility::STATE_SUSPENDED, self::NO_MOMENT_PASSED],
            'the end of its lifetime is told before the scope' => [self::KIND_RECURRING_EXPIRED, AIConversationScheduleStatus::PAUSED, AgentAutomationEligibility::STATE_SUSPENDED, self::TO_EXPIRED],
            'the platform state is told on an eligible scope too' => [
                self::KIND_RECURRING_CLOSED_BY_PLATFORM_STATE,
                AIConversationScheduleStatus::PAUSED,
                AgentAutomationEligibility::STATE_ELIGIBLE,
                self::NO_PLATFORM,
            ],
        ];
    }

    #[DataProvider('provideManualFiresByTheSecondsSinceTheLastFiring')]
    public function testAManualFireKeepsAnHourToTheLastFiring(int $secondsSinceTheLastFiring, string $expectedTransition): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING, AIConversationScheduleStatus::ACTIVE);
        $schedule->lastFiredAt = DateTime::fromTimestamp((new DateTime(static::NOW))->getTimestamp() - $secondsSinceTheLastFiring);

        $transition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_RUN_NOW, new DateTime(static::NOW));

        self::assertSame($expectedTransition, static::describeTransition($transition));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function provideManualFiresByTheSecondsSinceTheLastFiring(): array
    {
        $refusedForTheInterval = 'refused: ' . AIConversationScheduleTransition::REFUSAL_MANUAL_FIRE_INTERVAL;
        return [
            'fired this second' => [0, $refusedForTheInterval . ' (3600 s)'],
            'one second short of the hour' => [3599, $refusedForTheInterval . ' (1 s)'],
            'the hour is over' => [AIConversationScheduleTransition::MANUAL_FIRE_MINIMUM_INTERVAL_SECONDS, self::ALLOWED],
        ];
    }

    public function testAnExpiredScheduleFiredAMinuteAgoIsToldItExpired(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_EXPIRED, AIConversationScheduleStatus::ACTIVE);
        $schedule->lastFiredAt = new DateTime('2030-01-01 11:59:00');

        $transition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_RUN_NOW, new DateTime(static::NOW));

        self::assertSame(self::NO_EXPIRED, static::describeTransition($transition));
    }

    public function testAOneTimeScheduleWithoutACapThatFiredIsNotStartedAgain(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME_PAST, AIConversationScheduleStatus::ACTIVE);
        $schedule->maxIterations = null;
        $schedule->iterationsUsed = 1;

        $transition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_START_NOW, new DateTime(static::NOW));

        self::assertSame('refused: ' . AIConversationScheduleTransition::REFUSAL_ALREADY_FIRED, static::describeTransition($transition));
    }

    public function testDoneCriteriaOfBlanksAreNone(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING, AIConversationScheduleStatus::ACTIVE);
        $schedule->doneCriteria = "  \n";

        $transition = $schedule->buildTransitionTo(AIConversationScheduleTransition::OPERATION_DONE_BY_CRITERIA, new DateTime(static::NOW));

        self::assertSame(self::NO_CRITERIA, static::describeTransition($transition));
    }

    #[DataProvider('provideActiveSchedulesByExpiry')]
    public function testAnActiveScheduleExpiresWhenItsTtlOrItsRunsAreOver(string $kind, string $expectedTransition): void
    {
        $transition = static::buildSchedule($kind, AIConversationScheduleStatus::ACTIVE)
            ->buildTransitionTo(AIConversationScheduleTransition::OPERATION_EXPIRE, new DateTime(static::NOW));

        self::assertSame($expectedTransition, static::describeTransition($transition));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideActiveSchedulesByExpiry(): array
    {
        $refusedAsNotExpired = 'refused: ' . AIConversationScheduleTransition::REFUSAL_NOT_EXPIRED;
        return [
            'its ttl passed' => [self::KIND_RECURRING_EXPIRED, self::TO_EXPIRED],
            'its single run is used' => [self::KIND_ONE_TIME_RAN, self::TO_EXPIRED],
            'a recurring schedule without a ttl and a cap' => [self::KIND_RECURRING, $refusedAsNotExpired],
            'a one-time schedule that is late and never ran' => [self::KIND_ONE_TIME_PAST, $refusedAsNotExpired],
        ];
    }

    /**
     * The state sweep: what the eligibility of the scope does to a schedule.
     *
     * @param string|null $stateOfTheScope null = the sweep was given no eligibility
     */
    #[DataProvider('provideStateSweeps')]
    public function testTheStateSweep(string $kind, string $status, ?string $stateOfTheScope, bool $decidedByTheAgentSpace, string $expectedTransition): void
    {
        $transition = static::buildSchedule($kind, $status)->buildTransitionTo(
            AIConversationScheduleTransition::OPERATION_STATE_SWEEP,
            new DateTime(static::NOW),
            $stateOfTheScope !== null ? static::buildEligibility($stateOfTheScope, $decidedByTheAgentSpace) : null
        );

        self::assertSame($expectedTransition, static::describeTransition($transition));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: bool, 4: string}>
     */
    public static function provideStateSweeps(): array
    {
        $active = AIConversationScheduleStatus::ACTIVE;
        $paused = AIConversationScheduleStatus::PAUSED;
        $eligible = AgentAutomationEligibility::STATE_ELIGIBLE;
        $suspended = AgentAutomationEligibility::STATE_SUSPENDED;
        $ended = AgentAutomationEligibility::STATE_ENDED;
        $erased = AgentAutomationEligibility::STATE_ERASED;
        $pausedForTheAccount = self::TO_PAUSED . ', reason ' . AgentAutomationStateReason::ACCOUNT_SUSPENDED;
        $pausedForTheSpace = self::TO_PAUSED . ', reason ' . AgentAutomationStateReason::AGENT_SPACE_SUSPENDED;
        $cancelledForTheAccount = self::TO_CANCELLED . ', reason ' . AgentAutomationStateReason::ACCOUNT_ENDED;
        $cancelledForTheSpace = self::TO_CANCELLED . ', reason ' . AgentAutomationStateReason::AGENT_SPACE_ENDED;
        $cancelledForTheErasedAccount = self::TO_CANCELLED . ', reason ' . AgentAutomationStateReason::ACCOUNT_ERASED;
        $platformPaused = self::KIND_RECURRING_CLOSED_BY_PLATFORM_STATE;
        $sweeps = [
            'no eligibility given' => [self::KIND_RECURRING, $active, null, false, self::LEFT],
            // an ACTIVE schedule
            'active, the scope eligible' => [self::KIND_RECURRING, $active, $eligible, false, self::LEFT],
            'active, the account suspended' => [self::KIND_RECURRING, $active, $suspended, false, $pausedForTheAccount],
            'active, the space suspended' => [self::KIND_RECURRING, $active, $suspended, true, $pausedForTheSpace],
            'active, the account ended' => [self::KIND_RECURRING, $active, $ended, false, $cancelledForTheAccount],
            'active, the space ended' => [self::KIND_RECURRING, $active, $ended, true, $cancelledForTheSpace],
            'active, the account erased' => [self::KIND_RECURRING, $active, $erased, false, $cancelledForTheErasedAccount],
            'active, the space erased' => [self::KIND_RECURRING, $active, $erased, true, $cancelledForTheSpace],
            'active one-time, the account suspended' => [self::KIND_ONE_TIME, $active, $suspended, false, $pausedForTheAccount],
            // a schedule the owner paused: the pause stands, no reason is stamped
            'paused by the owner, the scope eligible' => [self::KIND_RECURRING, $paused, $eligible, false, self::LEFT],
            'paused by the owner, the account suspended' => [self::KIND_RECURRING, $paused, $suspended, false, self::LEFT],
            'paused by the owner, the account ended' => [self::KIND_RECURRING, $paused, $ended, false, $cancelledForTheAccount],
            'paused by the owner, the space erased' => [self::KIND_RECURRING, $paused, $erased, true, $cancelledForTheSpace],
            'paused by the owner, one-time with a passed moment, the scope eligible' => [self::KIND_ONE_TIME_PAST, $paused, $eligible, false, self::LEFT],
            // a schedule the platform paused: re-armed only when the scope is eligible again
            'paused by the platform, the scope eligible again' => [$platformPaused, $paused, $eligible, false, self::TO_ACTIVE_RE_ARMED],
            'paused by the platform, the account still suspended' => [$platformPaused, $paused, $suspended, false, self::LEFT],
            'paused by the platform, the account ended' => [$platformPaused, $paused, $ended, false, $cancelledForTheAccount],
            'paused by the platform, one-time with a passed moment, the scope eligible again' => [
                self::KIND_ONE_TIME_PAST_CLOSED_BY_PLATFORM_STATE,
                $paused,
                $eligible,
                false,
                self::TO_EXPIRED,
            ],
            // an ACTIVE schedule that still carries a reason is not touched on an eligible scope
            'active with a reason left, the scope eligible' => [$platformPaused, $active, $eligible, false, self::LEFT],
        ];
        // a final status never moves, whatever the scope
        foreach ([AIConversationScheduleStatus::EXPIRED, AIConversationScheduleStatus::DONE, AIConversationScheduleStatus::CANCELLED, AIConversationScheduleStatus::ARCHIVED] as $finalStatus) {
            $sweeps["{$finalStatus}, the scope eligible again"] = [$platformPaused, $finalStatus, $eligible, false, self::LEFT];
            $sweeps["{$finalStatus}, the account ended"] = [self::KIND_RECURRING, $finalStatus, $ended, false, self::LEFT];
        }
        return $sweeps;
    }

    public function testAPlatformPausedOneTimeScheduleIsReArmedWhileItsMomentLiesAheadAndExpiresFromItsMomentOn(): void
    {
        $now = new DateTime(static::NOW);
        $eligibleAgain = static::buildEligibility(AgentAutomationEligibility::STATE_ELIGIBLE, false);
        $momentAhead = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::PAUSED);
        $momentAhead->stateReason = AgentAutomationStateReason::ACCOUNT_SUSPENDED;
        $momentNow = static::buildSchedule(self::KIND_ONE_TIME_NOW, AIConversationScheduleStatus::PAUSED);
        $momentNow->stateReason = AgentAutomationStateReason::ACCOUNT_SUSPENDED;

        self::assertSame(
            self::TO_ACTIVE_RE_ARMED,
            static::describeTransition($momentAhead->buildTransitionTo(AIConversationScheduleTransition::OPERATION_STATE_SWEEP, $now, $eligibleAgain))
        );
        self::assertSame(
            self::TO_EXPIRED,
            static::describeTransition($momentNow->buildTransitionTo(AIConversationScheduleTransition::OPERATION_STATE_SWEEP, $now, $eligibleAgain))
        );
    }

    public function testAnUnknownOperationIsRefusedWithItsName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown schedule operation 'ARCHIVE_ALL'.");

        static::buildSchedule(self::KIND_RECURRING, AIConversationScheduleStatus::ACTIVE)->buildTransitionTo('ARCHIVE_ALL', new DateTime(static::NOW));
    }

    /** A new operation or refusal reason needs its cells here. */
    public function testEveryOperationAndEveryRefusalReasonOfTheClassHasACase(): void
    {
        $operationsWithACase = array_keys(static::EXPECTED_BY_OPERATION_AND_KIND);
        $operationsWithACase[] = AIConversationScheduleTransition::OPERATION_STATE_SWEEP;
        $expectedTransitions = [
            'refused: ' . AIConversationScheduleTransition::REFUSAL_SCOPE_NOT_ELIGIBLE,
            'refused: ' . AIConversationScheduleTransition::REFUSAL_ALREADY_FIRED,
            self::LEFT,
        ];
        foreach (static::provideCellsOfTheStatusTable() as $cell) {
            $expectedTransitions[] = $cell[3];
        }
        $allExpectedTransitions = implode("\n", $expectedTransitions) . "\n";

        foreach ((new ReflectionClass(AIConversationScheduleTransition::class))->getConstants() as $constantName => $constantValue) {
            if (str_starts_with($constantName, 'OPERATION_')) {
                self::assertContains($constantValue, $operationsWithACase, "The operation {$constantName} has no case in this test.");
            }
            if (str_starts_with($constantName, 'REFUSAL_')) {
                self::assertMatchesRegularExpression(
                    '/^refused: ' . preg_quote((string)$constantValue, '/') . '( \(\d+ s\))?$/m',
                    $allExpectedTransitions,
                    "The refusal reason {$constantName} has no case in this test."
                );
            }
        }
    }

    /** A schedule of `$kind` in `$status`, as the clock of the test ({@see NOW}) sees it. */
    protected static function buildSchedule(string $kind, string $status): AIConversationSchedule
    {
        $schedule = new AIConversationSchedule();
        $schedule->status = $status;
        $isOneTime = in_array(
            $kind,
            [self::KIND_ONE_TIME, self::KIND_ONE_TIME_PAST, self::KIND_ONE_TIME_NOW, self::KIND_ONE_TIME_RAN, self::KIND_ONE_TIME_PAST_CLOSED_BY_PLATFORM_STATE],
            true
        );
        if ($isOneTime) {
            $schedule->maxIterations = 1;
            $schedule->oneTimeAt = new DateTime('2030-01-02 12:00:00');
        } else {
            $schedule->schedule = '0 7 * * 1';
        }
        if (in_array($kind, [self::KIND_ONE_TIME_PAST, self::KIND_ONE_TIME_RAN, self::KIND_ONE_TIME_PAST_CLOSED_BY_PLATFORM_STATE], true)) {
            $schedule->oneTimeAt = new DateTime('2029-12-31 12:00:00');
        }
        if ($kind === self::KIND_ONE_TIME_NOW) {
            $schedule->oneTimeAt = new DateTime(static::NOW);
        }
        if (in_array($kind, [self::KIND_RECURRING_RAN, self::KIND_ONE_TIME_RAN, self::KIND_RECURRING_RECENTLY_FIRED], true)) {
            $schedule->iterationsUsed = 1;
        }
        if ($kind === self::KIND_RECURRING_RUNS_USED_UP) {
            $schedule->maxIterations = 3;
            $schedule->iterationsUsed = 3;
        }
        if ($kind === self::KIND_RECURRING_EXPIRED) {
            $schedule->ttlAt = new DateTime('2020-01-01 00:00:00');
        }
        if ($kind === self::KIND_RECURRING_RECENTLY_FIRED) {
            $schedule->lastFiredAt = new DateTime('2030-01-01 11:50:00');
        }
        if ($kind === self::KIND_RECURRING_WITH_DONE_CRITERIA) {
            $schedule->doneCriteria = 'The review has an answer.';
        }
        if (in_array($kind, [self::KIND_RECURRING_CLOSED_BY_PLATFORM_STATE, self::KIND_ONE_TIME_PAST_CLOSED_BY_PLATFORM_STATE], true)) {
            $schedule->stateReason = AgentAutomationStateReason::ACCOUNT_SUSPENDED;
        }
        return $schedule;
    }

    /** The eligibility of a scope in `$state`, decided by a signal of the agent space or of the account. */
    protected static function buildEligibility(string $state, bool $decidedByTheAgentSpace): AgentAutomationEligibility
    {
        $eligibility = AgentAutomationEligibility::eligible();
        $eligibility->state = $state;
        $eligibility->signalSource = $decidedByTheAgentSpace ? AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE : null;
        return $eligibility;
    }

    /** The whole answer of a transition in one line: every field that is set shows. */
    protected static function describeTransition(AIConversationScheduleTransition $transition): string
    {
        $description = $transition->isAllowed ? 'allowed' : 'refused: ' . $transition->refusalReason;
        if ($transition->isAllowed && $transition->refusalReason !== null) {
            $description .= ' [refusal reason left: ' . $transition->refusalReason . ']';
        }
        if ($transition->targetStatus !== null) {
            $description .= ' → ' . $transition->targetStatus;
        }
        if ($transition->deleteAction !== null) {
            $description .= ' (' . $transition->deleteAction . ')';
        }
        if ($transition->stateReason !== null) {
            $description .= ', reason ' . $transition->stateReason;
        }
        if ($transition->reArmsFireAnchor) {
            $description .= ', fire anchor re-armed';
        }
        if ($transition->secondsUntilManualFireIsAllowed !== null) {
            $description .= ' (' . $transition->secondsUntilManualFireIsAllowed . ' s)';
        }
        return $description;
    }
}
