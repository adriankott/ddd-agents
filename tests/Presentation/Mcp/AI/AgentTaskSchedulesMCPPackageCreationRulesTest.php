<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Mcp\Client\Agents\Dtos\BackgroundTaskStartRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\OneTimeTaskScheduleCreateRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\RecurringTaskScheduleCreateRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleCreateMcpResponseDto;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The creation rules of the three tools that create a task schedule — `createRecurringTaskSchedule`,
 * `scheduleOneTimeTask`, `startBackgroundTask` — each called the way the loop calls it: which request is refused with
 * which words, and what the schedule handed to the schedules service carries.
 *
 * The limits: 20 active schedules per scope (paused ones do not count), 4 cron firings per day, 5 new recurring
 * schedules per day counted from midnight of the application clock, `maxRuns` 1 to 365 (0 = no cap), a title of 60
 * characters, the report channels EMAIL and CHAT, a known IANA zone, a relative wish of at most 7 days, a local time at
 * most 2 minutes in the past, and no schedule administration from inside a scheduled run (an immediate background task
 * stays allowed there).
 *
 * The moment of a one-time task is resolved by the rule object; its own cases (neither field, zero minutes, both
 * fields, the zone of a local time) and the echo texts are in {@see AgentTaskSchedulesMCPPackageNotesTest}, the refusal
 * of an immediate start on a used-up budget pot in {@see AgentTaskSchedulesMCPPackageBudgetPayerTest}.
 */
class AgentTaskSchedulesMCPPackageCreationRulesTest extends UnitTestCase
{
    protected const int ACTING_ACCOUNT_ID = 3;

    protected const int AGENT_SPACE_ID = 17;

    protected const int CONVERSATION_ID = 700300;

    protected const int ROOT_CONVERSATION_ID = 700200;

    /** @var int The schedule whose run a call comes from in the "inside a scheduled run" cases */
    protected const int FIRING_SCHEDULE_ID = 4242;

    protected const string ZONE_OF_CALL = 'Europe/Berlin';

    protected const string CRON = '0 7 * * 1';

    protected const string TASK = 'Collect the open support requests and summarise each with a proposed next step.';

    protected const string LOCAL_TIME = '2099-07-01 12:00';

    protected const string ACTIVE_SCHEDULE_CAP_REFUSAL = 'Task-schedule limit reached (20 active) — ask the user to clean up existing schedules first (getTaskSchedules / deleteTaskSchedule).';

    protected const string MAX_RUNS_REFUSAL = 'maxRuns caps the number of runs and takes 1 to 365. A task that keeps running until the owner stops it has no cap: leave maxRuns out.';

    protected const string SCHEDULED_RUN_REFUSAL_TAIL = ' is not available inside a SCHEDULED RUN: you are the execution of a task schedule #4242 — execute the mission;'
        . ' never create, update or delete schedules from within it. Time or recurrence wording in the mission text is scheduler'
        . ' metadata, already handled. If the mission literally asks for schedule changes, report that to the owner in your final'
        . ' answer instead.';

    // ------------------------------------------------------------------ createRecurringTaskSchedule

