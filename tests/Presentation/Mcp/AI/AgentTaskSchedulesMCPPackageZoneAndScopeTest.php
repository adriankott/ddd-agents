<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskScheduleUpdateRequestDto;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * updateTaskSchedule on a schedule stored without a zone: only a cron edit stores the zone of its space; a new
 * one-time moment is read in that zone without storing it; any other edit leaves zone and next firing alone. And the
 * single-schedule lookup follows the scope rule of the lists (read without the entity rights, then the scope decides).
 */
class AgentTaskSchedulesMCPPackageZoneAndScopeTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testATitleOnlyEditLeavesTheZoneAndTheNextFiringOfAZonelessScheduleAlone(): void
    {
        $schedule = static::buildRecurringScheduleWithoutZone();
        $nextRunAtBefore = $schedule->nextRunAt;
        $requestDto = new TaskScheduleUpdateRequestDto();
        $requestDto->title = 'Weekly check';

        $editedColumnNames = (new AgentTaskSchedulesMCPPackageWithFixtureZoneDouble())->applyTimingEditsToScheduleForTest($schedule, $requestDto, new DateTime('2026-11-25 10:00:00'));

        self::assertSame([], $editedColumnNames);
        self::assertNull($schedule->timezone, 'the zone is stored only with a cron edit');
        self::assertSame($nextRunAtBefore, $schedule->nextRunAt);
        self::assertSame('0 8 * * 1', $schedule->schedule);
    }

    public function testACronEditStoresTheZoneOfTheSpace(): void
    {
        $schedule = static::buildRecurringScheduleWithoutZone();
        $requestDto = new TaskScheduleUpdateRequestDto();
        $requestDto->cronSchedule = '0 9 * * 1';

        $editedColumnNames = (new AgentTaskSchedulesMCPPackageWithFixtureZoneDouble())->applyTimingEditsToScheduleForTest($schedule, $requestDto, new DateTime('2026-11-25 10:00:00'));

        self::assertSame(['timezone', 'timezoneUnverified', 'schedule'], $editedColumnNames);
        self::assertSame('Europe/Berlin', $schedule->timezone);
        self::assertSame('0 9 * * 1', $schedule->schedule);
    }

    public function testAMomentEditIsReadInTheZoneOfTheSpaceWithoutStoringIt(): void
    {
        $schedule = new AIConversationSchedule();
        $schedule->id = 7;
        $schedule->timezone = null;
        $schedule->oneTimeAt = new DateTime('2026-12-01 08:00:00');
        $requestDto = new TaskScheduleUpdateRequestDto();
        $requestDto->runAtLocalTime = '2026-12-02 16:00';

        $editedColumnNames = (new AgentTaskSchedulesMCPPackageWithFixtureZoneDouble())->applyTimingEditsToScheduleForTest($schedule, $requestDto, new DateTime('2026-11-25 10:00:00'));

        self::assertSame(['oneTimeAt'], $editedColumnNames);
        self::assertNull($schedule->timezone);
        self::assertSame('2026-12-02 15:00:00', (new \DateTimeImmutable('@' . $schedule->oneTimeAt->getTimestamp()))->format('Y-m-d H:i:s'), '16:00 in Berlin is 15:00 UTC');
    }

    public function testTheSingleScheduleLookupReadsWithoutEntityRightsAndTheScopeDecides(): void
    {
        $schedulesService = new RightsRecordingSchedulesServiceDouble();
        $schedule = new AIConversationSchedule();
        $schedule->id = 9;
        $schedule->agentSpaceId = null;
        $schedule->accountId = 3;
        $schedulesService->fixtureSchedule = $schedule;
        $package = new AgentTaskSchedulesMCPPackageWithFixtureZoneDouble();

        self::assertSame($schedule, $package->findScheduleByIdForScopeCheckForTest(9, $schedulesService));
        self::assertFalse($schedulesService->rightsAppliedOnFind, 'read like the lists: without the entity rights');
        self::assertTrue(DatabaseRepoEntity::getApplyRightsRestrictions(), 'the rights are back on after the read');

        $owner = new Account();
        $owner->id = 3;
        $otherAccount = new Account();
        $otherAccount->id = 4;
        self::assertTrue($package->isScheduleInScopeOfAccountForTest($schedule, $owner));
        self::assertFalse($package->isScheduleInScopeOfAccountForTest($schedule, $otherAccount));
    }

    protected static function buildRecurringScheduleWithoutZone(): AIConversationSchedule
    {
        $schedule = new AIConversationSchedule();
        $schedule->id = 5;
        $schedule->timezone = null;
        $schedule->schedule = '0 8 * * 1';
        $schedule->nextRunAt = new DateTime('2026-11-30 08:00:00');
        return $schedule;
    }
}
