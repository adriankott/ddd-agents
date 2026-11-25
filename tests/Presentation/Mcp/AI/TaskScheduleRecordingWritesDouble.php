<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;

/**
 * A stored schedule that records the writes asked of it instead of reaching a database: the column names of each
 * targeted write, and its delete, in order.
 */
class TaskScheduleRecordingWritesDouble extends AIConversationSchedule
{
    /** @var string[] The writes in order: the column names of a targeted write joined by a comma, `delete` for a delete */
    public array $recordedWrites = [];

    public function updatePartialIgnoringRights(string ...$propertyNames): static
    {
        $this->recordedWrites[] = implode(',', $propertyNames);
        return $this;
    }

    public function delete(): void
    {
        $this->recordedWrites[] = 'delete';
    }
}
