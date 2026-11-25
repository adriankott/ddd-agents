<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Presentation\Mcp\AI\TaskScheduleRecordingWritesDouble;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the end of a run writes on the schedule that started it
 * ({@see AIConversationSchedulesService::recordRunTerminalOfConversation()}): the failure streak, the close of a
 * one-time task after its single run, the close by done criteria, and the notice to the owner. The bookkeeping reads
 * the schedule without the entity rights and never lets an error out: it runs on the turn path.
 *
 * The verdict on done criteria and its guards are in {@see AIConversationSchedulesServiceDoneCriteriaTest}.
 */
class AIConversationSchedulesServiceRunTerminalTest extends UnitTestCase
{
    protected const int SCHEDULE_ID = 4430;

    protected const int RUN_CONVERSATION_ID = 881234;

    protected const string LAST_FAILED_AT = '2026-11-20 08:00:00';

    protected const string LAST_ASSISTANT_TEXT = 'All twelve requests have an answer now.';

    protected function tearDown(): void
    {
        // a case that fails inside the bookkeeping must not leave the rights off for the next test
        DatabaseRepoEntity::setApplyRightsRestrictions(true);
        parent::tearDown();
    }

    public function testAConversationThatIsNoRunOfAScheduleChangesNothing(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $conversation = new RunConversationWithScriptedLastAssistantTextDouble();
        $conversation->id = static::RUN_CONVERSATION_ID;

        $schedulesService->recordRunTerminalOfConversation($conversation, true);

        self::assertSame([], $schedulesService->recordedLookups);
        self::assertSame([], $schedulesService->recordedNotices);
    }

    public function testACompletedRunClearsTheFailureStreakAndTellsTheOwner(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedule = static::buildRecurringSchedule(failureStreak: 3);
        $schedulesService->fixtureSchedule = $schedule;

        $schedulesService->recordRunTerminalOfConversation(static::buildRunConversation(), true);

        self::assertSame(['4430, read fresh'], $schedulesService->recordedLookups);
        self::assertFalse($schedulesService->rightsAppliedOnLookup, 'the worker runs as the customer account: the schedule is read without the entity rights');
        self::assertTrue(DatabaseRepoEntity::getApplyRightsRestrictions(), 'the rights are back on');
        self::assertSame(['failureStreak,lastFailedAt'], $schedule->recordedWrites);
        self::assertSame(0, $schedule->failureStreak);
        self::assertSame((new DateTime(static::LAST_FAILED_AT))->getTimestamp(), $schedule->lastFailedAt->getTimestamp(), 'the moment of the last failure stays');
        self::assertSame(['completed'], $schedulesService->recordedNotices);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        self::assertSame([], $schedulesService->judgedRunOutcomeTexts, 'a schedule without done criteria is not judged');
    }

    public function testAFailedRunCountsOnTheFailureStreakAndTellsTheOwner(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedule = static::buildRecurringSchedule(failureStreak: 3);
        $schedule->doneCriteria = 'Every request has an answer.';
        $schedulesService->fixtureSchedule = $schedule;
        $schedulesService->fixtureVerdict = true;

        $schedulesService->recordRunTerminalOfConversation(static::buildRunConversation(), false);

        self::assertSame(['failureStreak,lastFailedAt'], $schedule->recordedWrites);
        self::assertSame(4, $schedule->failureStreak);
        self::assertEqualsWithDelta(time(), $schedule->lastFailedAt->getTimestamp(), 5);
        self::assertSame(['failed'], $schedulesService->recordedNotices);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
        self::assertSame([], $schedulesService->judgedRunOutcomeTexts, 'a failed run is not judged against the done criteria');
    }

    #[DataProvider('provideOutcomesOfTheRun')]
    public function testTheSingleRunOfAOneTimeTaskClosesItWhateverTheOutcome(bool $runCompleted, string $expectedNotice): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedule = static::buildOneTimeSchedule(AIConversationScheduleStatus::ACTIVE);
        $schedulesService->fixtureSchedule = $schedule;

