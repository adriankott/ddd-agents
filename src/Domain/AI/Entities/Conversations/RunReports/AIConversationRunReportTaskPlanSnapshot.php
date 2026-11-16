<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The plan snapshot one message of a conversation stamped (the message's `taskPlanSnapshot` column): the plan in force
 * after that message. The decoded column is kept as stored and read as a plan ({@see buildTaskPlan()}).
 *
 * The unique key of a snapshot is its message index.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportTaskPlanSnapshot extends ValueObject
{
    /** @var int The index of the message in its conversation */
    public int $messageIndex = 0;

    /** @var array The decoded `taskPlanSnapshot` column, in the plan's repository form */
    protected array $snapshotFields = [];

    /**
     * The snapshot of the message `$messageIndex` from its column value: the JSON text or the array it decodes to;
     * null when the value holds no plan.
     */
    public static function fromColumnValue(int $messageIndex, mixed $columnValue): ?static
    {
        if (is_string($columnValue) && $columnValue !== '') {
            $columnValue = json_decode($columnValue, true);
        }
        if (!is_array($columnValue)) {
            return null;
        }
        $aIConversationRunReportTaskPlanSnapshot = static::newInstance();
        $aIConversationRunReportTaskPlanSnapshot->messageIndex = $messageIndex;
        $aIConversationRunReportTaskPlanSnapshot->snapshotFields = $columnValue;
        return $aIConversationRunReportTaskPlanSnapshot;
    }

    /** The plan the snapshot holds. */
    public function buildTaskPlan(): TaskPlan
    {
        return TaskPlan::fromRepositoryArray($this->snapshotFields);
    }

    /**
     * The decoded column as stored.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->snapshotFields;
    }

    /** The key of the snapshot of the message `$messageIndex` in its set. */
    public static function uniqueKeyForMessageIndex(int $messageIndex): string
    {
        return self::class . '_' . $messageIndex;
    }

    /** The message index. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyForMessageIndex($this->messageIndex);
    }
}