    /**
     * @param array<string, mixed> $requestFields the fields of the request that differ from the base request
     * @param array<string, int> $stage `active`, `paused`, `createdToday`
     * @param array<string, mixed> $expectedDifferences the fields of the created schedule that differ from the base schedule
     */
    #[DataProvider('provideRecurringRequestsThatCreate')]
    public function testARecurringRequestCreatesTheScheduleItDescribes(array $requestFields, array $stage, array $expectedDifferences): void
    {
        $schedulesService = static::buildSchedulesService($stage);
        $responseDto = static::buildPackage()->createRecurringTaskSchedule(
            static::buildRecurringRequest($requestFields),
            $schedulesService,
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['createSchedule'], $schedulesService->recordedServiceCalls);
        self::assertSame(
            array_merge(static::describeBaseRecurringSchedule(), $expectedDifferences),
            static::describeSchedule($schedulesService->lastCreatedSchedule)
        );
        self::assertSame(CreationRecordingSchedulesServiceDouble::CREATED_SCHEDULE_ID, $responseDto->scheduleId);
        self::assertSame('Schedule created — confirm to the user in ONE short sentence what will run and when the first firing is.', $responseDto->note);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, int>, 2: array<string, mixed>}>
     */
    public static function provideRecurringRequestsThatCreate(): array
    {
        return [
            'the base request' => [[], [], []],
            '19 active schedules: the 20th is created' => [[], ['active' => 19], []],
            '20 paused schedules do not count' => [[], ['paused' => 20], []],
            '4 firings per day' => [
                ['cronSchedule' => '0 6,10,14,18 * * *', 'timezoneIdentifier' => 'UTC'],
                [],
                ['schedule' => '0 6,10,14,18 * * *', 'timezone' => 'UTC'],
            ],
            // counted over 24 wall-clock hours of the zone: four on any day, the two days of a summer-time change included
            '4 firings per day in a zone with summer time' => [
                ['cronSchedule' => '0 6,10,14,18 * * *', 'timezoneIdentifier' => 'America/New_York'],
                [],
                ['schedule' => '0 6,10,14,18 * * *', 'timezone' => 'America/New_York'],
            ],
            'an unparseable cron passes the frequency floor' => [['cronSchedule' => 'every monday'], [], ['schedule' => 'every monday']],
            'the cron is trimmed' => [['cronSchedule' => ' 0 7 * * 1 '], [], []],
            '4 created today: the 5th is created' => [[], ['createdToday' => 4], []],
            'maxRuns 0 is no cap' => [['maxRuns' => 0], [], []],
            'maxRuns 1' => [['maxRuns' => 1], [], ['maxIterations' => 1]],
            'maxRuns 365' => [['maxRuns' => 365], [], ['maxIterations' => 365]],
            'blank done criteria are none' => [['doneCriteria' => '   '], [], []],
            'done criteria are trimmed' => [['doneCriteria' => ' every request has a next step '], [], ['doneCriteria' => 'every request has a next step']],
            'a title of 60 characters is kept' => [['title' => str_repeat('a', 60)], [], ['title' => str_repeat('a', 60)]],
            'a title of 61 characters is cut to 60' => [['title' => str_repeat('b', 61)], [], ['title' => str_repeat('b', 60)]],
            'report channel chat' => [['reportChannel' => 'chat'], [], ['reportChannel' => AIConversationSchedule::REPORT_CHANNEL_CHAT]],
            'report channel " Email "' => [['reportChannel' => ' Email '], [], []],
            'a zone the request names' => [['timezoneIdentifier' => 'Europe/Vienna'], [], ['timezone' => 'Europe/Vienna']],
            'a blank zone is the zone of the call' => [['timezoneIdentifier' => '  '], [], []],
            'a zone the request names is trimmed' => [['timezoneIdentifier' => ' Europe/Vienna '], [], ['timezone' => 'Europe/Vienna']],
        ];
    }

