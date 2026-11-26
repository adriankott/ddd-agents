<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoals;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A host's goal store: its goals are rows of its own, held here in memory with the host's ids, and the store answers
 * every port method from them — it never touches the package's table. Records every port call in order (with the
 * statement or ids it was about), so a test reads which writes ran in which order.
 */
class HostBackedAgentGoalStoreService extends AgentGoalStoreService
{
    /** The host's goal rows (each with the host's id) */
    public AgentGoals $hostGoals;

    /** @var string[] The port calls, in order: `<method>` or `<method>:<detail>` */
    public array $portCalls = [];

    /** @var bool What {@see isGoalStoreAvailable()} answers */
    public bool $isAvailable = true;

    /** @var int The id the next new row gets */
    public int $nextGoalId = 500;

    public function __construct()
    {
        $this->hostGoals = AgentGoals::newInstance();
    }

    /**
     * Stores a row as the host holds it (with its id).
     *
     * @param AgentGoal $agentGoal
     * @return AgentGoal
     */
    public function addHostRow(AgentGoal $agentGoal): AgentGoal
    {
        if (!isset($agentGoal->id)) {
            $agentGoal->id = $this->nextGoalId++;
        }
        $agentGoal->prepareForPersistence();
        $this->hostGoals->add($agentGoal);
        return $agentGoal;
    }

    public function isGoalStoreAvailable(): bool
    {
        $this->portCalls[] = 'isGoalStoreAvailable';
        return $this->isAvailable;
    }

    public function findCurrentGoalsForScope(int $accountId, ?int $agentSpaceId = null, bool $heldOnly = false, ?int $limit = null): AgentGoals
    {
        $this->portCalls[] = 'findCurrentGoalsForScope';
        $goalsOfScope = [];
        foreach ($this->hostGoals->getElements() as $agentGoal) {
            if ((int)$agentGoal->accountId !== $accountId || !$agentGoal->isCurrentlyValid()) {
                continue;
            }
            if ($agentGoal->isAgentSpaceBound() && ($agentSpaceId === null || (int)$agentGoal->agentSpaceId !== $agentSpaceId)) {
                continue;
            }
            if ($heldOnly && !$agentGoal->isHeld()) {
                continue;
            }
            $goalsOfScope[] = $agentGoal;
        }
        usort($goalsOfScope, static fn (AgentGoal $first, AgentGoal $second): int => [$second->priority, $first->id] <=> [$first->priority, $second->id]);
        $agentGoals = AgentGoals::newInstance();
        foreach (array_slice($goalsOfScope, 0, $limit) as $agentGoal) {
            $agentGoals->add($agentGoal);
        }
        return $agentGoals;
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $this->portCalls[] = 'find:' . $entityId;
        return $this->findHostGoal((int)$entityId);
    }

    public function writeGoal(AgentGoal $agentGoal): AgentGoal
    {
        $this->portCalls[] = 'writeGoal:' . $agentGoal->statement;
        if (!isset($agentGoal->id)) {
            $agentGoal->id = $this->nextGoalId++;
        }
        if ($this->findHostGoal((int)$agentGoal->id) === null) {
            $this->hostGoals->add($agentGoal);
        }
        return $agentGoal;
    }

    public function closeValidityWindow(int $targetGoalId, ?int $successorGoalId, DateTime $validTo, ?DateTime $invalidatedAt = null): int
    {
        $this->portCalls[] = 'closeValidityWindow:' . $targetGoalId . '>' . ($successorGoalId ?? 'none');
        $closedGoal = $this->findHostGoal($targetGoalId);
        if ($closedGoal === null || !$closedGoal->isCurrentlyValid()) {
            return 0;
        }
        $closedGoal->validTo = $validTo;
        $closedGoal->invalidatedAt = $invalidatedAt ?? new DateTime('now');
        $closedGoal->supersededByGoalId = $successorGoalId;
        return 1;
    }

    public function recordSuccessorOfClosedGoal(int $closedGoalId, int $successorGoalId): int
    {
        $this->portCalls[] = 'recordSuccessorOfClosedGoal:' . $closedGoalId . '>' . $successorGoalId;
        $closedGoal = $this->findHostGoal($closedGoalId);
        if ($closedGoal === null || $closedGoal->supersededByGoalId !== null) {
            return 0;
        }
        $closedGoal->supersededByGoalId = $successorGoalId;
        return 1;
    }

    public function invalidateCurrentGoalsOfScope(int $accountId, ?int $agentSpaceId): int
    {
        $this->portCalls[] = 'invalidateCurrentGoalsOfScope';
        $invalidatedCount = 0;
        foreach ($this->hostGoals->getElements() as $agentGoal) {
            if ((int)$agentGoal->accountId === $accountId && $agentGoal->agentSpaceId === $agentSpaceId && $agentGoal->isCurrentlyValid()) {
                $agentGoal->validTo = new DateTime('now');
                $agentGoal->invalidatedAt = new DateTime('now');
                $invalidatedCount++;
            }
        }
        return $invalidatedCount;
    }

    public function getColumnsNotPurgedByEvalPurge(): array
    {
        $this->portCalls[] = 'getColumnsNotPurgedByEvalPurge';
        return [];
    }

    /**
     * The host row with the id `$goalId`, or null.
     *
     * @param int $goalId
     * @return AgentGoal|null
     */
    public function findHostGoal(int $goalId): ?AgentGoal
    {
        foreach ($this->hostGoals->getElements() as $agentGoal) {
            if ((int)$agentGoal->id === $goalId) {
                return $agentGoal;
            }
        }
        return null;
    }

    /**
     * The port calls that wrote (rows written, closed or pointed at a successor), in order.
     *
     * @return string[]
     */
    public function findWriteCalls(): array
    {
        $writeCalls = [];
        foreach ($this->portCalls as $portCall) {
            if (str_starts_with($portCall, 'writeGoal') || str_starts_with($portCall, 'closeValidityWindow') || str_starts_with($portCall, 'recordSuccessorOfClosedGoal')) {
                $writeCalls[] = $portCall;
            }
        }
        return $writeCalls;
    }
}
