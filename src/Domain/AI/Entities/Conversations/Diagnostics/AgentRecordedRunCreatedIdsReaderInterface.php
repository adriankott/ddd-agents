<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

/**
 * Reads the ids a replay has created so far, for the translation of a played answer
 * ({@see AgentRecordedRunPlayedAnswerIdTranslation::translateResponseBody()}). The translation asks only when an answer
 * carries a recorded created id, and at most once per answer.
 */
interface AgentRecordedRunCreatedIdsReaderInterface
{
    /**
     * The ids the replay created so far, per table, in the tables the recording created rows in.
     *
     * @return array<string, int[]> table → ids
     */
    public function findReplayCreatedIdsByTable(): array;
}