    /**
     * @param array<string, mixed> $requestFields
     * @param array<string, int> $stage
     */
    #[DataProvider('provideRecurringRequestsThatAreRefused')]
    public function testARecurringRequestIsRefusedAndNothingIsCreated(array $requestFields, array $stage, bool $insideAScheduledRun, string $expectedRefusal): void
    {
        $schedulesService = static::buildSchedulesService($stage);
        try {
            static::buildPackage()->createRecurringTaskSchedule(
                static::buildRecurringRequest($requestFields),
                $schedulesService,
                $insideAScheduledRun ? static::buildProvenanceOfAScheduledRun() : static::buildProvenanceOfAChatTurn()
            );
            self::fail('The request is refused');
        } catch (BadRequestException $badRequestException) {
            self::assertSame($expectedRefusal, $badRequestException->getMessage());
        }
        self::assertSame([], $schedulesService->recordedServiceCalls);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, int>, 2: bool, 3: string}>
     */
    public static function provideRecurringRequestsThatAreRefused(): array
    {
        return [
            '20 active schedules' => [[], ['active' => 20], false, static::ACTIVE_SCHEDULE_CAP_REFUSAL],
            '5 firings per day' => [
                ['cronSchedule' => '0 6,10,14,18,22 * * *', 'timezoneIdentifier' => 'UTC'],
                [],
                false,
                "The cron '0 6,10,14,18,22 * * *' fires 5× per day — at most 4 firings per day are allowed (every firing is a full agent run"
                . ' that costs budget). Choose specific times of day (e.g. "0 8 * * *") or a weekly rhythm, and explain the limit to the user.',
            ],
            'an hourly cron' => [
                ['cronSchedule' => '0 * * * *', 'timezoneIdentifier' => 'UTC'],
                [],
                false,
                "The cron '0 * * * *' fires 24× per day — at most 4 firings per day are allowed (every firing is a full agent run"
                . ' that costs budget). Choose specific times of day (e.g. "0 8 * * *") or a weekly rhythm, and explain the limit to the user.',
            ],
            '5 created today' => [
                [],
                ['createdToday' => 5],
                false,
                'The daily limit of 5 new recurring schedules is reached — tell the user, and offer to set the remaining ones up tomorrow or to'
                . " merge them into one of today's schedules (getTaskSchedules / updateTaskSchedule).",
            ],
            'maxRuns 366' => [['maxRuns' => 366], [], false, static::MAX_RUNS_REFUSAL],
            'maxRuns -1' => [['maxRuns' => -1], [], false, static::MAX_RUNS_REFUSAL],
            'report channel sms' => [['reportChannel' => 'sms'], [], false, "Unknown reportChannel 'SMS' — use EMAIL or CHAT."],
            'an unknown zone' => [['timezoneIdentifier' => 'Mars/Base'], [], false, "Unknown time zone 'Mars/Base' — use an IANA identifier such as Europe/Berlin."],
            'an empty task' => [['task' => '  '], [], false, 'The task mission must not be empty.'],
            'inside a scheduled run' => [[], [], true, "Tool 'createRecurringTaskSchedule'" . static::SCHEDULED_RUN_REFUSAL_TAIL],
        ];
    }

    public function testTheCreationsOfTheDayAreCountedFromMidnightOfTheApplicationClock(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        $midnightBeforeTheCall = (int)strtotime('today');
        static::buildPackage()->createRecurringTaskSchedule(static::buildRecurringRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());
        $midnightAfterTheCall = (int)strtotime('today');

        self::assertCount(1, $schedulesService->creationCountStarts);
        // two values only when the call ran across midnight
        self::assertContains($schedulesService->creationCountStarts[0]->getTimestamp(), [$midnightBeforeTheCall, $midnightAfterTheCall]);
    }

    public function testAZoneOfTheCallThatIsOnlyADefaultMarksTheScheduleUnlessTheRequestNamesAZone(): void
    {
        $package = static::buildPackage();
        $package->fixtureTimezoneOfCallUnverified = true;

        $schedulesService = static::buildSchedulesService([]);
        $package->createRecurringTaskSchedule(static::buildRecurringRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());
        self::assertSame(static::ZONE_OF_CALL, $schedulesService->lastCreatedSchedule->timezone);
        self::assertTrue($schedulesService->lastCreatedSchedule->timezoneUnverified);

        $schedulesService = static::buildSchedulesService([]);
        $package->createRecurringTaskSchedule(static::buildRecurringRequest(['timezoneIdentifier' => 'Europe/Vienna']), $schedulesService, static::buildProvenanceOfAChatTurn());
        self::assertSame('Europe/Vienna', $schedulesService->lastCreatedSchedule->timezone);
        self::assertFalse($schedulesService->lastCreatedSchedule->timezoneUnverified);

        $schedulesService = static::buildSchedulesService([]);
        $package->scheduleOneTimeTask(static::buildOneTimeRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());
        self::assertTrue($schedulesService->lastCreatedSchedule->timezoneUnverified);

        $schedulesService = static::buildSchedulesService([]);
        $package->scheduleOneTimeTask(static::buildOneTimeRequest(['timezoneIdentifier' => 'Europe/Vienna']), $schedulesService, static::buildProvenanceOfAChatTurn());
        self::assertFalse($schedulesService->lastCreatedSchedule->timezoneUnverified);
    }

