<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The hard delete of a schedule that never ran follows the scope the caller decided, like the status changes: it runs
 * without the entity delete rights, and the rights are back on afterwards.
 */
class ScheduleHardDeleteWithoutEntityRightsTest extends UnitTestCase
{
    public function testAScheduleThatNeverRanIsDeletedWithoutTheEntityRights(): void
    {
        $schedule = new DeleteRecordingScheduleDouble();
        $schedule->id = 12;
        $schedule->schedule = '0 8 * * 1';
        $schedule->nextRunAt = new DateTime('2026-11-30 08:00:00');
        $schedule->lastFiredAt = null;

        $deleteAction = (new AIConversationSchedulesService())->deleteScheduleFollowingArchivePolicy($schedule);

        self::assertSame(AIConversationSchedule::DELETE_ACTION_HARD_DELETE, $deleteAction);
        self::assertFalse($schedule->rightsAppliedOnDelete);
        self::assertTrue(DatabaseRepoEntity::getApplyRightsRestrictions(), 'the rights are back on');
    }
}
