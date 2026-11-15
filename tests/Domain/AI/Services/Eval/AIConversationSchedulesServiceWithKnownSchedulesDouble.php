<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedules;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;

/** The schedules seam of a teardown test: the space's schedules are set by the test, the deletions are recorded. */
class AIConversationSchedulesServiceWithKnownSchedulesDouble extends AIConversationSchedulesService
{
    /** @var AIConversationSchedule[] The schedules of the space */
    public array $knownSchedules = [];

    /** @var int[] The ids of the deleted schedules, in order */
    public array $deletedScheduleIds = [];

    /** @var string|null Thrown by the read when set */
    public ?string $readErrorMessage = null;

    public function findSchedulesOfAgentSpace(AgentSpace $agentSpace, bool $includeArchived = false): AIConversationSchedules
    {
        if ($this->readErrorMessage !== null) {
            throw new \RuntimeException($this->readErrorMessage);
        }
        $aIConversationSchedules = new AIConversationSchedules();
        foreach ($this->knownSchedules as $knownSchedule) {
            $aIConversationSchedules->add($knownSchedule);
        }
        return $aIConversationSchedules;
    }

    public function deleteScheduleFollowingArchivePolicy(AIConversationSchedule $schedule): string
    {
        $this->deletedScheduleIds[] = (int)$schedule->id;
        return 'deleted';
    }
}