    public function testWithoutAKnownZoneTheScheduleStoresNoneAndALocalTimeIsReadAsUtc(): void
    {
        $package = static::buildPackage();
        $package->fixtureTimezoneIdentifierOfCall = null;

        $schedulesService = static::buildSchedulesService([]);
        $package->createRecurringTaskSchedule(static::buildRecurringRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());
        self::assertNull($schedulesService->lastCreatedSchedule->timezone);
        self::assertNull($schedulesService->lastCreatedSchedule->timezoneUnverified);

        $schedulesService = static::buildSchedulesService([]);
        $package->scheduleOneTimeTask(static::buildOneTimeRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());
        self::assertNull($schedulesService->lastCreatedSchedule->timezone);
        self::assertSame('2099-07-01 12:00', gmdate('Y-m-d H:i', $schedulesService->lastCreatedSchedule->oneTimeAt->getTimestamp()));
    }

    // ------------------------------------------------------------------ scheduleOneTimeTask

    /**
     * @param array<string, mixed> $requestFields
     */
    #[DataProvider('provideOneTimeRequestsWithALocalTime')]
    public function testAOneTimeRequestCreatesTheScheduleAtTheLocalTimeInItsZone(array $requestFields, string $expectedMomentInUtc, string $expectedZone): void
    {
        $schedulesService = static::buildSchedulesService([]);
        $responseDto = static::buildPackage()->scheduleOneTimeTask(
            static::buildOneTimeRequest($requestFields),
            $schedulesService,
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['createSchedule'], $schedulesService->recordedServiceCalls);
        $createdSchedule = $schedulesService->lastCreatedSchedule;
        self::assertSame(
            array_merge(static::describeBaseRecurringSchedule(), ['schedule' => null, 'timezone' => $expectedZone]),
            static::describeSchedule($createdSchedule)
        );
        self::assertSame($expectedMomentInUtc, gmdate('Y-m-d H:i', $createdSchedule->oneTimeAt->getTimestamp()));
        self::assertSame(CreationRecordingSchedulesServiceDouble::CREATED_SCHEDULE_ID, $responseDto->scheduleId);
        self::assertStringStartsWith('One-time task scheduled for 2099-07-01 12:00 local time', (string)$responseDto->note);
        self::assertStringEndsWith(
            'VERIFY this matches what the user asked (wrong day → fix it with updateTaskSchedule), then confirm in ONE short sentence what runs and when.',
            (string)$responseDto->note
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function provideOneTimeRequestsWithALocalTime(): array
    {
        return [
            'in the zone of the call' => [[], '2099-07-01 10:00', static::ZONE_OF_CALL],
            'in the zone the request names' => [['timezoneIdentifier' => 'America/New_York'], '2099-07-01 16:00', 'America/New_York'],
        ];
    }

    public function testARelativeWishOfSevenDaysIsTheLongestOne(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        $secondsBeforeTheCall = time();
        static::buildPackage()->scheduleOneTimeTask(
            static::buildOneTimeRequest(['runAtLocalTime' => null, 'runInMinutes' => 10080]),
            $schedulesService,
            static::buildProvenanceOfAChatTurn()
        );

        // a wish on the scale of days is moved out of the night: up to a day later, never earlier
        $secondsAhead = $schedulesService->lastCreatedSchedule->oneTimeAt->getTimestamp() - $secondsBeforeTheCall;
        self::assertGreaterThanOrEqual(10080 * 60, $secondsAhead);
        self::assertLessThanOrEqual((10080 + 1440) * 60 + 60, $secondsAhead);
    }

    public function testALocalTimeOneMinuteAgoIsStillTaken(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        static::buildPackage()->scheduleOneTimeTask(
            static::buildOneTimeRequest(['runAtLocalTime' => static::buildLocalTimeInTheZoneOfTheCall('-1 minute')]),
            $schedulesService,
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(['createSchedule'], $schedulesService->recordedServiceCalls);
        $secondsAgo = time() - $schedulesService->lastCreatedSchedule->oneTimeAt->getTimestamp();
        self::assertGreaterThanOrEqual(60, $secondsAgo);
        self::assertLessThanOrEqual(180, $secondsAgo);
    }

    public function testALocalTimeThreeMinutesAgoIsRefusedWithTheCurrentLocalTime(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        $localTimeThreeMinutesAgo = static::buildLocalTimeInTheZoneOfTheCall('-3 minutes');
        try {
            static::buildPackage()->scheduleOneTimeTask(
                static::buildOneTimeRequest(['runAtLocalTime' => $localTimeThreeMinutesAgo]),
                $schedulesService,
                static::buildProvenanceOfAChatTurn()
            );
            self::fail('A local time in the past is refused');
        } catch (BadRequestException $badRequestException) {
            self::assertMatchesRegularExpression(
                '/^' . preg_quote('That local time (' . $localTimeThreeMinutesAgo . ' ' . static::ZONE_OF_CALL . ') is in the past — right NOW it is ', '/')
                . '\d{4}-\d{2}-\d{2} \d{2}:\d{2}'
                . preg_quote(' there; pass a future time or use runInMinutes.', '/') . '$/u',
                $badRequestException->getMessage()
            );
        }
        self::assertSame([], $schedulesService->recordedServiceCalls);
    }

    /**
     * @param array<string, mixed> $requestFields
     * @param array<string, int> $stage
     */
    #[DataProvider('provideOneTimeRequestsThatAreRefused')]
    public function testAOneTimeRequestIsRefusedAndNothingIsCreated(array $requestFields, array $stage, bool $insideAScheduledRun, string $expectedRefusal): void
    {
        $schedulesService = static::buildSchedulesService($stage);
        try {
            static::buildPackage()->scheduleOneTimeTask(
                static::buildOneTimeRequest($requestFields),
                $schedulesService,
                $insideAScheduledRun ? static::buildProvenanceOfAScheduledRun() : static::buildProvenanceOfAChatTurn()
            );
            self::fail('The request is refused');
        } catch (BadRequestException $badRequestException) {
            self::assertSame($expectedRefusal, $badRequestException->getMessage());
        }
        self::assertSame([], $schedulesService->recordedServiceCalls);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, int>, 2: bool, 3: string}>
     */
    public static function provideOneTimeRequestsThatAreRefused(): array
    {
        return [
            'one minute more than seven days' => [
                ['runAtLocalTime' => null, 'runInMinutes' => 10081],
                [],
                false,
                'runInMinutes must be between 1 and 10080 (7 days) — farther ahead, use runAtLocalTime.',
            ],
            'negative minutes alone count as not set' => [
                ['runAtLocalTime' => null, 'runInMinutes' => -1],
                [],
                false,
                'Pass exactly ONE of runInMinutes / runAtLocalTime.',
            ],
            'a local time that is no date and time' => [
                ['runAtLocalTime' => 'tomorrow 15:30'],
                [],
                false,
                'runAtLocalTime must be "YYYY-MM-DD HH:MM" in the ' . AgentApplicationWording::DEFAULT_AGENT_SPACE_CONTEXT_SUBJECT_NAME
                . '\'s local time (e.g. "2026-08-07 15:30").',
            ],
            'an unknown zone' => [['timezoneIdentifier' => 'Mars/Base'], [], false, "Unknown time zone 'Mars/Base' — use an IANA identifier such as Europe/Berlin."],
            '20 active schedules' => [[], ['active' => 20], false, static::ACTIVE_SCHEDULE_CAP_REFUSAL],
            'inside a scheduled run' => [[], [], true, "Tool 'scheduleOneTimeTask'" . static::SCHEDULED_RUN_REFUSAL_TAIL],
        ];
    }

    // ------------------------------------------------------------------ startBackgroundTask

    public function testABackgroundTaskIsCreatedForNowAndStartedAtOnce(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        $secondsBeforeTheCall = time();
        $responseDto = static::buildPackage()->startBackgroundTask(static::buildBackgroundRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());

        self::assertSame(['createSchedule', 'startScheduleNow'], $schedulesService->recordedServiceCalls);
        $createdSchedule = $schedulesService->lastCreatedSchedule;
        // the tool names no zone for a task that starts now
        self::assertSame(
            array_merge(static::describeBaseRecurringSchedule(), ['schedule' => null, 'timezone' => null, 'timezoneUnverified' => null]),
            static::describeSchedule($createdSchedule)
        );
        self::assertGreaterThanOrEqual($secondsBeforeTheCall, $createdSchedule->oneTimeAt->getTimestamp());
        self::assertLessThanOrEqual(time(), $createdSchedule->oneTimeAt->getTimestamp());
        self::assertSame(CreationRecordingSchedulesServiceDouble::CREATED_SCHEDULE_ID, $responseDto->scheduleId);
        self::assertSame(CreationRecordingSchedulesServiceDouble::STARTED_RUN_CONVERSATION_ID, $responseDto->conversationId);
        self::assertSame(
            'Background run started — tell the user in ONE short sentence that the report will arrive by email when the work is done.',
            $responseDto->note
        );
    }

    public function testTheNoteOfAStartedBackgroundTaskNamesTheTaskListForTheChatChannel(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        $responseDto = static::buildPackage()->startBackgroundTask(
            static::buildBackgroundRequest(['reportChannel' => 'chat']),
            $schedulesService,
            static::buildProvenanceOfAChatTurn()
        );

        self::assertSame(AIConversationSchedule::REPORT_CHANNEL_CHAT, $schedulesService->lastCreatedSchedule->reportChannel);
        self::assertSame(
            'Background run started — tell the user in ONE short sentence that the report will arrive in the task list when the work is done.',
            $responseDto->note
        );
    }

    public function testABackgroundTaskStartsInsideAScheduledRunWhereTheSchedulingToolsRefuse(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        $responseDto = static::buildPackage()->startBackgroundTask(static::buildBackgroundRequest([]), $schedulesService, static::buildProvenanceOfAScheduledRun());

        self::assertSame(['createSchedule', 'startScheduleNow'], $schedulesService->recordedServiceCalls);
        self::assertSame(CreationRecordingSchedulesServiceDouble::STARTED_RUN_CONVERSATION_ID, $responseDto->conversationId);
    }

    public function testABackgroundTaskIsRefusedAtTwentyActiveSchedules(): void
    {
        $schedulesService = static::buildSchedulesService(['active' => 20]);
        try {
            static::buildPackage()->startBackgroundTask(static::buildBackgroundRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());
            self::fail('The active-schedule cap refuses the task');
        } catch (BadRequestException $badRequestException) {
            self::assertSame(static::ACTIVE_SCHEDULE_CAP_REFUSAL, $badRequestException->getMessage());
        }
        self::assertSame([], $schedulesService->recordedServiceCalls);
    }

    public function testAnImmediateStartThatThrowsReArmsTheOccurrenceAndSaysTheTaskFiresOnItsOwn(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        $schedulesService->fixtureImmediateStartThrows = true;
        $responseDto = static::buildPackage()->startBackgroundTask(static::buildBackgroundRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());

        self::assertSame(['createSchedule', 'startScheduleNow', 'refundClaimedOccurrence reArmNow'], $schedulesService->recordedServiceCalls);
        self::assertSame(CreationRecordingSchedulesServiceDouble::CREATED_SCHEDULE_ID, $responseDto->scheduleId);
        self::assertNull($responseDto->conversationId);
        self::assertSame('The schedule was created but the immediate start was not possible — the task will fire shortly on its own.', $responseDto->note);
    }

    public function testABudgetPotUsedUpBetweenTheCheckAndTheStartIsToldAsNotStarted(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        $schedulesService->fixtureImmediateStartFindsThePotUsedUp = true;
        $responseDto = static::buildPackage()->startBackgroundTask(static::buildBackgroundRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());

        self::assertSame(['createSchedule', 'startScheduleNow'], $schedulesService->recordedServiceCalls);
        self::assertNull($responseDto->conversationId);
        self::assertSame(
            'The task could not start: the automation budget of this period is used up — scheduled and background tasks pause until it'
            . ' renews; the chat keeps working. Tell the user in plain words and offer to handle anything urgent DIRECTLY in this chat.',
            $responseDto->note
        );
    }

    // ------------------------------------------------------------------ the three tools

    public function testTheScheduleIsStoredForTheSpaceOfTheCallElseForTheActingAccount(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        static::buildPackage()->createRecurringTaskSchedule(static::buildRecurringRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());
        self::assertSame(
            ['schedules of account 3', 'creations of account 3', 'monthly cost of account 3'],
            $schedulesService->scopeReads
        );

        $package = static::buildPackage();
        $package->fixtureAgentSpace = new AgentSpace();
        $package->fixtureAgentSpace->id = static::AGENT_SPACE_ID;
        $schedulesService = static::buildSchedulesService([]);
        $package->createRecurringTaskSchedule(static::buildRecurringRequest([]), $schedulesService, static::buildProvenanceOfAChatTurn());
        self::assertSame(
            ['schedules of space 17', 'creations of space 17', 'monthly cost of space 17'],
            $schedulesService->scopeReads
        );
        self::assertSame(
            array_merge(static::describeBaseRecurringSchedule(), ['accountId' => null, 'agentSpaceId' => static::AGENT_SPACE_ID]),
            static::describeSchedule($schedulesService->lastCreatedSchedule)
        );
    }

    public function testTheCreatingConversationIsTheRootConversationOfTheCallElseItsOwn(): void
    {
        $schedulesService = static::buildSchedulesService([]);
        static::buildPackage()->createRecurringTaskSchedule(
            static::buildRecurringRequest([]),
            $schedulesService,
            new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID, rootConversationId: static::ROOT_CONVERSATION_ID)
        );
        self::assertSame(static::ROOT_CONVERSATION_ID, $schedulesService->lastCreatedSchedule->createdByConversationId);

        $schedulesService = static::buildSchedulesService([]);
        static::buildPackage()->createRecurringTaskSchedule(static::buildRecurringRequest([]), $schedulesService, new AgentToolCallProvenance());
        self::assertFalse(isset($schedulesService->lastCreatedSchedule->createdByConversationId));
    }

    // ------------------------------------------------------------------ the stage

    protected static function buildPackage(): AgentTaskSchedulesMCPPackageWithCreationStageDouble
    {
        $package = new AgentTaskSchedulesMCPPackageWithCreationStageDouble();
        $package->fixtureActingAccount = new Account();
        $package->fixtureActingAccount->id = static::ACTING_ACCOUNT_ID;
        return $package;
    }

    /**
     * @param array<string, int> $stage `active` and `paused`: schedules the scope already has; `createdToday`: recurring
     *                                  schedules the agent created in it since the start of the creation day
     */
    protected static function buildSchedulesService(array $stage): CreationRecordingSchedulesServiceDouble
    {
        $schedulesService = new CreationRecordingSchedulesServiceDouble();
        $schedulesService->fixtureActiveScheduleCount = $stage['active'] ?? 0;
        $schedulesService->fixturePausedScheduleCount = $stage['paused'] ?? 0;
        $schedulesService->fixtureRecurringSchedulesCreatedToday = $stage['createdToday'] ?? 0;
        return $schedulesService;
    }

    protected static function buildProvenanceOfAChatTurn(): AgentToolCallProvenance
    {
        return new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID);
    }

    protected static function buildProvenanceOfAScheduledRun(): AgentToolCallProvenance
    {
        return new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID, scheduleId: static::FIRING_SCHEDULE_ID);
    }

