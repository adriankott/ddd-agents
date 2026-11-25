<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleOccurrenceSpawn;
use RuntimeException;

/**
 * The scripted schedules service of the fire command with three more paths: an id the due scan names and no row is
 * found for, a claim another process won, and a spawn that throws.
 */
class SchedulesServiceWithScriptedFirePathsDouble extends SchedulesServiceWithOwnSkipWordsDouble
{
    public const string SPAWN_FAILURE_MESSAGE = 'The run could not be started.';

    /** @var int[] Ids the due scan names although their row is gone */
    public array $dueScheduleIdsWithoutARow = [];

    /** @var int[] Schedules whose occurrence another process claimed first */
    public array $scheduleIdsWhoseClaimIsLost = [];

    /** @var int[] Schedules whose spawn throws */
    public array $scheduleIdsWhoseSpawnThrows = [];

    public function findDueScheduleIds(int $limit = self::DUE_SCHEDULES_BATCH_LIMIT): array
    {
        $dueScheduleIds = array_merge($this->dueScheduleIdsWithoutARow, array_keys($this->dueSchedulesById));
        sort($dueScheduleIds);
        return array_slice($dueScheduleIds, 0, $limit);
    }

    public function claimFireOccurrence(AIConversationSchedule $schedule): bool
    {
        if (in_array((int)$schedule->id, $this->scheduleIdsWhoseClaimIsLost, true)) {
            return false;
        }
        return parent::claimFireOccurrence($schedule);
    }

    public function spawnRunConversationForClaimedSchedule(AIConversationSchedule $schedule): AIConversationScheduleOccurrenceSpawn
    {
        if (in_array((int)$schedule->id, $this->scheduleIdsWhoseSpawnThrows, true)) {
            $this->spawnedScheduleIds[] = (int)$schedule->id;
            throw new RuntimeException(static::SPAWN_FAILURE_MESSAGE);
        }
        return parent::spawnRunConversationForClaimedSchedule($schedule);
    }
}
