<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\TaskPlanRecord;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;

/** A task-plan item of an application that declares one channel a seed may gate on: the calendar. */
class TaskPlanItemWithACalendarChannel extends TaskPlanItem
{
    public const array CONNECTED_CHANNEL_LABELS = ['calendar' => 'the calendar'];
}