    /**
     * @param array<string, mixed> $requestFields the fields that differ from the base request (the task and a weekly cron)
     */
    protected static function buildRecurringRequest(array $requestFields): RecurringTaskScheduleCreateRequestDto
    {
        $requestDto = new RecurringTaskScheduleCreateRequestDto();
        $requestDto->task = static::TASK;
        $requestDto->cronSchedule = static::CRON;
        foreach ($requestFields as $fieldName => $fieldValue) {
            $requestDto->{$fieldName} = $fieldValue;
        }
        return $requestDto;
    }

    /**
     * @param array<string, mixed> $requestFields the fields that differ from the base request (the task and a local time in 2099)
     */
    protected static function buildOneTimeRequest(array $requestFields): OneTimeTaskScheduleCreateRequestDto
    {
        $requestDto = new OneTimeTaskScheduleCreateRequestDto();
        $requestDto->task = static::TASK;
        $requestDto->runAtLocalTime = static::LOCAL_TIME;
        foreach ($requestFields as $fieldName => $fieldValue) {
            $requestDto->{$fieldName} = $fieldValue;
        }
        return $requestDto;
    }

    /**
     * @param array<string, mixed> $requestFields the fields that differ from the base request (the task)
     */
    protected static function buildBackgroundRequest(array $requestFields): BackgroundTaskStartRequestDto
    {
        $requestDto = new BackgroundTaskStartRequestDto();
        $requestDto->task = static::TASK;
        foreach ($requestFields as $fieldName => $fieldValue) {
            $requestDto->{$fieldName} = $fieldValue;
        }
        return $requestDto;
    }

