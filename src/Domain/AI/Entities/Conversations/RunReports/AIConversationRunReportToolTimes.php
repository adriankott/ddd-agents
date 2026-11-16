<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The times of the tools of a run ({@see AIConversationRunReportToolTime}), one per tool, in the order the tools were
 * first called until {@see sortBySecondsDescending()} orders them.
 *
 * @property AIConversationRunReportToolTime[] $elements
 * @method AIConversationRunReportToolTime[] getElements()
 * @method AIConversationRunReportToolTime|null first()
 * @method AIConversationRunReportToolTime|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportToolTimes extends ObjectSet
{
    /**
     * Counts one call of a tool, adding the tool's time when it is the tool's first call.
     *
     * @param string $toolName
     * @param float $durationSeconds
     * @return void
     */
    public function addCallOfTool(string $toolName, float $durationSeconds): void
    {
        $toolTime = $this->getByUniqueKey(AIConversationRunReportToolTime::uniqueKeyForToolName($toolName));
        if ($toolTime === null) {
            $toolTime = AIConversationRunReportToolTime::forTool($toolName);
            $this->add($toolTime);
        }
        $toolTime->addCall($durationSeconds);
    }

    /**
     * Orders the tools by their summed seconds, the slowest first; tools of equal seconds keep their order.
     *
     * @return void
     */
    public function sortBySecondsDescending(): void
    {
        $this->sort(static fn (AIConversationRunReportToolTime $first, AIConversationRunReportToolTime $second): int => $second->seconds <=> $first->seconds);
    }
}
