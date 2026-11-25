<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TaskPlanRecord;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService;

/** The task-plan record service whose conversations are judged by the purpose the test hands it, without a registry. */
class TaskPlanRecordServiceWithAGivenPurposeDouble extends AIConversationTaskPlanRecordService
{
    public ?AIConversationPurpose $purposeJudgingTicks = null;

    protected function findPurposeJudgingTicksOfConversation(AIConversation $conversation): ?AIConversationPurpose
    {
        return $this->purposeJudgingTicks;
    }
}
