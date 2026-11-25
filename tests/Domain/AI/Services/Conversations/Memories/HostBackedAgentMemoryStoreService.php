<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentEmbeddingRepairTargets;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemories;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A host's memory store: its memories are rows of its own ({@see HostMemoryNoteRow}), mapped into the carrier
 * {@see AgentMemory} with the host row's id. It answers the port and never touches the package's table: every method
 * that would reach the package's repository is answered from the rows.
 */
class HostBackedAgentMemoryStoreService extends AgentMemoryStoreService
{
    public HostMemoryNoteRows $hostMemoryNoteRows;

    /** @var string[] The port methods asked, in order */
    public array $askedPortMethods = [];

    public function __construct(HostMemoryNoteRows $hostMemoryNoteRows)
    {
        $this->hostMemoryNoteRows = $hostMemoryNoteRows;
    }

    public function findCurrentMemoriesForScope(int $accountId, ?int $agentSpaceId = null, ?string $memoryType = null, ?int $limit = null): AgentMemories
    {
        $this->askedPortMethods[] = 'findCurrentMemoriesForScope';
        $agentMemories = AgentMemories::newInstance();
        foreach ($this->hostMemoryNoteRows->getElements() as $hostMemoryNoteRow) {
            if (!$this->isRowInScope($hostMemoryNoteRow, $accountId, $agentSpaceId)) {
                continue;
            }
            $agentMemory = $this->mapRowToMemory($hostMemoryNoteRow);
            if ($memoryType !== null && $agentMemory->memoryType !== $memoryType) {
                continue;
            }
            $agentMemories->add($agentMemory);
            if ($limit !== null && $agentMemories->count() >= $limit) {
                break;
            }
        }
        return $agentMemories;
    }

    public function findSimilarMemories(string $searchText, int $accountId, ?int $agentSpaceId = null, int $limit = 10, ?string $memoryType = null): AgentMemories
    {
        $this->askedPortMethods[] = 'findSimilarMemories';
        $similarMemories = AgentMemories::newInstance();
        foreach ($this->findCurrentMemoriesForScope($accountId, $agentSpaceId, $memoryType)->getElements() as $agentMemory) {
            if (stripos($agentMemory->content, $searchText) !== false && $similarMemories->count() < $limit) {
                $similarMemories->add($agentMemory);
            }
        }
        return $similarMemories;
    }

    public function getEmbeddingRepairTargets(): AgentEmbeddingRepairTargets
    {
        $this->askedPortMethods[] = 'getEmbeddingRepairTargets';
        return new AgentEmbeddingRepairTargets();
    }

    public function getColumnsNotPurgedByEvalPurge(): array
    {
        $this->askedPortMethods[] = 'getColumnsNotPurgedByEvalPurge';
        return [];
    }

    /** Whether the row belongs to the scope: the account's rows of the space, and the account-level ones. */
    protected function isRowInScope(HostMemoryNoteRow $hostMemoryNoteRow, int $accountId, ?int $agentSpaceId): bool
    {
        if ($hostMemoryNoteRow->ownerAccountId !== $accountId) {
            return false;
        }
        return $hostMemoryNoteRow->workspaceId === null || $hostMemoryNoteRow->workspaceId === $agentSpaceId;
    }

    /** The carrier of one host row: id = the host row's id. */
    protected function mapRowToMemory(HostMemoryNoteRow $hostMemoryNoteRow): AgentMemory
    {
        $memoryType = $hostMemoryNoteRow->isStandingRule ? AgentMemory::MEMORY_TYPE_DIRECTIVE : AgentMemory::MEMORY_TYPE_FACT;
        $agentMemory = AgentMemory::buildStated($hostMemoryNoteRow->noteText, $memoryType, null);
        $agentMemory->id = $hostMemoryNoteRow->noteId;
        $agentMemory->accountId = $hostMemoryNoteRow->ownerAccountId;
        $agentMemory->agentSpaceId = $hostMemoryNoteRow->workspaceId;
        $agentMemory->validFrom = new DateTime('2026-03-0' . min(9, $hostMemoryNoteRow->noteId) . ' 00:00:00');
        return $agentMemory;
    }
}
