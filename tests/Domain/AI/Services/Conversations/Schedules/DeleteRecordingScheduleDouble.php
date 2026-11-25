<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;

/** A schedule that records, instead of deleting its row, whether the entity rights applied to the delete */
class DeleteRecordingScheduleDouble extends AIConversationSchedule
{
    public ?bool $rightsAppliedOnDelete = null;

    public function delete(): void
    {
        $this->rightsAppliedOnDelete = DatabaseRepoEntity::getApplyRightsRestrictions();
    }
}
