<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationStateReason;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleIdRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleUpdateRequestDto;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The four tools that change an existing task schedule — `pauseTaskSchedule`, `resumeTaskSchedule`,
 * `deleteTaskSchedule`, `updateTaskSchedule` — each called the way the loop calls it, on a schedule in a status: which
 * call is refused with which words, which columns of the schedule are written, and what the answer tells the model.
 *
 * Whether an operation is allowed on a schedule is the answer of the rule object, pinned cell by cell in
 * {@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\AIConversationScheduleTransitionTest}. Here is what a tool
 * makes of that answer. The schedule is one that records its writes instead of storing them; the service is the real
 * one apart from the lookup by id.
 *
 * A schedule stored without a zone and the lookup without the entity rights are in
 * {@see AgentTaskSchedulesMCPPackageZoneAndScopeTest}, the rules of a moment and of a cron as at creation in
 * {@see AgentTaskSchedulesMCPPackageCreationRulesTest}.
 */
class AgentTaskSchedulesMCPPackageTransitionToolsTest extends IntegrationTestCase
{
    protected const int ACTING_ACCOUNT_ID = 3;

    protected const int OTHER_ACCOUNT_ID = 4;

    protected const int AGENT_SPACE_ID = 17;

    protected const int SCHEDULE_ID = 4430;

    protected const int CONVERSATION_ID = 700300;

    /** @var int The schedule whose run a call comes from in the "inside a scheduled run" cases */
    protected const int FIRING_SCHEDULE_ID = 4242;

    protected const string ZONE = 'Europe/Berlin';

    /** @var string Mondays at 07:00 in the zone of the schedule */
    protected const string CRON = '0 7 * * 1';

    protected const string TASK = 'Collect the open support requests and summarise each with a proposed next step.';

    protected const string TITLE = 'Weekly support summary';

    /** @var string The fire anchor of the recurring fixture: Monday 2099-07-06 at 07:00 in Berlin */
    protected const string ANCHOR = '2099-07-06 05:00:00';

    protected const string ANCHOR_AS_THE_MODEL_READS_IT = '2099-07-06 07:00:00+02:00 (Monday)';

    /** @var string A fire anchor of years ago, as a long pause leaves it in the row */
    protected const string ANCHOR_OF_YEARS_AGO = '2020-01-06 06:00:00';

    /** @var string The moment of the one-time fixture: Wednesday 2099-07-01 at 12:00 in Berlin */
    protected const string ONE_TIME_MOMENT = '2099-07-01 10:00:00';

    protected const string ONE_TIME_MOMENT_AS_THE_MODEL_READS_IT = '2099-07-01 12:00:00+02:00 (Wednesday)';

    protected const string PASSED_ONE_TIME_MOMENT = '2020-07-01 10:00:00';

    protected const string KIND_RECURRING = 'a recurring schedule that never ran';

    protected const string KIND_RECURRING_RAN = 'a recurring schedule that ran';

    protected const string KIND_ONE_TIME = 'a one-time task whose moment lies ahead';

    protected const string KIND_ONE_TIME_PASSED = 'a one-time task whose moment has passed';

    protected const string KIND_ONE_TIME_RAN = 'a one-time task that ran';

    protected const array TOOL_NAMES = ['pauseTaskSchedule', 'resumeTaskSchedule', 'deleteTaskSchedule', 'updateTaskSchedule'];

    protected const array STATUSES = [
        AIConversationScheduleStatus::ACTIVE,
        AIConversationScheduleStatus::PAUSED,
        AIConversationScheduleStatus::EXPIRED,
        AIConversationScheduleStatus::DONE,
        AIConversationScheduleStatus::CANCELLED,
        AIConversationScheduleStatus::ARCHIVED,
    ];

    protected const array PAUSE_REFUSAL_BY_STATUS = [
        AIConversationScheduleStatus::PAUSED => 'Schedule 4430 cannot be paused in status PAUSED — only an ACTIVE schedule pauses (it is already paused; resumeTaskSchedule restarts it).',
        AIConversationScheduleStatus::EXPIRED => 'Schedule 4430 cannot be paused in status EXPIRED — only an ACTIVE schedule pauses.',
        AIConversationScheduleStatus::DONE => 'Schedule 4430 cannot be paused in status DONE — only an ACTIVE schedule pauses.',
        AIConversationScheduleStatus::CANCELLED => 'Schedule 4430 cannot be paused in status CANCELLED — only an ACTIVE schedule pauses.',
        AIConversationScheduleStatus::ARCHIVED => 'Schedule 4430 cannot be paused in status ARCHIVED — only an ACTIVE schedule pauses.',
    ];

    protected const array RESUME_REFUSAL_BY_STATUS = [
        AIConversationScheduleStatus::ACTIVE => 'Schedule 4430 cannot be resumed in status ACTIVE.',
        AIConversationScheduleStatus::EXPIRED => 'Schedule 4430 cannot be resumed in status EXPIRED.',
        AIConversationScheduleStatus::DONE => 'Schedule 4430 cannot be resumed in status DONE.',
        AIConversationScheduleStatus::CANCELLED => 'Schedule 4430 cannot be resumed in status CANCELLED.',
        AIConversationScheduleStatus::ARCHIVED => 'Schedule 4430 cannot be resumed in status ARCHIVED.',
    ];

    protected const array UPDATE_REFUSAL_BY_STATUS = [
        AIConversationScheduleStatus::EXPIRED => 'Schedule 4430 is EXPIRED — only an ACTIVE or PAUSED schedule can be changed. Create a new one with scheduleOneTimeTask or createRecurringTaskSchedule.',
        AIConversationScheduleStatus::DONE => 'Schedule 4430 is DONE — only an ACTIVE or PAUSED schedule can be changed. Create a new one with scheduleOneTimeTask or createRecurringTaskSchedule.',
        AIConversationScheduleStatus::CANCELLED => 'Schedule 4430 is CANCELLED — only an ACTIVE or PAUSED schedule can be changed. Create a new one with scheduleOneTimeTask or createRecurringTaskSchedule.',
        AIConversationScheduleStatus::ARCHIVED => 'Schedule 4430 is ARCHIVED — only an ACTIVE or PAUSED schedule can be changed. Create a new one with scheduleOneTimeTask or createRecurringTaskSchedule.',
    ];

    protected const string NOTE_UPDATED = 'Schedule updated IN PLACE (same id, run history preserved). ';

    protected const string NOTE_CONFIRM_THE_CHANGE = 'Confirm to the user in ONE short sentence what changed.';

    protected const string NOTE_PAUSED_RECURRING = 'It is PAUSED and does not fire until resumed (resumeTaskSchedule); once resumed, it fires at the next slot of its cron after the resume. ';

    protected const string NOTE_PAUSED_ONE_TIME_PREFIX = 'It is PAUSED and does not fire until resumed (resumeTaskSchedule); once resumed, it fires at ';

    protected const string NOTE_VERIFY_THE_MOMENT = 'VERIFY this matches what the user asked (wrong day → fix it with updateTaskSchedule). ';

    // ------------------------------------------------------------------ before the schedule is read

