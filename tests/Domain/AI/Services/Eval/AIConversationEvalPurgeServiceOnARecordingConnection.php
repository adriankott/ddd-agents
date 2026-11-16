<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Domain\Base\Repo\DB\Dialect\DialectResolver;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use Doctrine\DBAL\Connection;

/**
 * The evaluation purge on {@see AIConversationEvalPurgeRecordingConnection} with the dialect a test chooses: the real
 * write of a chunk runs, the connection records it. The pauses are not slept and the server is never busy.
 */
class AIConversationEvalPurgeServiceOnARecordingConnection extends AIConversationEvalPurgeService
{
    public AIConversationEvalPurgeRecordingConnection $recordingConnection;

    /** @var string The DBAL driver whose dialect the statements are built for */
    public string $databaseDriver = 'pdo_mysql';

    public function __construct()
    {
        $this->recordingConnection = new AIConversationEvalPurgeRecordingConnection();
    }

    /** @param int[] $idChunk */
    public function executeOneStepChunk(AIConversationEvalPurgeStep $step, array $idChunk, int $rowLimit): int
    {
        return $this->executeStepChunk($step, $idChunk, $rowLimit);
    }

    /**
     * @param int[] $evalAccountIds
     * @return int[]
     */
    public function findCandidates(array $evalAccountIds, AIConversationEvalPurgeSettings $settings): array
    {
        return $this->findCandidateRootConversationIds($evalAccountIds, '2026-03-10 12:00:00', 0, $settings);
    }

    /**
     * @param int[] $rootConversationIds
     * @return array<int, string|null>
     */
    public function findMarkers(array $rootConversationIds): array
    {
        return $this->findEvalMarkersOfRootConversations($rootConversationIds);
    }

    /** @param int[] $idChunk */
    public function countRowsOfStep(AIConversationEvalPurgeStep $step, array $idChunk): int
    {
        return $this->countStepRows($step, $idChunk);
    }

    protected function getConnection(): Connection
    {
        return $this->recordingConnection;
    }

    protected function getSqlDialect(): SqlDialect
    {
        return DialectResolver::forDriver($this->databaseDriver);
    }

    protected function isDatabaseTooBusy(AIConversationEvalPurgeSettings $settings): bool
    {
        return false;
    }

    protected function pauseMilliseconds(int $pauseMs): void
    {
    }
}
