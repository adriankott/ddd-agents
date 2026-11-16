<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The plan snapshots the messages of one conversation stamped ({@see AIConversationRunReportTaskPlanSnapshot}), one per
 * message index.
 *
 * @property AIConversationRunReportTaskPlanSnapshot[] $elements
 * @method AIConversationRunReportTaskPlanSnapshot[] getElements()
 * @method AIConversationRunReportTaskPlanSnapshot|null first()
 * @method AIConversationRunReportTaskPlanSnapshot|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportTaskPlanSnapshots extends ObjectSet
{
    /** The snapshot of the message `$messageIndex`; null when it stamped none. */
    public function findByMessageIndex(int $messageIndex): ?AIConversationRunReportTaskPlanSnapshot
    {
        $aIConversationRunReportTaskPlanSnapshot = $this->getByUniqueKey(AIConversationRunReportTaskPlanSnapshot::uniqueKeyForMessageIndex($messageIndex));
        return $aIConversationRunReportTaskPlanSnapshot instanceof AIConversationRunReportTaskPlanSnapshot ? $aIConversationRunReportTaskPlanSnapshot : null;
    }
}
