<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\SeedAccounts;

use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountResetReport;

/**
 * The report of an application that removes leftover drafts before the conversation purge and notes after it.
 */
class AgentEvalSeedAccountResetReportOfAnApplication extends AgentEvalSeedAccountResetReport
{
    /** @var int[] */
    public array $deletedDraftIds = [];

    public int $wipedNotes = 0;

    public function buildLinesBeforePurge(): array
    {
        return $this->deletedDraftIds === [] ? [] : ['deleted ' . count($this->deletedDraftIds) . ' leftover draft(s): ' . implode(', ', $this->deletedDraftIds)];
    }

    public function buildLinesAfterPurge(): array
    {
        return $this->wipedNotes > 0 ? ["wiped {$this->wipedNotes} notes"] : [];
    }
}
