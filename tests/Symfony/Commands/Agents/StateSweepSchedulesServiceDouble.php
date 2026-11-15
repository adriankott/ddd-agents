<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleTransition;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\DBEntity;
use RuntimeException;

/**
 * The schedules service of the state sweep, answering from scripted rows: each candidate's sweep answers the
 * transition the test scripted (none = untouched) or throws. Records the cap and every sweep with its dry-run flag and
 * whether the entity rights restrictions applied during it.
 */
class StateSweepSchedulesServiceDouble extends AIConversationSchedulesService
{
    public const string FAILURE_MESSAGE = 'the schedule row is locked';

    /** @var AIConversationSchedule[] The candidates, by id */
    public array $schedulesById = [];

    /** @var array<int, AIConversationScheduleTransition|null> What the sweep of a schedule answers, by schedule id */
    public array $transitionsByScheduleId = [];

    /** @var int[] The schedules whose sweep throws */
    public array $failingScheduleIds = [];

    /** @var string[] The sweeps asked, as `<schedule id>:dry|real` */
    public array $sweptSchedules = [];

    /** @var int[] The caps asked */
    public array $askedLimits = [];

    /** @var bool[] Whether the entity rights restrictions applied, one per sweep */
    public array $rightsAppliedDuringSweeps = [];

    public function findScheduleIdsForAutomationStateSweep(int $limit = self::STATE_SWEEP_BATCH_LIMIT): array
    {
        $this->askedLimits[] = $limit;
        return array_keys($this->schedulesById);
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->schedulesById[(int)$entityId] ?? null;
    }

    public function applyAutomationStateSweepToSchedule(AIConversationSchedule $schedule, bool $dryRun = false): ?AIConversationScheduleTransition
    {
        $this->sweptSchedules[] = $schedule->id . ':' . ($dryRun ? 'dry' : 'real');
        $this->rightsAppliedDuringSweeps[] = DBEntity::getApplyRightsRestrictions();
        if (in_array((int)$schedule->id, $this->failingScheduleIds, true)) {
            throw new RuntimeException(self::FAILURE_MESSAGE);
        }
        return $this->transitionsByScheduleId[(int)$schedule->id] ?? null;
    }
}
