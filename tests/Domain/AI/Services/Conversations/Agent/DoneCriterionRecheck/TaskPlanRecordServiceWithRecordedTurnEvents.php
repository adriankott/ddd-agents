<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DoneCriterionRecheck;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;

/** The task-plan record service whose turn events go to a {@see RecordingTurnEventsService}. */
class TaskPlanRecordServiceWithRecordedTurnEvents extends AIConversationTaskPlanRecordService
{
    public RecordingTurnEventsService $recordingTurnEventsService;

    public function __construct()
    {
        $this->recordingTurnEventsService = new RecordingTurnEventsService();
    }

    /**
     * @param string[]|null $onlyDoneCriterionKeys
     * @return string[]
     */
    public function findStatusesOfCriterionLeaves(TaskPlanItems $taskPlanItems, ?array $onlyDoneCriterionKeys): array
    {
        return $this->findDoneCriterionLeafStatusesByItemKey($taskPlanItems, $onlyDoneCriterionKeys);
    }

    /** @param string[] $criterionLeafStatusesBefore */
    public function recordRecheck(AIConversation $conversation, TaskPlanItems $taskPlanItems, array $criterionLeafStatusesBefore, ?string $completedToolName, int $resolvedLeafCount): void
    {
        $this->recordDoneCriterionRecheckTurnEvent(AgentRun::forTurnEntry(), $conversation, $taskPlanItems, $criterionLeafStatusesBefore, $completedToolName, $resolvedLeafCount);
    }

    protected function turnEventsService(): AIConversationTurnEventsService
    {
        return $this->recordingTurnEventsService;
    }
}
