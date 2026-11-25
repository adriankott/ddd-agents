<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;

/** A schedules service that answers one fixture schedule and records whether the entity rights applied to the read */
class RightsRecordingSchedulesServiceDouble extends AIConversationSchedulesService
{
    public ?AIConversationSchedule $fixtureSchedule = null;

    public ?bool $rightsAppliedOnFind = null;

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $this->rightsAppliedOnFind = DatabaseRepoEntity::getApplyRightsRestrictions();
        return $this->fixtureSchedule;
    }
}
