<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleStatus;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Agents\Presentation\Mcp\AI\TaskScheduleRecordingWritesDouble;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A fire of a schedule outside its cron: the run-now of a person ({@see AIConversationSchedulesService::assertScheduleMayRunNow()},
 * {@see AIConversationSchedulesService::fireScheduleNow()}), the repeated fire of an eval schedule
 * ({@see AIConversationSchedulesService::fireEvalScheduleNow()}) and the first firing of a task created to run at once
 * ({@see AIConversationSchedulesService::startScheduleNow()}). Which refusal is told with which words, and what an
 * allowed fire does: it moves the anchor to now, claims the occurrence and spawns the run, in this order.
 *
 * Whether a fire is allowed is the answer of the rule object
 * ({@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\AIConversationScheduleTransitionTest}); what the spawn does
 * is in {@see AIConversationSchedulesServiceOccurrenceSpawnTest}.
 */
class AIConversationSchedulesServiceManualFireTest extends UnitTestCase
{
    protected const int SCHEDULE_ID = 4430;

    protected const string STATUS_REFUSAL_HEAD = 'Schedule 4430 cannot be fired in status ';

    protected const string STATUS_REFUSAL_TAIL = ' — only an ACTIVE schedule fires.';

    protected const string ONE_TIME_REFUSAL = 'Schedule 4430 is a one-time task — it fires once at its planned moment; firing it now'
        . ' would consume that single occurrence early. Move the moment via the edit endpoint instead.';

    protected const string EXPIRED_REFUSAL = 'Schedule 4430 has expired (ttl or maximum runs reached) — it no longer fires.';

    // ------------------------------------------------------------------ the run-now of a person: the words of a refusal

    /**
     * @param int|null $secondsSinceTheLastFiring null: it never fired
     */
    #[DataProvider('provideSchedulesThatMayRunNow')]
    public function testAnActiveRecurringScheduleMayRunNow(?int $secondsSinceTheLastFiring): void
    {
        $schedule = static::buildScheduleOfFixture($secondsSinceTheLastFiring === null ? [] : ['secondsSinceTheLastFiring' => $secondsSinceTheLastFiring]);

        (new AIConversationSchedulesService())->assertScheduleMayRunNow($schedule);

        self::assertSame([], $schedule->recordedWrites, 'the guard writes nothing');
    }

    /**
     * @return array<string, array{0: int|null}>
     */
    public static function provideSchedulesThatMayRunNow(): array
    {
        return [
            'it never fired' => [null],
            'it fired an hour and a minute ago' => [3660],
            'it fired a day ago' => [86400],
        ];
    }

