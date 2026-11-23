<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntries;
use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntry;
use DDD\Domain\AI\Repo\DB\Sandboxes\DBAgentSandboxActionLogEntries;
use DDD\Domain\AI\Repo\DB\Sandboxes\DBAgentSandboxActionLogEntry;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOption;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The audit of workspace actions ({@see AgentSandboxActionLogEntry}). A host without the table still acts: the entry
 * goes to the error log instead, and reads answer an empty list.
 *
 * @method AgentSandboxActionLogEntry find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentSandboxActionLogEntries findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentSandboxActionLogEntry getEntityRepoClassInstance()
 * @method DBAgentSandboxActionLogEntries getEntitySetRepoClassInstance()
 */
class AgentSandboxActionLogEntriesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentSandboxActionLogEntry::class;

    /** @var int The most entries one read answers */
    public const int MAX_ENTRIES_PER_READ = 200;

    /** @var int Characters kept of a reason or an outcome detail */
    public const int MAX_TEXT_LENGTH = 1000;

    /**
     * Writes one audit entry. A write that fails (no table yet) logs the entry as an error and answers null; the action
     * it describes is never undone for it.
     *
     * @param AgentSandboxActionLogEntry $agentSandboxActionLogEntry
     * @return AgentSandboxActionLogEntry|null the stored entry, null when it could only be logged
     */
    public function recordActionLogEntry(AgentSandboxActionLogEntry $agentSandboxActionLogEntry): ?AgentSandboxActionLogEntry
    {
        $agentSandboxActionLogEntry->reason = $this->truncateText($agentSandboxActionLogEntry->reason);
        $agentSandboxActionLogEntry->outcomeDetail = $this->truncateText($agentSandboxActionLogEntry->outcomeDetail);
        try {
            /** @var AgentSandboxActionLogEntry $storedActionLogEntry */
            $storedActionLogEntry = $agentSandboxActionLogEntry->insert();
            return $storedActionLogEntry;
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->error('[AgentSandbox] audit entry not stored, logged instead', [
                'workspaceKey' => $agentSandboxActionLogEntry->workspaceKey,
                'agentSpaceId' => $agentSandboxActionLogEntry->agentSpaceId,
                'action' => $agentSandboxActionLogEntry->action,
                'jobId' => $agentSandboxActionLogEntry->jobId,
                'actorAccountId' => $agentSandboxActionLogEntry->actorAccountId,
                'forced' => $agentSandboxActionLogEntry->forced,
                'reason' => $agentSandboxActionLogEntry->reason,
                'outcome' => $agentSandboxActionLogEntry->outcome,
                'outcomeDetail' => $agentSandboxActionLogEntry->outcomeDetail,
                'storeError' => $throwable->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * The newest audit entries of one workspace; empty when the table is missing.
     *
     * @param string $workspaceKey
     * @param int $limit
     * @return AgentSandboxActionLogEntries
     */
    public function findNewestActionLogEntriesOfWorkspace(string $workspaceKey, int $limit): AgentSandboxActionLogEntries
    {
        try {
            $repoClass = $this->getEntitySetRepoClassInstance();
            $queryBuilder = $repoClass::createQueryBuilder();
            $baseModelAlias = $repoClass::getBaseModelAlias();
            $queryBuilder->andWhere("{$baseModelAlias}.workspaceKey = :workspaceKey")
                ->setParameter('workspaceKey', $workspaceKey)
                ->orderBy("{$baseModelAlias}.id", OrderByOption::DESC)
                ->setMaxResults(max(1, min($limit, static::MAX_ENTRIES_PER_READ)));
            return $repoClass->find($queryBuilder, false) ?? AgentSandboxActionLogEntries::newInstance();
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[AgentSandbox] audit entries not readable', ['workspaceKey' => $workspaceKey, 'reason' => $throwable->getMessage()]);
            return AgentSandboxActionLogEntries::newInstance();
        }
    }

    protected function truncateText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $trimmedText = trim($text);
        return $trimmedText === '' ? null : mb_substr($trimmedText, 0, static::MAX_TEXT_LENGTH);
    }
}
