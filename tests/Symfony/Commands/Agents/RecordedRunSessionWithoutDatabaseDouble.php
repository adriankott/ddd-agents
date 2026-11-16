<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunCreatedIdRead;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSnapshot;

/**
 * The package's session with its two database reads replaced: the high-water marks are none, and the snapshot of a
 * run holds its root conversation (normalized) and no dispatched message, with a created-id read whose one table went
 * through the shared account column. Every recorder, the message queue and the comparison are the real ones.
 */
class RecordedRunSessionWithoutDatabaseDouble extends AgentRecordedRunSession
{
    /** The table the created-id read of the double reports as read through the account column. */
    public const string SHARED_SCOPE_TABLE = 'agentNotes';

    public function readHighWaterMarks(): void
    {
        $this->idNormalization ??= AgentRecordedRunIdNormalization::withHighWaterMarks([], $this->getAgentRecordedRunsService()->buildTableByFamily());
    }

    protected function buildSnapshotOfRun(AIConversation $rootConversation, array $scopeIdsByName, array $otherRunScopeIdsByName): AgentRecordedRunSnapshot
    {
        $idNormalization = $this->requireIdNormalization();
        $idNormalization->addScopeIds($scopeIdsByName);
        $idNormalization->addScopeIds($otherRunScopeIdsByName);
        $this->createdIdRead = AgentRecordedRunCreatedIdRead::fromStoredRead([
            'idsByTable' => [],
            'scopeColumnByTable' => [self::SHARED_SCOPE_TABLE => 'accountId'],
            'unscopedTables' => [],
            'cappedTables' => [],
            'sharedScopeTables' => $this->findSharedScopeTables([self::SHARED_SCOPE_TABLE => 'accountId']),
        ]);
        return AgentRecordedRunSnapshot::fromRowsBySection([
            AgentRecordedRunComparison::SECTION_CONVERSATIONS => [['_label' => $idNormalization->normalizeJsonOrText((string)$rootConversation->id)]],
            AgentRecordedRunComparison::SECTION_DISPATCHES => [],
        ]);
    }
}
