<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TaskPlanRecord;

use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService;

/** The task-plan record service that counts how often a call reads the tick judgement (the purpose's done criteria). */
class TaskPlanRecordServiceCountingJudgementReadsDouble extends AIConversationTaskPlanRecordService
{
    public int $judgementReadCount = 0;

    public function findDoneCriteriaForStatusTick(AIConversation $conversation): TaskPlanDoneCriteria
    {
        $this->judgementReadCount++;
        return parent::findDoneCriteriaForStatusTick($conversation);
    }
}