    /** A moment relative to the clock as the local time a model writes it, in the zone of the call */
    protected static function buildLocalTimeInTheZoneOfTheCall(string $relativeToNow): string
    {
        return (new \DateTimeImmutable($relativeToNow))->setTimezone(new \DateTimeZone(static::ZONE_OF_CALL))->format('Y-m-d H:i');
    }

    /**
     * The schedule of the base recurring request: an agent's schedule of the acting account, created by the call's
     * conversation, reporting by email, in the zone of the call.
     *
     * @return array<string, mixed>
     */
    protected static function describeBaseRecurringSchedule(): array
    {
        return [
            'initiatorType' => AIConversationSchedule::INITIATOR_TYPE_AGENT,
            'createdByConversationId' => static::CONVERSATION_ID,
            'accountId' => static::ACTING_ACCOUNT_ID,
            'agentSpaceId' => null,
            'task' => static::TASK,
            'title' => null,
            'schedule' => static::CRON,
            'reportChannel' => AIConversationSchedule::REPORT_CHANNEL_EMAIL,
            'maxIterations' => null,
            'doneCriteria' => null,
            'timezone' => static::ZONE_OF_CALL,
            'timezoneUnverified' => false,
        ];
    }

    /**
     * What the tool set on the schedule it handed to the service (the moment of a one-time schedule is asserted by its test).
     *
     * @return array<string, mixed>
     */
    protected static function describeSchedule(?AIConversationSchedule $schedule): array
    {
        self::assertNotNull($schedule, 'The tool handed a schedule to the service');
        return [
            'initiatorType' => $schedule->initiatorType,
            'createdByConversationId' => $schedule->createdByConversationId ?? null,
            'accountId' => $schedule->accountId ?? null,
            'agentSpaceId' => $schedule->agentSpaceId ?? null,
            'task' => $schedule->task ?? null,
            'title' => $schedule->title ?? null,
            'schedule' => $schedule->schedule ?? null,
            'reportChannel' => $schedule->reportChannel,
            'maxIterations' => $schedule->maxIterations ?? null,
            'doneCriteria' => $schedule->doneCriteria ?? null,
            'timezone' => $schedule->timezone,
            'timezoneUnverified' => $schedule->timezoneUnverified,
        ];
    }
}