    #[DataProvider('provideToolNames')]
    public function testNoToolAdministersAScheduleFromInsideAScheduledRun(string $toolName): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE);
        $schedulesService = static::buildSchedulesService($schedule);
        try {
            static::buildPackage()->{$toolName}(static::buildRequestOfTool($toolName), $schedulesService, static::buildProvenanceOfAScheduledRun());
            self::fail("{$toolName} is refused inside a scheduled run");
        } catch (BadRequestException $badRequestException) {
            self::assertSame(
                "Tool '{$toolName}' is not available inside a SCHEDULED RUN: you are the execution of a task schedule #4242 — execute the mission;"
                . ' never create, update or delete schedules from within it. Time or recurrence wording in the mission text is scheduler'
                . ' metadata, already handled. If the mission literally asks for schedule changes, report that to the owner in your final'
                . ' answer instead.',
                $badRequestException->getMessage()
            );
        }
        self::assertNull($schedulesService->rightsAppliedOnFind, 'the schedule is not even read');
        self::assertSame([], $schedule->recordedWrites);
    }

    #[DataProvider('provideToolNames')]
    public function testAScheduleOfAnotherAccountIsAnsweredAsNotFound(string $toolName): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE);
        $schedule->accountId = static::OTHER_ACCOUNT_ID;

        $this->assertNotFoundWithoutAWrite($toolName, $schedule);
    }

    #[DataProvider('provideToolNames')]
    public function testAScheduleOfASpaceOfAnotherAccountIsAnsweredAsNotFound(string $toolName): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE);
        static::attachAgentSpaceOfAccount($schedule, static::OTHER_ACCOUNT_ID);

        $this->assertNotFoundWithoutAWrite($toolName, $schedule);
    }

    #[DataProvider('provideToolNames')]
    public function testAnIdWithoutAScheduleIsAnsweredAsNotFound(string $toolName): void
    {
        $schedulesService = static::buildSchedulesService(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No such task schedule — list them with getTaskSchedules.');
        static::buildPackage()->{$toolName}(static::buildRequestOfTool($toolName), $schedulesService, static::buildProvenanceOfAChatTurn());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideToolNames(): array
    {
        $toolNames = [];
        foreach (self::TOOL_NAMES as $toolName) {
            $toolNames[$toolName] = [$toolName];
        }
        return $toolNames;
    }

    // ------------------------------------------------------------------ pauseTaskSchedule

    #[DataProvider('provideKindsThatPause')]
    public function testAnActiveScheduleIsPausedAndKeepsItsAnchor(string $kind): void
    {
        $schedule = static::buildSchedule($kind, AIConversationScheduleStatus::ACTIVE);
        $anchorBefore = $schedule->nextRunAt;

        $responseDto = static::buildPackage()->pauseTaskSchedule(
            static::buildIdRequest(),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['status'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::PAUSED, $schedule->status);
        self::assertSame($anchorBefore, $schedule->nextRunAt, 'the anchor stays in the row; a resume replaces it');
        self::assertSame(static::SCHEDULE_ID, $responseDto->scheduleId);
        self::assertNull($responseDto->nextRunAt, 'a paused schedule is told without a next firing');
        self::assertSame(
            'Schedule paused — it will not fire until resumed (resumeTaskSchedule); id, cron and run history are kept. Confirm to the user in ONE short sentence.',
            $responseDto->note
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideKindsThatPause(): array
    {
        return [
            self::KIND_RECURRING => [self::KIND_RECURRING],
            self::KIND_RECURRING_RAN => [self::KIND_RECURRING_RAN],
            self::KIND_ONE_TIME => [self::KIND_ONE_TIME],
        ];
    }

    #[DataProvider('provideKindsAndStatusesThatDoNotPause')]
    public function testAPauseIsRefusedInEveryOtherStatus(string $kind, string $status): void
    {
        $this->assertRefusedWithoutAWrite(
            self::PAUSE_REFUSAL_BY_STATUS[$status],
            'pauseTaskSchedule',
            static::buildIdRequest(),
            static::buildSchedule($kind, $status)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideKindsAndStatusesThatDoNotPause(): array
    {
        return static::buildCasesOfKindsAndStatuses([self::KIND_RECURRING_RAN, self::KIND_ONE_TIME], array_keys(self::PAUSE_REFUSAL_BY_STATUS));
    }

    // ------------------------------------------------------------------ resumeTaskSchedule

    public function testAResumedRecurringScheduleFiresNextFromNowAndNotFromItsOldAnchor(): void
    {
        $eligibilityService = $this->installEligibilityService();
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        $schedule->nextRunAt = new DateTime(static::ANCHOR_OF_YEARS_AGO);

        $responseDto = static::buildPackage()->resumeTaskSchedule(
            static::buildIdRequest(),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['status,nextRunAt'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        $nextFiringInTheZone = (new \DateTimeImmutable('@' . $schedule->nextRunAt->getTimestamp()))->setTimezone(new \DateTimeZone(static::ZONE));
        self::assertGreaterThan(time(), $schedule->nextRunAt->getTimestamp(), 'a long pause fires no backlog');
        // a week, and the hour a change of summer time adds
        self::assertLessThanOrEqual(time() + 7 * 86400 + 3600, $schedule->nextRunAt->getTimestamp());
        self::assertSame('Monday 07:00', $nextFiringInTheZone->format('l H:i'));
        self::assertSame(static::SCHEDULE_ID, $responseDto->scheduleId);
        self::assertSame($schedule->nextRunAt, $responseDto->nextRunAt);
        self::assertSame(
            'Schedule resumed. Next firing: ' . $nextFiringInTheZone->format('Y-m-d H:i:sP') . ' (Monday). Confirm to the user in ONE short sentence when it fires next.',
            $responseDto->note
        );
        self::assertSame(['account 3, read fresh'], $eligibilityService->eligibilityReads);
        self::assertSame([], $eligibilityService->refusalQuestions, 'an eligible scope is not asked to refuse');
    }

    public function testAResumedOneTimeTaskFiresAtItsMoment(): void
    {
        $this->installEligibilityService();
        $schedule = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::PAUSED);
        $schedule->nextRunAt = new DateTime(static::ANCHOR_OF_YEARS_AGO);

        $responseDto = static::buildPackage()->resumeTaskSchedule(
            static::buildIdRequest(),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['status,nextRunAt'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        self::assertSame((new DateTime(static::ONE_TIME_MOMENT))->getTimestamp(), $schedule->nextRunAt->getTimestamp());
        self::assertSame(
            'Schedule resumed. Next firing: ' . static::ONE_TIME_MOMENT_AS_THE_MODEL_READS_IT . '. Confirm to the user in ONE short sentence when it fires next.',
            $responseDto->note
        );
    }

    public function testTheNextFiringIsToldWithTheWeekdayOfTheZoneOfTheSchedule(): void
    {
        $this->installEligibilityService();
        $schedule = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::PAUSED);
        // Wednesday 23:30 in UTC is Thursday 01:30 in Berlin
        $schedule->oneTimeAt = new DateTime('2099-07-01 23:30:00');

        $responseDto = static::buildPackage()->resumeTaskSchedule(
            static::buildIdRequest(),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(
            'Schedule resumed. Next firing: 2099-07-02 01:30:00+02:00 (Thursday). Confirm to the user in ONE short sentence when it fires next.',
            $responseDto->note
        );
    }

    public function testAScheduleOfASpaceIsResumedOnTheEligibilityOfItsSpace(): void
    {
        $eligibilityService = $this->installEligibilityService();
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        static::attachAgentSpaceOfAccount($schedule, static::ACTING_ACCOUNT_ID);

        static::buildPackage()->resumeTaskSchedule(static::buildIdRequest(), static::buildSchedulesService($schedule), static::buildProvenanceOfAChatTurn());

        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        self::assertSame(['space 17, read fresh'], $eligibilityService->eligibilityReads);
    }

    #[DataProvider('provideStatusesThatDoNotResume')]
    public function testAResumeIsRefusedInEveryOtherStatusBeforeTheScopeIsRead(string $kind, string $status): void
    {
        $eligibilityService = $this->installEligibilityService(AgentAutomationEligibility::STATE_SUSPENDED, 'PAYMENT_OVERDUE');

        $this->assertRefusedWithoutAWrite(
            self::RESUME_REFUSAL_BY_STATUS[$status],
            'resumeTaskSchedule',
            static::buildIdRequest(),
            static::buildSchedule($kind, $status)
        );
        self::assertSame([], $eligibilityService->eligibilityReads);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideStatusesThatDoNotResume(): array
    {
        return static::buildCasesOfKindsAndStatuses([self::KIND_RECURRING_RAN, self::KIND_ONE_TIME], array_keys(self::RESUME_REFUSAL_BY_STATUS));
    }

    public function testAOneTimeTaskWhoseMomentHasPassedIsNotResumed(): void
    {
        $eligibilityService = $this->installEligibilityService();

        $this->assertRefusedWithoutAWrite(
            'Schedule 4430 cannot be resumed: its one-time moment has passed. Schedule a new one-time task instead.',
            'resumeTaskSchedule',
            static::buildIdRequest(),
            static::buildSchedule(self::KIND_ONE_TIME_PASSED, AIConversationScheduleStatus::PAUSED)
        );
        self::assertSame([], $eligibilityService->eligibilityReads);
    }

    #[DataProvider('provideReasonsOfAPauseByThePlatform')]
    public function testAScheduleThePlatformPausedIsNotResumedByHandAndNamesTheState(string $kind, string $stateReason, string $expectedWordsOfTheReason): void
    {
        $eligibilityService = $this->installEligibilityService();
        $schedule = static::buildSchedule($kind, AIConversationScheduleStatus::PAUSED);
        $schedule->stateReason = $stateReason;

        $this->assertRefusedWithoutAWrite(
            'Schedule 4430 was paused by the platform (' . $expectedWordsOfTheReason . ') and resumes by itself once the '
            . AgentApplicationWording::current()->billingEntityName . ' or account is active again.',
            'resumeTaskSchedule',
            static::buildIdRequest(),
            $schedule
        );
        self::assertSame([], $eligibilityService->eligibilityReads);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideReasonsOfAPauseByThePlatform(): array
    {
        return [
            'a suspended account' => [self::KIND_RECURRING_RAN, AgentAutomationStateReason::ACCOUNT_SUSPENDED, 'account suspended'],
            'a suspended space' => [self::KIND_RECURRING_RAN, AgentAutomationStateReason::AGENT_SPACE_SUSPENDED, 'agent space suspended'],
            // the state is told before the passed moment
            'a suspended account, a one-time task whose moment has passed' => [self::KIND_ONE_TIME_PASSED, AgentAutomationStateReason::ACCOUNT_SUSPENDED, 'account suspended'],
        ];
    }

    #[DataProvider('provideStatesOfAScopeThatIsNotEligible')]
    public function testAResumeIsRefusedWhileTheScopeIsNotEligible(string $state, string $reason, string $expectedWords): void
    {
        $eligibilityService = $this->installEligibilityService($state, $reason);

        $this->assertRefusedWithoutAWrite(
            'Schedule 4430 cannot be resumed: the ' . AgentApplicationWording::current()->billingEntityName . ' or account is ' . $expectedWords . '.',
            'resumeTaskSchedule',
            static::buildIdRequest(),
            static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED)
        );
        self::assertSame(['account 3, read fresh'], $eligibilityService->eligibilityReads);
        self::assertSame(['scheduleResume of schedule 4430'], $eligibilityService->refusalQuestions);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideStatesOfAScopeThatIsNotEligible(): array
    {
        return [
            'suspended' => [AgentAutomationEligibility::STATE_SUSPENDED, 'PAYMENT_OVERDUE', 'suspended (payment overdue)'],
            'ended' => [AgentAutomationEligibility::STATE_ENDED, 'CONTRACT_ENDED', 'ended (contract ended)'],
            'erased' => [AgentAutomationEligibility::STATE_ERASED, 'ACCOUNT_ERASED', 'erased (account erased)'],
        ];
    }

    public function testAScopeThatIsNotEligibleResumesWhenTheApplicationDoesNotRefuseIt(): void
    {
        $eligibilityService = $this->installEligibilityService(AgentAutomationEligibility::STATE_SUSPENDED, 'PAYMENT_OVERDUE', refusesWhenNotEligible: false);
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);

        static::buildPackage()->resumeTaskSchedule(static::buildIdRequest(), static::buildSchedulesService($schedule), static::buildProvenanceOfAChatTurn());

        self::assertSame(['scheduleResume of schedule 4430'], $eligibilityService->refusalQuestions);
        self::assertSame(['status,nextRunAt'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
    }

    #[DataProvider('provideReasonsOfACancellationByThePlatform')]
    public function testAScheduleThePlatformCancelledIsToldItNeverComesBack(string $kind, string $stateReason, string $expectedWordsOfTheReason): void
    {
        $eligibilityService = $this->installEligibilityService();
        $schedule = static::buildSchedule($kind, AIConversationScheduleStatus::CANCELLED);
        $schedule->stateReason = $stateReason;

        $this->assertRefusedWithoutAWrite(
            'Schedule 4430 was closed by the platform (' . $expectedWordsOfTheReason . ') and is cancelled — it does not resume and never fires again.'
            . ' Once the ' . AgentApplicationWording::current()->billingEntityName . ' or account is active again, a new schedule is needed.',
            'resumeTaskSchedule',
            static::buildIdRequest(),
            $schedule
        );
        self::assertSame([], $eligibilityService->eligibilityReads);
    }

    /**
     * Only a CANCELLED row with a platform reason is told "closed by the platform": a reason left in a row of another
     * status (a row archived before an owner's status change cleared the reason, a host's own write) gets the status
     * answer.
     */
    #[DataProvider('provideStatusesWithAReasonLeftInTheRow')]
    public function testAReasonLeftInARowThatIsNeitherPausedNorCancelledGetsTheStatusAnswer(string $status): void
    {
        $this->installEligibilityService();
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, $status);
        $schedule->stateReason = AgentAutomationStateReason::ACCOUNT_SUSPENDED;

        $this->assertRefusedWithoutAWrite('Schedule 4430 cannot be resumed in status ' . $status . '.', 'resumeTaskSchedule', static::buildIdRequest(), $schedule);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideStatusesWithAReasonLeftInTheRow(): array
    {
        return [
            'archived by the owner after a platform pause' => [AIConversationScheduleStatus::ARCHIVED],
            'active with a leftover reason' => [AIConversationScheduleStatus::ACTIVE],
            'expired with a leftover reason' => [AIConversationScheduleStatus::EXPIRED],
            'done with a leftover reason' => [AIConversationScheduleStatus::DONE],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideReasonsOfACancellationByThePlatform(): array
    {
        return [
            'an ended space' => [self::KIND_RECURRING_RAN, AgentAutomationStateReason::AGENT_SPACE_ENDED, 'agent space ended'],
            'an ended account' => [self::KIND_RECURRING_RAN, AgentAutomationStateReason::ACCOUNT_ENDED, 'account ended'],
            'an erased account, a one-time task' => [self::KIND_ONE_TIME, AgentAutomationStateReason::ACCOUNT_ERASED, 'account erased'],
        ];
    }

    #[DataProvider('provideSchedulesWhoseLifetimeIsOver')]
    public function testAResumeOfAScheduleWhoseLifetimeIsOverClosesItAsExpired(?string $ttlAt, ?int $maxIterations, int $iterationsUsed, string $expectedWordsOfTheEnd): void
    {
        $eligibilityService = $this->installEligibilityService();
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        if ($ttlAt !== null) {
            $schedule->ttlAt = new DateTime($ttlAt);
        }
        $schedule->maxIterations = $maxIterations;
        $schedule->iterationsUsed = $iterationsUsed;
        $anchorBefore = $schedule->nextRunAt;

        $responseDto = static::buildPackage()->resumeTaskSchedule(
            static::buildIdRequest(),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['status'], $schedule->recordedWrites, 'closed, not re-armed');
        self::assertSame(AIConversationScheduleStatus::EXPIRED, $schedule->status);
        self::assertSame($anchorBefore, $schedule->nextRunAt);
        self::assertSame(static::SCHEDULE_ID, $responseDto->scheduleId);
        self::assertNull($responseDto->nextRunAt, 'an ended schedule is told without a next firing');
        self::assertSame(
            'Schedule 4430 was not resumed: it has ended — ' . $expectedWordsOfTheEnd . ' — and is now EXPIRED; it never fires again.'
            . ' Tell the user so in ONE short sentence; if they still want the task, a new schedule is needed'
            . ' (scheduleOneTimeTask or createRecurringTaskSchedule).',
            $responseDto->note
        );
        self::assertSame([], $eligibilityService->refusalQuestions);
    }

    /**
     * @return array<string, array{0: string|null, 1: int|null, 2: int, 3: string}>
     */
    public static function provideSchedulesWhoseLifetimeIsOver(): array
    {
        return [
            'its ttl passed' => ['2020-01-01 00:00:00', null, 1, 'its lifetime (ttl) has passed'],
            'its runs are used up' => [null, 3, 3, 'its runs are used up (3 of 3)'],
        ];
    }

    public function testTheEndOfItsLifetimeIsToldBeforeTheScope(): void
    {
        $eligibilityService = $this->installEligibilityService(AgentAutomationEligibility::STATE_SUSPENDED, 'PAYMENT_OVERDUE');
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        $schedule->ttlAt = new DateTime('2020-01-01 00:00:00');

        static::buildPackage()->resumeTaskSchedule(static::buildIdRequest(), static::buildSchedulesService($schedule), static::buildProvenanceOfAChatTurn());

        self::assertSame(['status'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::EXPIRED, $schedule->status);
        self::assertSame([], $eligibilityService->refusalQuestions, 'an end is no automation: the application is not asked');
    }

    // ------------------------------------------------------------------ deleteTaskSchedule

    /**
     * @param string[] $expectedWrites
     */
    #[DataProvider('provideSchedulesToDelete')]
    public function testADeleteFollowsTheArchivePolicyInEveryStatus(
        string $kind,
        string $status,
        array $expectedWrites,
        string $expectedStatusAfterwards,
        string $expectedActionTaken,
        string $expectedNote
    ): void {
        $schedule = static::buildSchedule($kind, $status);

        $responseDto = static::buildPackage()->deleteTaskSchedule(
            static::buildIdRequest(),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame($expectedWrites, $schedule->recordedWrites);
        self::assertSame($expectedStatusAfterwards, $schedule->status);
        self::assertSame($expectedActionTaken, $responseDto->actionTaken);
        self::assertSame($expectedNote, $responseDto->note);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string[], 3: string, 4: string, 5: string}>
     */
    public static function provideSchedulesToDelete(): array
    {
        $noteOfAHardDelete = 'The task never ran and is deleted for good — confirm in ONE short sentence.';
        $noteOfAnArchive = 'The recurring task is stopped and archived (its past runs stay readable) — confirm in ONE short sentence.';
        $noteOfKeptHistory = 'This one-time task already ran — it stays in the history unchanged; nothing was deleted. Tell the user so.';
        $cases = [];
        foreach ([self::KIND_RECURRING, self::KIND_ONE_TIME, self::KIND_ONE_TIME_PASSED] as $kindThatNeverRan) {
            foreach ([AIConversationScheduleStatus::ACTIVE, AIConversationScheduleStatus::PAUSED, AIConversationScheduleStatus::CANCELLED] as $status) {
                $cases[$kindThatNeverRan . ', ' . $status . ': removed for good'] = [
                    $kindThatNeverRan,
                    $status,
                    ['delete'],
                    $status,
                    AIConversationSchedule::DELETE_ACTION_HARD_DELETE,
                    $noteOfAHardDelete,
                ];
            }
        }
        foreach (self::STATUSES as $status) {
            $cases[self::KIND_RECURRING_RAN . ', ' . $status . ': archived'] = [
                self::KIND_RECURRING_RAN,
                $status,
                ['status'],
                AIConversationScheduleStatus::ARCHIVED,
                AIConversationSchedule::DELETE_ACTION_ARCHIVE,
                $noteOfAnArchive,
            ];
            $cases[self::KIND_ONE_TIME_RAN . ', ' . $status . ': stays as history'] = [
                self::KIND_ONE_TIME_RAN,
                $status,
                [],
                $status,
                AIConversationSchedule::DELETE_ACTION_KEEP_HISTORY,
                $noteOfKeptHistory,
            ];
        }
        return $cases;
    }

    // ------------------------------------------------------------------ updateTaskSchedule: what is refused before a field is read

    #[DataProvider('provideFinishedSchedulesAndTheirEdits')]
    public function testAFinishedScheduleIsNotChangedWhateverTheEdit(string $kind, string $status, array $requestFields): void
    {
        $this->assertRefusedWithoutAWrite(
            self::UPDATE_REFUSAL_BY_STATUS[$status],
            'updateTaskSchedule',
            static::buildUpdateRequest($requestFields),
            static::buildSchedule($kind, $status)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function provideFinishedSchedulesAndTheirEdits(): array
    {
        $cases = [];
        foreach (array_keys(self::UPDATE_REFUSAL_BY_STATUS) as $status) {
            $cases[$status . ', a new title'] = [self::KIND_RECURRING_RAN, $status, ['title' => 'Monthly summary']];
            // the status is told before the kind: neither "a one-time task has no cron" nor "it already ran"
            $cases[$status . ', a cron for a one-time task'] = [self::KIND_ONE_TIME_RAN, $status, ['cronSchedule' => '0 9 * * 5']];
            $cases[$status . ', a new moment for a one-time task that ran'] = [self::KIND_ONE_TIME_RAN, $status, ['runAtLocalTime' => '2099-07-02 16:00']];
        }
        return $cases;
    }

    /**
     * @param array<string, mixed> $requestFields
     */
    #[DataProvider('provideUpdatesWithoutAChange')]
    public function testAnUpdateWithoutAChangeIsRefused(array $requestFields): void
    {
        $this->assertRefusedWithoutAWrite(
            'Nothing to change — pass at least one field besides scheduleId.',
            'updateTaskSchedule',
            static::buildUpdateRequest($requestFields),
            static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::ACTIVE)
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function provideUpdatesWithoutAChange(): array
    {
        return [
            'only the id' => [[]],
            'a blank task and a blank title' => [['task' => '   ', 'title' => '']],
            'a blank cron and a blank zone' => [['cronSchedule' => ' ', 'timezoneIdentifier' => '  ']],
            'zero minutes and a blank local time count as not set' => [['runInMinutes' => 0, 'runAtLocalTime' => ' ']],
            'negative minutes count as not set' => [['runInMinutes' => -5]],
        ];
    }

    // ------------------------------------------------------------------ updateTaskSchedule: text, criteria, channel

    /**
     * @param array<string, mixed> $requestFields
     * @param array<string, mixed> $expectedValues the values of the schedule after the call, by property name
     */
    #[DataProvider('provideEditsOfTextCriteriaAndChannel')]
    public function testAnEditOfTextCriteriaOrChannelWritesItsColumnsAndLeavesTheTiming(array $requestFields, string $expectedWrite, array $expectedValues): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE);
        $schedule->doneCriteria = 'Every request has an answer.';
        $anchorBefore = $schedule->nextRunAt;

        $responseDto = static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest($requestFields),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame([$expectedWrite], $schedule->recordedWrites);
        foreach ($expectedValues as $propertyName => $expectedValue) {
            self::assertSame($expectedValue, $schedule->{$propertyName}, $propertyName);
        }
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        self::assertSame(static::CRON, $schedule->schedule);
        self::assertSame(static::ZONE, $schedule->timezone);
        self::assertSame($anchorBefore, $schedule->nextRunAt);
        self::assertSame(static::SCHEDULE_ID, $responseDto->scheduleId);
        self::assertSame($anchorBefore, $responseDto->nextRunAt);
        self::assertSame(
            self::NOTE_UPDATED . 'Next firing: ' . static::ANCHOR_AS_THE_MODEL_READS_IT . '. ' . self::NOTE_CONFIRM_THE_CHANGE,
            $responseDto->note
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<string, mixed>}>
     */
    public static function provideEditsOfTextCriteriaAndChannel(): array
    {
        $titleOfSixtyOneCharacters = str_repeat('Weekly summary ', 4) . 'x';
        return [
            'the task, trimmed' => [
                ['task' => '  Summarise the open orders.  '],
                'task',
                ['task' => 'Summarise the open orders.', 'title' => self::TITLE],
            ],
            'a title of 61 characters is cut to 60' => [
                ['title' => $titleOfSixtyOneCharacters],
                'title',
                ['title' => mb_substr($titleOfSixtyOneCharacters, 0, 60), 'task' => self::TASK],
            ],
            'done criteria, trimmed' => [
                ['doneCriteria' => ' No request is older than a day. '],
                'doneCriteria',
                ['doneCriteria' => 'No request is older than a day.'],
            ],
            'an empty string removes the done criteria' => [
                ['doneCriteria' => ''],
                'doneCriteria',
                ['doneCriteria' => null],
            ],
            'the report channel, normalised' => [
                ['reportChannel' => ' chat '],
                'reportChannel',
                ['reportChannel' => AIConversationSchedule::REPORT_CHANNEL_CHAT],
            ],
            'all four in one call, in this order' => [
                ['reportChannel' => 'CHAT', 'doneCriteria' => 'Done.', 'title' => '  Orders ', 'task' => 'Summarise the open orders.'],
                'task,title,doneCriteria,reportChannel',
                ['task' => 'Summarise the open orders.', 'title' => 'Orders', 'doneCriteria' => 'Done.', 'reportChannel' => AIConversationSchedule::REPORT_CHANNEL_CHAT],
            ],
        ];
    }

    public function testAPausedScheduleIsEditedTheSameWayAndStaysPaused(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        $anchorBefore = $schedule->nextRunAt;

        $responseDto = static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['task' => 'Summarise the open orders.', 'reportChannel' => 'chat']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['task,reportChannel'], $schedule->recordedWrites);
        self::assertSame('Summarise the open orders.', $schedule->task);
        self::assertSame(AIConversationSchedule::REPORT_CHANNEL_CHAT, $schedule->reportChannel);
        self::assertSame(AIConversationScheduleStatus::PAUSED, $schedule->status);
        self::assertSame($anchorBefore, $schedule->nextRunAt);
        self::assertNull($responseDto->nextRunAt, 'the anchor a pause leaves in the row is no next firing');
        self::assertSame(self::NOTE_UPDATED . self::NOTE_PAUSED_RECURRING . self::NOTE_CONFIRM_THE_CHANGE, $responseDto->note);
    }

    public function testAPausedOneTimeTaskIsToldTheMomentItFiresAtOnceResumed(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::PAUSED);

        $responseDto = static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['title' => 'Orders']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['title'], $schedule->recordedWrites);
        self::assertNull($responseDto->nextRunAt);
        self::assertSame(
            self::NOTE_UPDATED . self::NOTE_PAUSED_ONE_TIME_PREFIX . static::ONE_TIME_MOMENT_AS_THE_MODEL_READS_IT . '. ' . self::NOTE_CONFIRM_THE_CHANGE,
            $responseDto->note
        );
    }

    public function testAnUnknownReportChannelIsRefused(): void
    {
        $this->assertRefusedWithoutAWrite(
            "Unknown reportChannel 'SMS' — use EMAIL or CHAT.",
            'updateTaskSchedule',
            static::buildUpdateRequest(['reportChannel' => 'sms']),
            static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE)
        );
    }

    // ------------------------------------------------------------------ updateTaskSchedule: the cron and the zone of a recurring schedule

    public function testANewCronReArmsAnActiveScheduleFromNow(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE);

        $responseDto = static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['cronSchedule' => '  0 9 * * 5 ']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['schedule,nextRunAt'], $schedule->recordedWrites);
        self::assertSame('0 9 * * 5', $schedule->schedule);
        self::assertSame(static::ZONE, $schedule->timezone);
        $nextFiringInTheZone = (new \DateTimeImmutable('@' . $schedule->nextRunAt->getTimestamp()))->setTimezone(new \DateTimeZone(static::ZONE));
        self::assertGreaterThan(time(), $schedule->nextRunAt->getTimestamp());
        self::assertLessThanOrEqual(time() + 7 * 86400 + 3600, $schedule->nextRunAt->getTimestamp());
        self::assertSame('Friday 09:00', $nextFiringInTheZone->format('l H:i'));
        self::assertSame($schedule->nextRunAt, $responseDto->nextRunAt);
        self::assertSame(
            self::NOTE_UPDATED . 'Next firing: ' . $nextFiringInTheZone->format('Y-m-d H:i:sP') . ' (Friday). ' . self::NOTE_CONFIRM_THE_CHANGE,
            $responseDto->note
        );
    }

    public function testANewCronOnAPausedScheduleIsStoredWithoutAnAnchor(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        $anchorBefore = $schedule->nextRunAt;

        static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['cronSchedule' => '0 9 * * 5']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['schedule'], $schedule->recordedWrites, 'the resume computes the anchor of a paused schedule');
        self::assertSame('0 9 * * 5', $schedule->schedule);
        self::assertSame($anchorBefore, $schedule->nextRunAt);
        self::assertSame(AIConversationScheduleStatus::PAUSED, $schedule->status);
    }

    public function testTheTextAndTheCronInOneCallAreOneWrite(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE);

        static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['cronSchedule' => '0 9 * * 5', 'task' => 'Summarise the open orders.']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['task,schedule,nextRunAt'], $schedule->recordedWrites);
    }

    /**
     * @param array<string, mixed> $requestFields
     */
    #[DataProvider('provideCronEditsThatAreRefused')]
    public function testACronEditIsRefused(string $kind, string $status, array $requestFields, string $expectedRefusal): void
    {
        $this->assertRefusedWithoutAWrite(
            $expectedRefusal,
            'updateTaskSchedule',
            static::buildUpdateRequest($requestFields),
            static::buildSchedule($kind, $status)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function provideCronEditsThatAreRefused(): array
    {
        $refusalOnAOneTimeTask = 'Schedule 4430 is a ONE-TIME task — move its moment with runAtLocalTime/runInMinutes; a cron applies to recurring schedules only.';
        return [
            'five firings per day, as at creation' => [
                self::KIND_RECURRING_RAN,
                AIConversationScheduleStatus::ACTIVE,
                ['cronSchedule' => '0 6,9,12,15,18 * * *'],
                "The cron '0 6,9,12,15,18 * * *' fires 5× per day — at most 4 firings per day are allowed (every firing is a full agent run"
                . ' that costs budget). Choose specific times of day (e.g. "0 8 * * *") or a weekly rhythm, and explain the limit to the user.',
            ],
            'five firings per day on a paused schedule' => [
                self::KIND_RECURRING_RAN,
                AIConversationScheduleStatus::PAUSED,
                ['cronSchedule' => '0 6,9,12,15,18 * * *'],
                "The cron '0 6,9,12,15,18 * * *' fires 5× per day — at most 4 firings per day are allowed (every firing is a full agent run"
                . ' that costs budget). Choose specific times of day (e.g. "0 8 * * *") or a weekly rhythm, and explain the limit to the user.',
            ],
            'an expression that is no cron' => [
                self::KIND_RECURRING_RAN,
                AIConversationScheduleStatus::ACTIVE,
                ['cronSchedule' => 'every monday'],
                "Invalid cron expression 'every monday'.",
            ],
            'an expression that is no cron on a paused schedule' => [
                self::KIND_RECURRING_RAN,
                AIConversationScheduleStatus::PAUSED,
                ['cronSchedule' => 'every monday'],
                "Invalid cron expression 'every monday'.",
            ],
            'a cron for a one-time task' => [self::KIND_ONE_TIME, AIConversationScheduleStatus::ACTIVE, ['cronSchedule' => '0 9 * * 5'], $refusalOnAOneTimeTask],
            'a cron for a paused one-time task' => [self::KIND_ONE_TIME, AIConversationScheduleStatus::PAUSED, ['cronSchedule' => '0 9 * * 5'], $refusalOnAOneTimeTask],
        ];
    }

    public function testANewZoneMovesTheFiringsOfAnActiveRecurringScheduleAndIsConfirmed(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE);

        $responseDto = static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['timezoneIdentifier' => ' America/New_York ']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['timezone,timezoneUnverified,nextRunAt'], $schedule->recordedWrites);
        self::assertSame('America/New_York', $schedule->timezone);
        self::assertFalse($schedule->timezoneUnverified, 'a zone the request names is a confirmed one');
        self::assertSame(static::CRON, $schedule->schedule);
        $nextFiringInTheNewZone = (new \DateTimeImmutable('@' . $schedule->nextRunAt->getTimestamp()))->setTimezone(new \DateTimeZone('America/New_York'));
        self::assertGreaterThan(time(), $schedule->nextRunAt->getTimestamp());
        self::assertSame('Monday 07:00', $nextFiringInTheNewZone->format('l H:i'));
        self::assertSame(
            self::NOTE_UPDATED . 'Next firing: ' . $nextFiringInTheNewZone->format('Y-m-d H:i:sP') . ' (Monday). ' . self::NOTE_CONFIRM_THE_CHANGE,
            $responseDto->note
        );
    }

    public function testANewZoneOnAPausedRecurringScheduleIsStoredWithoutAnAnchor(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        $anchorBefore = $schedule->nextRunAt;

        static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['timezoneIdentifier' => 'America/New_York']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['timezone,timezoneUnverified'], $schedule->recordedWrites);
        self::assertSame('America/New_York', $schedule->timezone);
        self::assertSame($anchorBefore, $schedule->nextRunAt);
    }

    public function testAZoneAndACronInOneCallAreCountedAndAnchoredInTheNewZone(): void
    {
        $schedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE);

        static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['cronSchedule' => '0 9 * * 5', 'timezoneIdentifier' => 'America/New_York']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['timezone,timezoneUnverified,schedule,nextRunAt'], $schedule->recordedWrites);
        $nextFiringInTheNewZone = (new \DateTimeImmutable('@' . $schedule->nextRunAt->getTimestamp()))->setTimezone(new \DateTimeZone('America/New_York'));
        self::assertSame('Friday 09:00', $nextFiringInTheNewZone->format('l H:i'));
    }

    public function testAnUnknownZoneIsRefused(): void
    {
        $this->assertRefusedWithoutAWrite(
            "Unknown time zone 'Mars/Base' — use an IANA identifier such as Europe/Berlin.",
            'updateTaskSchedule',
            static::buildUpdateRequest(['timezoneIdentifier' => 'Mars/Base', 'title' => 'Orders']),
            static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE)
        );
    }

    // ------------------------------------------------------------------ updateTaskSchedule: the moment and the zone of a one-time task

    public function testANewLocalTimeMovesAnActiveOneTimeTaskAndItsAnchor(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::ACTIVE);

        $responseDto = static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['runAtLocalTime' => ' 2099-07-02 16:00 ']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['oneTimeAt,nextRunAt'], $schedule->recordedWrites);
        // 16:00 in Berlin is 14:00 UTC
        self::assertSame((new DateTime('2099-07-02 14:00:00'))->getTimestamp(), $schedule->oneTimeAt->getTimestamp());
        self::assertSame($schedule->oneTimeAt->getTimestamp(), $schedule->nextRunAt->getTimestamp());
        self::assertSame(static::ZONE, $schedule->timezone);
        self::assertSame(
            self::NOTE_UPDATED . 'Next firing: 2099-07-02 16:00:00+02:00 (Thursday). '
            . 'One-time task scheduled for 2099-07-02 16:00 local time — that is Thursday. '
            . self::NOTE_VERIFY_THE_MOMENT . self::NOTE_CONFIRM_THE_CHANGE,
            $responseDto->note
        );
    }

    public function testANewLocalTimeOnAPausedOneTimeTaskIsStoredWithoutMovingTheAnchor(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::PAUSED);
        $anchorBefore = $schedule->nextRunAt;

        $responseDto = static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['runAtLocalTime' => '2099-07-02 16:00']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['oneTimeAt'], $schedule->recordedWrites, 'the resume moves the anchor onto the new moment');
        self::assertSame((new DateTime('2099-07-02 14:00:00'))->getTimestamp(), $schedule->oneTimeAt->getTimestamp());
        self::assertSame($anchorBefore, $schedule->nextRunAt);
        self::assertNull($responseDto->nextRunAt, 'the old moment left in the row is no next firing');
        self::assertSame(
            self::NOTE_UPDATED . self::NOTE_PAUSED_ONE_TIME_PREFIX . '2099-07-02 16:00:00+02:00 (Thursday). '
            . 'One-time task scheduled for 2099-07-02 16:00 local time — that is Thursday. '
            . self::NOTE_VERIFY_THE_MOMENT . self::NOTE_CONFIRM_THE_CHANGE,
            $responseDto->note
        );
    }

    public function testANewMomentInMinutesIsCountedFromNow(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::ACTIVE);

        static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['runInMinutes' => 90]),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['oneTimeAt,nextRunAt'], $schedule->recordedWrites);
        self::assertGreaterThanOrEqual(time() + 89 * 60, $schedule->oneTimeAt->getTimestamp());
        // a relative wish that lands in the night of the zone is moved to the morning: at most a day later
        self::assertLessThanOrEqual(time() + 90 * 60 + 86400, $schedule->oneTimeAt->getTimestamp());
    }

    public function testALocalTimeOneMinuteAgoIsStillTaken(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::ACTIVE);

        static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['runAtLocalTime' => static::buildLocalTimeInTheZone('-1 minute')]),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['oneTimeAt,nextRunAt'], $schedule->recordedWrites);
        $secondsAgo = time() - $schedule->oneTimeAt->getTimestamp();
        self::assertGreaterThanOrEqual(60, $secondsAgo);
        self::assertLessThanOrEqual(180, $secondsAgo);
    }

    public function testALocalTimeThreeMinutesAgoIsRefusedWithTheCurrentLocalTime(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::ACTIVE);
        $localTimeThreeMinutesAgo = static::buildLocalTimeInTheZone('-3 minutes');
        try {
            static::buildPackage()->updateTaskSchedule(
                static::buildUpdateRequest(['runAtLocalTime' => $localTimeThreeMinutesAgo]),
                static::buildSchedulesService($schedule),
                static::buildProvenanceOfAChatTurn()
            );
            self::fail('A local time in the past is refused');
        } catch (BadRequestException $badRequestException) {
            self::assertMatchesRegularExpression(
                '/^' . preg_quote('That local time (' . $localTimeThreeMinutesAgo . ' ' . static::ZONE . ') is in the past — right NOW it is ', '/')
                . '\d{4}-\d{2}-\d{2} \d{2}:\d{2}'
                . preg_quote(' there; pass a future time or use runInMinutes.', '/') . '$/u',
                $badRequestException->getMessage()
            );
        }
        self::assertSame([], $schedule->recordedWrites);
    }

    public function testAZoneTogetherWithALocalTimeReadsTheMomentInTheNewZone(): void
    {
        $schedule = static::buildSchedule(self::KIND_ONE_TIME, AIConversationScheduleStatus::ACTIVE);

        $responseDto = static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['timezoneIdentifier' => 'America/New_York', 'runAtLocalTime' => '2099-07-02 16:00']),
            static::buildSchedulesService($schedule),
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['timezone,timezoneUnverified,oneTimeAt,nextRunAt'], $schedule->recordedWrites);
        self::assertSame('America/New_York', $schedule->timezone);
        self::assertFalse($schedule->timezoneUnverified);
        // 16:00 in New York is 20:00 UTC
        self::assertSame((new DateTime('2099-07-02 20:00:00'))->getTimestamp(), $schedule->oneTimeAt->getTimestamp());
        self::assertSame(
            self::NOTE_UPDATED . 'Next firing: 2099-07-02 16:00:00-04:00 (Thursday). '
            . 'One-time task scheduled for 2099-07-02 16:00 local time — that is Thursday. '
            . self::NOTE_VERIFY_THE_MOMENT . self::NOTE_CONFIRM_THE_CHANGE,
            $responseDto->note
        );
    }

    /**
     * @param array<string, mixed> $requestFields
     */
    #[DataProvider('provideMomentAndZoneEditsThatAreRefused')]
    public function testAMomentOrZoneEditIsRefused(string $kind, string $status, array $requestFields, string $expectedRefusal): void
    {
        $this->assertRefusedWithoutAWrite(
            $expectedRefusal,
            'updateTaskSchedule',
            static::buildUpdateRequest($requestFields),
            static::buildSchedule($kind, $status)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function provideMomentAndZoneEditsThatAreRefused(): array
    {
        $zoneNeedsALocalTime = "Changing a one-time task's time zone needs runAtLocalTime in the same call.";
        $recurringHasNoMoment = 'Schedule 4430 is a RECURRING schedule — change its firing times with cronSchedule; runAtLocalTime/runInMinutes apply to one-time tasks only.';
        $alreadyRan = 'Schedule 4430 already ran — a one-time task that ran is history; schedule a new one with scheduleOneTimeTask instead.';
        return [
            'a zone alone for a one-time task' => [self::KIND_ONE_TIME, AIConversationScheduleStatus::ACTIVE, ['timezoneIdentifier' => 'America/New_York'], $zoneNeedsALocalTime],
            'a zone alone for a paused one-time task' => [self::KIND_ONE_TIME, AIConversationScheduleStatus::PAUSED, ['timezoneIdentifier' => 'America/New_York'], $zoneNeedsALocalTime],
            // minutes are no local time: the zone of a one-time task moves only with a local time read in it
            'a zone with minutes for a one-time task' => [
                self::KIND_ONE_TIME,
                AIConversationScheduleStatus::ACTIVE,
                ['timezoneIdentifier' => 'America/New_York', 'runInMinutes' => 90],
                $zoneNeedsALocalTime,
            ],
            'a local time for a recurring schedule' => [self::KIND_RECURRING_RAN, AIConversationScheduleStatus::ACTIVE, ['runAtLocalTime' => '2099-07-02 16:00'], $recurringHasNoMoment],
            'minutes for a paused recurring schedule' => [self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED, ['runInMinutes' => 90], $recurringHasNoMoment],
            'a local time for a one-time task that ran' => [self::KIND_ONE_TIME_RAN, AIConversationScheduleStatus::ACTIVE, ['runAtLocalTime' => '2099-07-02 16:00'], $alreadyRan],
            'minutes for a paused one-time task that ran' => [self::KIND_ONE_TIME_RAN, AIConversationScheduleStatus::PAUSED, ['runInMinutes' => 90], $alreadyRan],
        ];
    }

    // ------------------------------------------------------------------ a schedule the platform paused

    public function testAScheduleThePlatformPausedIsEditedAndDeletedLikeAnyPausedOneAndItsArchiveClearsTheReason(): void
    {
        $editedSchedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        $editedSchedule->stateReason = AgentAutomationStateReason::ACCOUNT_SUSPENDED;
        static::buildPackage()->updateTaskSchedule(
            static::buildUpdateRequest(['title' => 'Orders']),
            static::buildSchedulesService($editedSchedule),
            static::buildProvenanceOfAChatTurn()
        );
        self::assertSame(['title'], $editedSchedule->recordedWrites);
        self::assertSame(AgentAutomationStateReason::ACCOUNT_SUSPENDED, $editedSchedule->stateReason);

        $deletedSchedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        $deletedSchedule->stateReason = AgentAutomationStateReason::ACCOUNT_SUSPENDED;
        $responseDto = static::buildPackage()->deleteTaskSchedule(
            static::buildIdRequest(),
            static::buildSchedulesService($deletedSchedule),
            static::buildProvenanceOfAChatTurn()
        );
        self::assertSame(['status,stateReason'], $deletedSchedule->recordedWrites, 'the owner archived it: the platform reason goes in the same write');
        self::assertSame(AIConversationScheduleStatus::ARCHIVED, $deletedSchedule->status);
        self::assertNull($deletedSchedule->stateReason);
        self::assertSame(AIConversationSchedule::DELETE_ACTION_ARCHIVE, $responseDto->actionTaken);

        $pausedSchedule = static::buildSchedule(self::KIND_RECURRING_RAN, AIConversationScheduleStatus::PAUSED);
        $pausedSchedule->stateReason = AgentAutomationStateReason::ACCOUNT_SUSPENDED;
        $this->assertRefusedWithoutAWrite(
            self::PAUSE_REFUSAL_BY_STATUS[AIConversationScheduleStatus::PAUSED],
            'pauseTaskSchedule',
            static::buildIdRequest(),
            $pausedSchedule
        );
    }

    // ------------------------------------------------------------------ the stage

    protected static function buildPackage(): AgentTaskSchedulesMCPPackageWithCreationStageDouble
    {
        $package = new AgentTaskSchedulesMCPPackageWithCreationStageDouble();
        $package->fixtureActingAccount = new Account();
        $package->fixtureActingAccount->id = static::ACTING_ACCOUNT_ID;
        // an account without a role: it reads the spaces that are its own
        $package->fixtureActingAccount->roles = new Roles();
        return $package;
    }

    /** The real schedules service; only its lookup by id answers the schedule of the test (null: there is none). */
    protected static function buildSchedulesService(?AIConversationSchedule $schedule): RightsRecordingSchedulesServiceDouble
    {
        $schedulesService = new RightsRecordingSchedulesServiceDouble();
        $schedulesService->fixtureSchedule = $schedule;
        return $schedulesService;
    }

    protected function installEligibilityService(
        string $state = AgentAutomationEligibility::STATE_ELIGIBLE,
        ?string $reason = null,
        bool $refusesWhenNotEligible = true
    ): ResumeRecordingEligibilityServiceDouble {
        $eligibilityService = new ResumeRecordingEligibilityServiceDouble();
        $eligibilityService->fixtureState = $state;
        $eligibilityService->fixtureReason = $reason;
        $eligibilityService->fixtureRefusesWhenNotEligible = $refusesWhenNotEligible;
        $this->replaceService(AgentAutomationEligibilityService::class, $eligibilityService);
        return $eligibilityService;
    }

    protected static function buildProvenanceOfAChatTurn(): AgentToolCallProvenance
    {
        return new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID);
    }

    protected static function buildProvenanceOfAScheduledRun(): AgentToolCallProvenance
    {
        return new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID, scheduleId: static::FIRING_SCHEDULE_ID);
    }

    protected static function buildIdRequest(): TaskScheduleIdRequestDto
    {
        $requestDto = new TaskScheduleIdRequestDto();
        $requestDto->scheduleId = static::SCHEDULE_ID;
        return $requestDto;
    }

    /**
     * @param array<string, mixed> $requestFields the fields of the update besides the id
     */
    protected static function buildUpdateRequest(array $requestFields): TaskScheduleUpdateRequestDto
    {
        $requestDto = new TaskScheduleUpdateRequestDto();
        $requestDto->scheduleId = static::SCHEDULE_ID;
        foreach ($requestFields as $fieldName => $fieldValue) {
            $requestDto->{$fieldName} = $fieldValue;
        }
        return $requestDto;
    }

    /** The request a tool is called with when only the call itself matters: the id, and a new title for the update. */
    protected static function buildRequestOfTool(string $toolName): RequestDto
    {
        return $toolName === 'updateTaskSchedule' ? static::buildUpdateRequest(['title' => 'Orders']) : static::buildIdRequest();
    }

    /**
     * A stored schedule of the acting account, without a space, in the zone {@see ZONE}, reporting by email.
     */
    protected static function buildSchedule(string $kind, string $status): TaskScheduleRecordingWritesDouble
    {
        $schedule = new TaskScheduleRecordingWritesDouble();
        $schedule->id = static::SCHEDULE_ID;
        $schedule->accountId = static::ACTING_ACCOUNT_ID;
        $schedule->status = $status;
        $schedule->task = static::TASK;
        $schedule->title = static::TITLE;
        $schedule->timezone = static::ZONE;
        // the zone was a default nobody confirmed: an edit that names a zone confirms it
        $schedule->timezoneUnverified = true;
        $schedule->reportChannel = AIConversationSchedule::REPORT_CHANNEL_EMAIL;
        if (in_array($kind, [self::KIND_RECURRING, self::KIND_RECURRING_RAN], true)) {
            $schedule->schedule = static::CRON;
            $schedule->nextRunAt = new DateTime(static::ANCHOR);
        } else {
            $moment = $kind === self::KIND_ONE_TIME ? static::ONE_TIME_MOMENT : static::PASSED_ONE_TIME_MOMENT;
            $schedule->maxIterations = 1;
            $schedule->oneTimeAt = new DateTime($moment);
            $schedule->nextRunAt = new DateTime($moment);
        }
        if (in_array($kind, [self::KIND_RECURRING_RAN, self::KIND_ONE_TIME_RAN], true)) {
            $schedule->iterationsUsed = 1;
            $schedule->lastFiredAt = new DateTime(static::ANCHOR_OF_YEARS_AGO);
        }
        return $schedule;
    }

    /** Makes the schedule one of a space that belongs to the given account. */
    protected static function attachAgentSpaceOfAccount(AIConversationSchedule $schedule, int $accountIdOfTheSpace): void
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = static::AGENT_SPACE_ID;
        $agentSpace->accountId = $accountIdOfTheSpace;
        $schedule->agentSpaceId = static::AGENT_SPACE_ID;
        $schedule->agentSpace = $agentSpace;
    }

    /**
     * @param string[] $kinds
     * @param string[] $statuses
     * @return array<string, array{0: string, 1: string}>
     */
    protected static function buildCasesOfKindsAndStatuses(array $kinds, array $statuses): array
    {
        $cases = [];
        foreach ($kinds as $kind) {
            foreach ($statuses as $status) {
                $cases[$kind . ', ' . $status] = [$kind, $status];
            }
        }
        return $cases;
    }

    /** A moment relative to the clock as the local time a model writes it, in the zone of the schedule */
    protected static function buildLocalTimeInTheZone(string $relativeToNow): string
    {
        return (new \DateTimeImmutable($relativeToNow))->setTimezone(new \DateTimeZone(static::ZONE))->format('Y-m-d H:i');
    }

    /** Calls the tool on the schedule and expects the refusal: nothing is written and the status stays. */
    protected function assertRefusedWithoutAWrite(string $expectedRefusal, string $toolName, RequestDto $requestDto, TaskScheduleRecordingWritesDouble $schedule): void
    {
        $statusBefore = $schedule->status;
        try {
            static::buildPackage()->{$toolName}($requestDto, static::buildSchedulesService($schedule), static::buildProvenanceOfAChatTurn());
            self::fail("{$toolName} is refused");
        } catch (BadRequestException $badRequestException) {
            self::assertSame($expectedRefusal, $badRequestException->getMessage());
        }
        self::assertSame([], $schedule->recordedWrites);
        self::assertSame($statusBefore, $schedule->status);
    }

    /** Calls the tool on a schedule outside the scope of the acting account: it is answered like an id without a schedule. */
    protected function assertNotFoundWithoutAWrite(string $toolName, TaskScheduleRecordingWritesDouble $schedule): void
    {
        $schedulesService = static::buildSchedulesService($schedule);
        try {
            static::buildPackage()->{$toolName}(static::buildRequestOfTool($toolName), $schedulesService, static::buildProvenanceOfAChatTurn());
            self::fail("{$toolName} answers a schedule outside the scope as not found");
        } catch (NotFoundException $notFoundException) {
            self::assertSame('No such task schedule — list them with getTaskSchedules.', $notFoundException->getMessage());
        }
        self::assertFalse($schedulesService->rightsAppliedOnFind, 'read without the entity rights; the scope decides');
        self::assertSame([], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
    }
}
