<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The normalized snapshot of a recorded run: per section (the conversations, messages, delegation batches and
 * compaction nodes of the run's tree, then the dispatched messages — {@see AgentRecordedRunComparison}) the rows as
 * compared, their ids replaced by placeholders. A recording stores it; a replay builds its own and compares the two.
 * The rows are the stored document of the section, kept as they are decoded.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunSnapshot extends ValueObject
{
    /** @var array<string, array<int, array<string, mixed>>> Section → its normalized rows, the stored document */
    protected array $rowsBySection = [];

    /**
     * @param array<string, array<int, array<string, mixed>>> $rowsBySection the sections as stored in a recording, or
     *        as {@see AgentRecordedRunComparison::buildTreeSnapshot()} and
     *        {@see AgentRecordedRunComparison::buildDispatchSnapshot()} build them
     * @return static
     */
    public static function fromRowsBySection(array $rowsBySection): static
    {
        $agentRecordedRunSnapshot = static::newInstance();
        $agentRecordedRunSnapshot->rowsBySection = $rowsBySection;
        return $agentRecordedRunSnapshot;
    }

    /**
     * @return string[] the section names, in stored order
     */
    public function getSectionNames(): array
    {
        return array_map('strval', array_keys($this->rowsBySection));
    }

    /**
     * @return int[] section → its row count, in stored order
     */
    public function countRowsBySection(): array
    {
        $rowCountsBySection = [];
        foreach ($this->rowsBySection as $section => $sectionRows) {
            $rowCountsBySection[(string)$section] = count((array)$sectionRows);
        }
        return $rowCountsBySection;
    }

    /**
     * The snapshot as stored in the recording and as {@see AgentRecordedRunComparison::buildComparison()} compares it.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function toArray(): array
    {
        return $this->rowsBySection;
    }
}
