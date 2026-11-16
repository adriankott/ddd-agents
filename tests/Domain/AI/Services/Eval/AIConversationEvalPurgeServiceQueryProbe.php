<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;

/**
 * The evaluation purge with its protected reads and its chunk write opened for a database test — the statements
 * themselves unchanged. Its evaluation accounts are the ones the test names.
 */
class AIConversationEvalPurgeServiceQueryProbe extends AIConversationEvalPurgeService
{
    /** @var int[] */
    public array $configuredEvalAccountIds = [];

    public function findConfiguredEvalAccountIds(): array
    {
        return $this->configuredEvalAccountIds;
    }

    /**
     * @param int[] $evalAccountIds
     * @return int[]
     */
    public function probeCandidateRootConversationIds(array $evalAccountIds, string $activityCutoff, AIConversationEvalPurgeSettings $settings): array
    {
        return $this->findCandidateRootConversationIds($evalAccountIds, $activityCutoff, 0, $settings);
    }

    /**
     * @param int[] $rootConversationIds
     * @return array<int, string|null>
     */
    public function probeEvalMarkersOfRootConversations(array $rootConversationIds): array
    {
        return $this->findEvalMarkersOfRootConversations($rootConversationIds);
    }

    /** @param int[] $idChunk */
    public function probeStepChunk(AIConversationEvalPurgeStep $step, array $idChunk, int $rowLimit): int
    {
        return $this->executeStepChunk($step, $idChunk, $rowLimit);
    }

    protected function pauseMilliseconds(int $pauseMs): void
    {
    }
}
