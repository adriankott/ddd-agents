<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TaskPlanRecord;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;

/** The conversations service that records every task-plan slice it is asked to persist instead of writing it. */
class ConversationsServiceRecordingTaskPlanSlicesDouble extends AIConversationsService
{
    /** @var array<int, array{conversation: AIConversation, taskPlan: ?TaskPlan, runToken: ?string}> */
    public array $persistedTaskPlanSlices = [];

    public function persistTaskPlanSlice(AIConversation $conversation, ?TaskPlan $taskPlan, ?string $runToken): void
    {
        $this->persistedTaskPlanSlices[] = ['conversation' => $conversation, 'taskPlan' => $taskPlan, 'runToken' => $runToken];
    }
}