        $schedulesService->recordRunTerminalOfConversation(static::buildRunConversation(), $runCompleted);

        self::assertSame(['failureStreak,lastFailedAt', 'status'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::DONE, $schedule->status);
        self::assertSame([$expectedNotice], $schedulesService->recordedNotices);
    }

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function provideOutcomesOfTheRun(): array
    {
        return [
            'the run completed' => [true, 'completed'],
            'the run failed' => [false, 'failed'],
        ];
    }

    #[DataProvider('provideStatusesAOneTimeTaskKeeps')]
    public function testAOneTimeTaskThatIsNoLongerActiveKeepsItsStatus(string $status): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedule = static::buildOneTimeSchedule($status);
        $schedulesService->fixtureSchedule = $schedule;

        $schedulesService->recordRunTerminalOfConversation(static::buildRunConversation(), true);

        self::assertSame(['failureStreak,lastFailedAt'], $schedule->recordedWrites);
        self::assertSame($status, $schedule->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideStatusesAOneTimeTaskKeeps(): array
    {
        return [
            // a pause or a cancel between the fire and the end of the run stands
            'paused meanwhile' => [AIConversationScheduleStatus::PAUSED],
            'cancelled meanwhile' => [AIConversationScheduleStatus::CANCELLED],
            'expired' => [AIConversationScheduleStatus::EXPIRED],
        ];
    }

    public function testACompletedRunThatFulfilsTheDoneCriteriaClosesARecurringSchedule(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedule = static::buildRecurringSchedule(failureStreak: 0);
        $schedule->doneCriteria = 'Every request has an answer.';
        $schedulesService->fixtureSchedule = $schedule;
        $schedulesService->fixtureVerdict = true;

        $schedulesService->recordRunTerminalOfConversation(static::buildRunConversation(), true);

        self::assertSame([static::LAST_ASSISTANT_TEXT], $schedulesService->judgedRunOutcomeTexts, 'the last assistant text of the run is what is judged');
        self::assertSame(['failureStreak,lastFailedAt', 'status'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::DONE, $schedule->status);
    }

    /**
     * @param bool|null $verdict null: the judgment call failed
     */
    #[DataProvider('provideVerdictsThatKeepTheScheduleRunning')]
    public function testARunThatDoesNotFulfilTheDoneCriteriaLeavesTheScheduleActive(?bool $verdict): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedule = static::buildRecurringSchedule(failureStreak: 0);
        $schedule->doneCriteria = 'Every request has an answer.';
        $schedulesService->fixtureSchedule = $schedule;
        $schedulesService->fixtureVerdict = $verdict;

        $schedulesService->recordRunTerminalOfConversation(static::buildRunConversation(), true);

        self::assertSame([static::LAST_ASSISTANT_TEXT], $schedulesService->judgedRunOutcomeTexts);
        self::assertSame(['failureStreak,lastFailedAt'], $schedule->recordedWrites);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
    }

    /**
     * @return array<string, array{0: bool|null}>
     */
    public static function provideVerdictsThatKeepTheScheduleRunning(): array
    {
        return [
            'not fulfilled' => [false],
            'the judgment call failed' => [null],
        ];
    }

    public function testARunThatLeftNoAssistantTextIsNotJudged(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedule = static::buildRecurringSchedule(failureStreak: 0);
        $schedule->doneCriteria = 'Every request has an answer.';
        $schedulesService->fixtureSchedule = $schedule;
        $schedulesService->fixtureVerdict = true;
        $conversation = static::buildRunConversation();
        $conversation->fixtureLastAssistantText = null;

        $schedulesService->recordRunTerminalOfConversation($conversation, true);

        self::assertSame([], $schedulesService->judgedRunOutcomeTexts);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
    }

    public function testARunOfAScheduleThatNoLongerExistsChangesNothing(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedulesService->fixtureSchedule = null;

        $schedulesService->recordRunTerminalOfConversation(static::buildRunConversation(), true);

        self::assertSame(['4430, read fresh'], $schedulesService->recordedLookups);
        self::assertSame([], $schedulesService->recordedNotices);
        self::assertTrue(DatabaseRepoEntity::getApplyRightsRestrictions());
    }

    public function testANoticeThatFailsDoesNotStopTheBookkeeping(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedulesService->fixtureNoticeThrows = true;
        $schedule = static::buildOneTimeSchedule(AIConversationScheduleStatus::ACTIVE);
        $schedulesService->fixtureSchedule = $schedule;

        $schedulesService->recordRunTerminalOfConversation(static::buildRunConversation(), true);

        self::assertSame(['completed'], $schedulesService->recordedNotices);
        self::assertSame(['failureStreak,lastFailedAt', 'status'], $schedule->recordedWrites, 'the streak is stored and the one-time task is closed all the same');
        self::assertSame(AIConversationScheduleStatus::DONE, $schedule->status);
    }

    public function testAnErrorOfTheBookkeepingNeverReachesTheTurn(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedRunTerminalDouble();
        $schedulesService->fixtureLookupThrows = true;

        $schedulesService->recordRunTerminalOfConversation(static::buildRunConversation(), true);

        self::assertSame(['4430, read fresh'], $schedulesService->recordedLookups);
        self::assertTrue(DatabaseRepoEntity::getApplyRightsRestrictions(), 'the rights are back on after the error');
    }

    public function testTheBookkeepingOfAScheduleThatIsNotStoredWritesNothing(): void
    {
        $schedule = new TaskScheduleRecordingWritesDouble();
        $schedule->failureStreak = 2;

        (new SchedulesServiceWithScriptedRunTerminalDouble())->recordRunTerminal($schedule, false);

        self::assertSame([], $schedule->recordedWrites);
        self::assertSame(2, $schedule->failureStreak);
    }

    // ------------------------------------------------------------------ the stage

    protected static function buildRunConversation(): RunConversationWithScriptedLastAssistantTextDouble
    {
        $conversation = new RunConversationWithScriptedLastAssistantTextDouble();
        $conversation->id = static::RUN_CONVERSATION_ID;
        $conversation->scheduleId = static::SCHEDULE_ID;
        $conversation->fixtureLastAssistantText = static::LAST_ASSISTANT_TEXT;
        return $conversation;
    }

    /** An ACTIVE recurring schedule that fired, with the given streak of failed runs. */
    protected static function buildRecurringSchedule(int $failureStreak): TaskScheduleRecordingWritesDouble
    {
        $schedule = new TaskScheduleRecordingWritesDouble();
        $schedule->id = static::SCHEDULE_ID;
        $schedule->status = AIConversationScheduleStatus::ACTIVE;
        $schedule->schedule = '0 7 * * 1';
        $schedule->task = 'Answer the open support requests.';
        $schedule->iterationsUsed = 4;
        $schedule->failureStreak = $failureStreak;
        $schedule->lastFailedAt = new DateTime(static::LAST_FAILED_AT);
        return $schedule;
    }

    /** A one-time task whose single occurrence fired. */
    protected static function buildOneTimeSchedule(string $status): TaskScheduleRecordingWritesDouble
    {
        $schedule = new TaskScheduleRecordingWritesDouble();
        $schedule->id = static::SCHEDULE_ID;
        $schedule->status = $status;
        $schedule->task = 'Answer the open support requests.';
        $schedule->maxIterations = 1;
        $schedule->iterationsUsed = 1;
        $schedule->oneTimeAt = new DateTime('2026-11-20 07:00:00');
        $schedule->lastFailedAt = new DateTime(static::LAST_FAILED_AT);
        return $schedule;
    }
}
