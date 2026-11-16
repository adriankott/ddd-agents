<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The message rows of a run in index order ({@see AIConversationRunReportMessageRow}), and why their plan snapshots
 * could not be read when the read failed.
 *
 * @property AIConversationRunReportMessageRow[] $elements
 * @method AIConversationRunReportMessageRow[] getElements()
 * @method AIConversationRunReportMessageRow|null first()
 * @method AIConversationRunReportMessageRow|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportMessageRows extends ObjectSet
{
    /** @var string|null The class of the exception the read of the plan snapshots ended in; null when it worked or was not tried */
    public ?string $taskPlanSnapshotReadFailure = null;

    /**
     * The rows of the metric rows `$rowArrays` (each as the metrics statement returned it), in their order.
     *
     * @param array $rowArrays the metric rows
     * @return static
     */
    public static function fromRowArrays(array $rowArrays): static
    {
        $aIConversationRunReportMessageRows = static::newInstance();
        foreach ($rowArrays as $rowFields) {
            $aIConversationRunReportMessageRow = AIConversationRunReportMessageRow::fromRowFields((array)$rowFields);
            $aIConversationRunReportMessageRows->add($aIConversationRunReportMessageRow);
        }
        return $aIConversationRunReportMessageRows;
    }

    /** Gives every row its snapshot from `$aIConversationRunReportTaskPlanSnapshots`, or none when its message stamped none. */
    public function attachTaskPlanSnapshots(AIConversationRunReportTaskPlanSnapshots $aIConversationRunReportTaskPlanSnapshots): void
    {
        foreach ($this->getElements() as $aIConversationRunReportMessageRow) {
            $aIConversationRunReportMessageRow->taskPlanSnapshot = $aIConversationRunReportTaskPlanSnapshots->findByMessageIndex($aIConversationRunReportMessageRow->getMessageIndex());
        }
    }

    /** The tool-call rows, in order. */
    public function buildToolCallRows(): static
    {
        $toolCallRows = static::newInstance();
        foreach ($this->getElements() as $aIConversationRunReportMessageRow) {
            if ($aIConversationRunReportMessageRow->isToolCall()) {
                $toolCallRows->add($aIConversationRunReportMessageRow);
            }
        }
        return $toolCallRows;
    }

    /** The cost of every row's model call in USD. */
    public function sumCostInUSD(): float
    {
        return array_sum(array_map(static fn (AIConversationRunReportMessageRow $aIConversationRunReportMessageRow): float => $aIConversationRunReportMessageRow->getCostInUSD(), $this->getElements()));
    }

    /** The tool of the first tool-call row after the message `$messageIndex`; empty when no tool call follows. */
    public function getToolCalledAfterMessage(int $messageIndex): string
    {
        foreach ($this->getElements() as $aIConversationRunReportMessageRow) {
            if ($aIConversationRunReportMessageRow->getMessageIndexOrZero() > $messageIndex && $aIConversationRunReportMessageRow->isToolCall()) {
                return $aIConversationRunReportMessageRow->getToolName();
            }
        }
        return '';
    }

    /**
     * The rows as the metrics read them ({@see \DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics::buildFromRows()}).
     *
     * @return array the list of metric rows
     */
    public function toArrays(): array
    {
        return array_map(static fn (AIConversationRunReportMessageRow $aIConversationRunReportMessageRow): array => $aIConversationRunReportMessageRow->toArray(), $this->getElements());
    }
}
