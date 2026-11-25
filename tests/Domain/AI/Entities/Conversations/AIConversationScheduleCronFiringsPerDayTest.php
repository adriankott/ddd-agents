<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleCreationLimits;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The frequency floor counts the firings of a cron within 24 WALL-CLOCK hours in the schedule's zone: a cron with four
 * fixed times of day counts four at every hour of the two days of a summer-time change, as on a normal day (counted in
 * elapsed hours, the eve of the change to summer time counted five in the hour before the evening firing).
 */
class AIConversationScheduleCronFiringsPerDayTest extends UnitTestCase
{
    protected const string FOUR_FIXED_TIMES_OF_DAY = '0 6,10,14,18 * * *';

    protected const string FIVE_FIXED_TIMES_OF_DAY = '0 6,10,14,18,22 * * *';

    protected function buildSchedule(string $cronExpression, string $timezoneIdentifier): AIConversationSchedule
    {
        $schedule = self::instantiateWithoutConstructor(AIConversationSchedule::class);
        $schedule->schedule = $cronExpression;
        $schedule->timezone = $timezoneIdentifier;
        return $schedule;
    }

    protected static function buildMomentFromLocalTime(string $localTime, string $timezoneIdentifier): DateTime
    {
        return DateTime::fromTimestamp((new \DateTimeImmutable($localTime, new \DateTimeZone($timezoneIdentifier)))->getTimestamp());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideMomentsAroundTheSummerTimeChanges(): array
    {
        return [
            'Berlin, a normal day' => ['Europe/Berlin', '2027-03-20 17:30'],
            'Berlin, eve of the change to summer time, before the evening firing' => ['Europe/Berlin', '2027-03-27 16:30'],
            'Berlin, eve of the change to summer time, the hour that counted five' => ['Europe/Berlin', '2027-03-27 17:30'],
            'Berlin, eve of the change to summer time, after the evening firing' => ['Europe/Berlin', '2027-03-27 18:30'],
            'Berlin, the day of the change to summer time' => ['Europe/Berlin', '2027-03-28 01:30'],
            'Berlin, eve of the change back' => ['Europe/Berlin', '2027-10-30 17:30'],
            'Berlin, eve of the change back, the hour before the evening firing' => ['Europe/Berlin', '2027-10-30 18:30'],
            'Berlin, the day of the change back' => ['Europe/Berlin', '2027-10-31 01:30'],
            'New York, a normal day' => ['America/New_York', '2027-03-06 17:30'],
            'New York, eve of the change to summer time, the hour before the evening firing' => ['America/New_York', '2027-03-13 17:30'],
            'New York, the day of the change to summer time' => ['America/New_York', '2027-03-14 01:30'],
            'New York, eve of the change back' => ['America/New_York', '2027-11-06 17:30'],
            'New York, the day of the change back' => ['America/New_York', '2027-11-07 01:30'],
        ];
    }

    #[DataProvider('provideMomentsAroundTheSummerTimeChanges')]
    public function testFourFixedTimesOfDayCountFourAndPassTheFloor(string $timezoneIdentifier, string $localTime): void
    {
        $cronFiringsPerDay = AIConversationScheduleCreationLimits::countCronFiringsPerDay(
            $this->buildSchedule(static::FOUR_FIXED_TIMES_OF_DAY, $timezoneIdentifier),
            static::buildMomentFromLocalTime($localTime, $timezoneIdentifier)
        );

        self::assertSame(4, $cronFiringsPerDay);
        self::assertFalse(AIConversationScheduleCreationLimits::exceedsCronFiringsPerDay($cronFiringsPerDay));
    }

    #[DataProvider('provideMomentsAroundTheSummerTimeChanges')]
    public function testFiveFixedTimesOfDayCountFiveAndAreRefused(string $timezoneIdentifier, string $localTime): void
    {
        $cronFiringsPerDay = AIConversationScheduleCreationLimits::countCronFiringsPerDay(
            $this->buildSchedule(static::FIVE_FIXED_TIMES_OF_DAY, $timezoneIdentifier),
            static::buildMomentFromLocalTime($localTime, $timezoneIdentifier)
        );

        self::assertSame(5, $cronFiringsPerDay);
        self::assertTrue(AIConversationScheduleCreationLimits::exceedsCronFiringsPerDay($cronFiringsPerDay));
    }

    public function testTheWindowIsTwentyFourWallClockHoursAndTwentyThreeOrTwentyFiveElapsedOnTheChangeDays(): void
    {
        $schedule = $this->buildSchedule(static::FOUR_FIXED_TIMES_OF_DAY, 'Europe/Berlin');
        $windowCases = [
            ['2027-03-20 17:30', 24],
            ['2027-03-27 17:30', 23],
            ['2027-10-30 17:30', 25],
        ];
        foreach ($windowCases as [$localTime, $expectedElapsedHours]) {
            $from = static::buildMomentFromLocalTime($localTime, 'Europe/Berlin');
            $windowEnd = $schedule->computeWallClockWindowEnd($from, 1);
            self::assertSame($expectedElapsedHours * 3600, $windowEnd->getTimestamp() - $from->getTimestamp(), $localTime);
            self::assertSame(substr($localTime, 11), $windowEnd->format('H:i'), $localTime);
        }
    }

    public function testAScheduleWithoutZoneCountsInUtc(): void
    {
        $schedule = self::instantiateWithoutConstructor(AIConversationSchedule::class);
        $schedule->schedule = static::FOUR_FIXED_TIMES_OF_DAY;
        $schedule->timezone = null;

        self::assertSame(4, AIConversationScheduleCreationLimits::countCronFiringsPerDay($schedule, static::buildMomentFromLocalTime('2027-03-27 17:30', 'UTC')));
    }

    public function testAnHourlyCronCountsTheHoursOfTheWallClockDay(): void
    {
        $hourlySchedule = $this->buildSchedule('0 * * * *', 'Europe/Berlin');

        self::assertSame(24, AIConversationScheduleCreationLimits::countCronFiringsPerDay($hourlySchedule, static::buildMomentFromLocalTime('2027-03-20 17:30', 'Europe/Berlin')));
        // 23 elapsed hours: 02:00 does not exist on the day of the change
        self::assertSame(23, AIConversationScheduleCreationLimits::countCronFiringsPerDay($hourlySchedule, static::buildMomentFromLocalTime('2027-03-27 17:30', 'Europe/Berlin')));
    }
}