    /**
     * @param array<string, mixed> $fixture {@see buildScheduleOfFixture()}
     */
    #[DataProvider('provideSchedulesThatMayNotRunNow')]
    public function testARunNowIsRefusedWithTheRuleThatRefuses(array $fixture, string $expectedRefusal): void
    {
        $schedule = static::buildScheduleOfFixture($fixture);
        try {
            (new AIConversationSchedulesService())->assertScheduleMayRunNow($schedule);
            self::fail('The run-now is refused');
        } catch (BadRequestException $badRequestException) {
            self::assertSame($expectedRefusal, $badRequestException->getMessage());
        }
        self::assertSame([], $schedule->recordedWrites);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function provideSchedulesThatMayNotRunNow(): array
    {
        $cases = [];
        foreach ([
            AIConversationScheduleStatus::PAUSED,
            AIConversationScheduleStatus::EXPIRED,
            AIConversationScheduleStatus::DONE,
            AIConversationScheduleStatus::CANCELLED,
            AIConversationScheduleStatus::ARCHIVED,
        ] as $status) {
            $cases['a recurring schedule, ' . $status] = [['status' => $status], self::STATUS_REFUSAL_HEAD . $status . self::STATUS_REFUSAL_TAIL];
        }
        return $cases + [
            'a one-time task' => [['oneTimeMoment' => '2099-07-01 10:00:00'], self::ONE_TIME_REFUSAL],
            // the kind is told before the status
            'a paused one-time task' => [['oneTimeMoment' => '2099-07-01 10:00:00', 'status' => AIConversationScheduleStatus::PAUSED], self::ONE_TIME_REFUSAL],
            'an active schedule whose lifetime is over' => [['lifetimeIsOver' => true], self::EXPIRED_REFUSAL],
            'an active schedule whose runs are used up' => [['runsAreUsedUp' => true, 'secondsSinceTheLastFiring' => 86400], self::EXPIRED_REFUSAL],
            // the expiry is told before the hour to the last firing
            'an expired schedule that fired ten minutes ago' => [['lifetimeIsOver' => true, 'secondsSinceTheLastFiring' => 600], self::EXPIRED_REFUSAL],
            'it fired ten minutes ago' => [
                ['secondsSinceTheLastFiring' => 600],
                'Schedule 4430 fired within the last hour — manual fire is limited to once per hour; try again in 50 minutes.',
            ],
            // the rest of the hour is told in whole minutes, rounded up
            'it fired 59 and a half minutes ago' => [
                ['secondsSinceTheLastFiring' => 3570],
                'Schedule 4430 fired within the last hour — manual fire is limited to once per hour; try again in 1 minutes.',
            ],
            'it fired this very second' => [
                ['secondsSinceTheLastFiring' => 0],
                'Schedule 4430 fired within the last hour — manual fire is limited to once per hour; try again in 60 minutes.',
            ],
        ];
    }

    public function testAScheduleThatIsNotStoredIsToldWithoutAnId(): void
    {
        $schedule = static::buildScheduleOfFixture(['status' => AIConversationScheduleStatus::PAUSED, 'notStored' => true]);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Schedule ? cannot be fired in status PAUSED — only an ACTIVE schedule fires.');
        (new AIConversationSchedulesService())->assertScheduleMayRunNow($schedule);
    }

    // ------------------------------------------------------------------ the run-now of a person: the fire

    public function testAFireNowMovesTheAnchorToNowThenClaimsThenSpawns(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedManualFireDouble();
        $schedule = static::buildScheduleOfFixture(['secondsSinceTheLastFiring' => 86400]);

        $runConversation = $schedulesService->fireScheduleNow($schedule);

        self::assertSame(SchedulesServiceWithScriptedManualFireDouble::STARTED_RUN_CONVERSATION_ID, $runConversation?->id);
        self::assertSame(['nextRunAt'], $schedule->recordedWrites, 'the anchor is stored before the claim compares it');
        self::assertSame(['claim', 'spawn'], $schedulesService->recordedCalls);
        self::assertEqualsWithDelta(time(), $schedulesService->anchorAtTheClaim, 5);
        self::assertSame(AIConversationScheduleStatus::ACTIVE, $schedule->status);
    }

    public function testAFireNowWhoseClaimIsLostStartsNoRun(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedManualFireDouble();
        $schedulesService->fixtureClaimIsWon = false;
        $schedule = static::buildScheduleOfFixture([]);

        self::assertNull($schedulesService->fireScheduleNow($schedule));
        self::assertSame(['claim'], $schedulesService->recordedCalls);
        self::assertSame(['nextRunAt'], $schedule->recordedWrites);
    }

    public function testAFireNowWhoseOccurrenceIsSkippedAnswersNoRun(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedManualFireDouble();
        $schedulesService->fixtureSpawnSkipReason = AIConversationSchedulesService::OCCURRENCE_SKIP_REASON_BUDGET_POT_EXHAUSTED;
        $schedule = static::buildScheduleOfFixture([]);

        self::assertNull($schedulesService->fireScheduleNow($schedule));
        self::assertSame(['claim', 'spawn'], $schedulesService->recordedCalls);
    }

    /**
     * @param array<string, mixed> $fixture {@see buildScheduleOfFixture()}
     */
    #[DataProvider('provideSchedulesAFireNowLeavesAlone')]
    public function testAFireNowThatTheRuleRefusesWritesAndClaimsNothing(array $fixture): void
    {
        $schedulesService = new SchedulesServiceWithScriptedManualFireDouble();
        $schedule = static::buildScheduleOfFixture($fixture);

        self::assertNull($schedulesService->fireScheduleNow($schedule));
        self::assertSame([], $schedulesService->recordedCalls);
        self::assertSame([], $schedule->recordedWrites);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function provideSchedulesAFireNowLeavesAlone(): array
    {
        return [
            'a paused schedule' => [['status' => AIConversationScheduleStatus::PAUSED]],
            'a one-time task' => [['oneTimeMoment' => '2099-07-01 10:00:00']],
            'a schedule that fired ten minutes ago' => [['secondsSinceTheLastFiring' => 600]],
            'a schedule whose lifetime is over' => [['lifetimeIsOver' => true]],
            'a schedule that is not stored' => [['notStored' => true]],
        ];
    }

    // ------------------------------------------------------------------ the repeated fire of an eval schedule

    public function testAnEvalScheduleFiresAgainWithinTheHour(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedManualFireDouble();
        $schedule = static::buildScheduleOfFixture(['secondsSinceTheLastFiring' => 60, 'evalRun' => true]);

        $runConversation = $schedulesService->fireEvalScheduleNow($schedule);

        self::assertSame(SchedulesServiceWithScriptedManualFireDouble::STARTED_RUN_CONVERSATION_ID, $runConversation?->id);
        self::assertSame(['nextRunAt'], $schedule->recordedWrites);
        self::assertSame(['claim', 'spawn'], $schedulesService->recordedCalls);
    }

    /**
     * @param array<string, mixed> $fixture {@see buildScheduleOfFixture()}
     */
    #[DataProvider('provideSchedulesThatAreNoEvalSchedule')]
    public function testOnlyAnEvalScheduleFiresWithoutTheHour(array $fixture): void
    {
        $schedulesService = new SchedulesServiceWithScriptedManualFireDouble();
        $schedule = static::buildScheduleOfFixture($fixture);
        try {
            $schedulesService->fireEvalScheduleNow($schedule);
            self::fail('A schedule that is no eval schedule is refused');
        } catch (BadRequestException $badRequestException) {
            self::assertSame('Only an eval schedule (runSettings.evalRun) fires without the manual fire interval.', $badRequestException->getMessage());
        }
        self::assertSame([], $schedulesService->recordedCalls);
        self::assertSame([], $schedule->recordedWrites);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function provideSchedulesThatAreNoEvalSchedule(): array
    {
        return [
            'no run settings' => [[]],
            'run settings without the marker' => [['evalRun' => null]],
            'the marker off' => [['evalRun' => false]],
            'an eval schedule that is not stored' => [['evalRun' => true, 'notStored' => true]],
        ];
    }

    /**
     * The eval fire bypasses only the hour to the last firing; every other rule of a manual fire is checked before
     * anything is written, so a refused eval schedule keeps its fire anchor.
     *
     * @param array<string, mixed> $fixture {@see buildScheduleOfFixture()}
     */
    #[DataProvider('provideEvalSchedulesTheRunNowRuleRefuses')]
    public function testAnEvalScheduleTheRunNowRuleRefusesKeepsItsAnchorAndClaimsNothing(array $fixture, string $expectedRefusal): void
    {
        $schedulesService = new SchedulesServiceWithScriptedManualFireDouble();
        $schedule = static::buildScheduleOfFixture($fixture + ['evalRun' => true]);
        $anchorBefore = $schedule->nextRunAt;
        try {
            $schedulesService->fireEvalScheduleNow($schedule);
            self::fail('The eval fire is refused');
        } catch (BadRequestException $badRequestException) {
            self::assertSame($expectedRefusal, $badRequestException->getMessage());
        }
        self::assertSame([], $schedule->recordedWrites, 'the anchor is not moved before the claim would be lost');
        self::assertSame($anchorBefore, $schedule->nextRunAt);
        self::assertSame([], $schedulesService->recordedCalls);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function provideEvalSchedulesTheRunNowRuleRefuses(): array
    {
        return [
            'a paused eval schedule' => [['status' => AIConversationScheduleStatus::PAUSED], self::STATUS_REFUSAL_HEAD . 'PAUSED' . self::STATUS_REFUSAL_TAIL],
            'a cancelled eval schedule' => [['status' => AIConversationScheduleStatus::CANCELLED], self::STATUS_REFUSAL_HEAD . 'CANCELLED' . self::STATUS_REFUSAL_TAIL],
            'a paused eval schedule that fired a minute ago' => [
                ['status' => AIConversationScheduleStatus::PAUSED, 'secondsSinceTheLastFiring' => 60],
                self::STATUS_REFUSAL_HEAD . 'PAUSED' . self::STATUS_REFUSAL_TAIL,
            ],
            'an eval schedule whose lifetime is over' => [['lifetimeIsOver' => true], self::EXPIRED_REFUSAL],
        ];
    }

    // ------------------------------------------------------------------ the first firing of a task created to run at once

    public function testATaskCreatedForNowIsStartedAtOnce(): void
    {
        $schedulesService = new SchedulesServiceWithScriptedManualFireDouble();
        $schedule = static::buildScheduleOfFixture(['oneTimeMoment' => 'now']);

        $runConversation = $schedulesService->startScheduleNow($schedule);

        self::assertSame(SchedulesServiceWithScriptedManualFireDouble::STARTED_RUN_CONVERSATION_ID, $runConversation?->id);
        self::assertSame(['nextRunAt'], $schedule->recordedWrites);
        self::assertSame(['claim', 'spawn'], $schedulesService->recordedCalls);
        self::assertEqualsWithDelta(time(), $schedulesService->anchorAtTheClaim, 5);
    }

    /**
     * @param array<string, mixed> $fixture {@see buildScheduleOfFixture()}
     */
    #[DataProvider('provideSchedulesThatAreNotStartedAtOnce')]
    public function testAStartThatTheRuleRefusesWritesAndClaimsNothing(array $fixture): void
    {
        $schedulesService = new SchedulesServiceWithScriptedManualFireDouble();
        $schedule = static::buildScheduleOfFixture($fixture);

        self::assertNull($schedulesService->startScheduleNow($schedule));
        self::assertSame([], $schedulesService->recordedCalls);
        self::assertSame([], $schedule->recordedWrites);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function provideSchedulesThatAreNotStartedAtOnce(): array
    {
        return [
            'a paused task' => [['oneTimeMoment' => 'now', 'status' => AIConversationScheduleStatus::PAUSED]],
            'a task whose moment lies ahead' => [['oneTimeMoment' => '2099-07-01 10:00:00']],
            'a task that fired already' => [['oneTimeMoment' => 'now', 'firedOnce' => true]],
            'a recurring schedule' => [[]],
            'a task that is not stored' => [['oneTimeMoment' => 'now', 'notStored' => true]],
        ];
    }

    // ------------------------------------------------------------------ the stage

    /**
     * A schedule from the fields that differ from an ACTIVE, stored, recurring schedule that never fired. Built inside
     * the test, because some fields count from the clock:
     * `status`; `oneTimeMoment` (a one-time task at that moment, as the clock reads it); `secondsSinceTheLastFiring`;
     * `lifetimeIsOver`; `runsAreUsedUp`; `firedOnce`; `evalRun` (run settings with the marker set to the value);
     * `notStored` (no id).
     *
     * @param array<string, mixed> $fixture
     */
    protected static function buildScheduleOfFixture(array $fixture): TaskScheduleRecordingWritesDouble
    {
        $schedule = new TaskScheduleRecordingWritesDouble();
        if (($fixture['notStored'] ?? false) !== true) {
            $schedule->id = static::SCHEDULE_ID;
        }
        $schedule->status = $fixture['status'] ?? AIConversationScheduleStatus::ACTIVE;
        if (isset($fixture['oneTimeMoment'])) {
            $schedule->maxIterations = 1;
            $schedule->oneTimeAt = new DateTime($fixture['oneTimeMoment']);
            $schedule->nextRunAt = new DateTime($fixture['oneTimeMoment']);
        } else {
            $schedule->schedule = '0 7 * * 1';
            $schedule->nextRunAt = new DateTime('2099-07-06 05:00:00');
        }
        if (isset($fixture['secondsSinceTheLastFiring'])) {
            $schedule->iterationsUsed = 1;
            $schedule->lastFiredAt = DateTime::fromTimestamp(time() - $fixture['secondsSinceTheLastFiring']);
        }
        if (($fixture['lifetimeIsOver'] ?? false) === true) {
            $schedule->ttlAt = new DateTime('2020-01-01 00:00:00');
        }
        if (($fixture['runsAreUsedUp'] ?? false) === true) {
            $schedule->maxIterations = 3;
            $schedule->iterationsUsed = 3;
        }
        if (($fixture['firedOnce'] ?? false) === true) {
            $schedule->iterationsUsed = 1;
        }
        if (array_key_exists('evalRun', $fixture)) {
            $schedule->runSettings = new RunSettings();
            $schedule->runSettings->evalRun = $fixture['evalRun'];
        }
        return $schedule;
    }
}
