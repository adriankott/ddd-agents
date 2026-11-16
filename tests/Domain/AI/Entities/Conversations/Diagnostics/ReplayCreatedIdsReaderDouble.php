<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunCreatedIdsReaderInterface;
use LogicException;

/**
 * The replay's created ids as a test hands them in, with the number of reads; on demand a read fails (an answer that
 * must not read the database).
 */
class ReplayCreatedIdsReaderDouble implements AgentRecordedRunCreatedIdsReaderInterface
{
    /** @var array<string, int[]> table → the ids the reads answer */
    public array $createdIdsByTable = [];

    /** How many times the ids were read. */
    public int $readCount = 0;

    /** A read throws. */
    public bool $readFails = false;

    /**
     * @param array<string, int[]> $createdIdsByTable
     * @return static
     */
    public static function answering(array $createdIdsByTable): static
    {
        $replayCreatedIdsReaderDouble = new static();
        $replayCreatedIdsReaderDouble->createdIdsByTable = $createdIdsByTable;
        return $replayCreatedIdsReaderDouble;
    }

    /** @return static a reader whose read fails */
    public static function failingWhenRead(): static
    {
        $replayCreatedIdsReaderDouble = new static();
        $replayCreatedIdsReaderDouble->readFails = true;
        return $replayCreatedIdsReaderDouble;
    }

    public function findReplayCreatedIdsByTable(): array
    {
        $this->readCount++;
        if ($this->readFails) {
            throw new LogicException('no candidate — the database is not read');
        }
        return $this->createdIdsByTable;
    }
}
