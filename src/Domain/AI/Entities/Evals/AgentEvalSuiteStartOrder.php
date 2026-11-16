<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The start order of the tasks of a suite run ({@see AgentEvalSuiteSchedule::orderTasksForStart()}): the tasks without
 * live state first (they bound the wall time when they are long), then the reads — those a write lane waits for first,
 * the longest lane's first —, then the writes of the longest lane first, exclusive tasks last; the tasks of one wave
 * group by their wave; stable otherwise.
 *
 * Holds what the comparison needs (the length of each write lane, the planned position of each task) for one ordering.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteStartOrder extends ValueObject
{
    /** @var int[] The rank of each access in the start order; an access not listed ranks after every listed one */
    public const array RANK_BY_ACCESS = [
        AgentEvalSuiteTask::ACCESS_NONE => 0,
        AgentEvalSuiteTask::ACCESS_READ => 1,
        AgentEvalSuiteTask::ACCESS_WRITE => 2,
        AgentEvalSuiteTask::ACCESS_EXCLUSIVE => 3,
    ];

    /** @var int[] Write lane key ({@see AgentEvalSuiteTask::getWriteLaneKey()}) → the writing tasks in it */
    protected array $laneSizeByWriteLaneKey = [];

    /** @var int[] Task id → its position among the tasks to order */
    protected array $positionByTaskId = [];

    /** The start order of `$agentEvalSuiteTasks`. */
    public static function forTasks(AgentEvalSuiteTasks $agentEvalSuiteTasks): static
    {
        $agentEvalSuiteStartOrder = static::newInstance();
        foreach ($agentEvalSuiteTasks->getElements() as $agentEvalSuiteTask) {
            if ($agentEvalSuiteTask->writesLiveState()) {
                $writeLaneKey = $agentEvalSuiteTask->getWriteLaneKey();
                $agentEvalSuiteStartOrder->laneSizeByWriteLaneKey[$writeLaneKey] = ($agentEvalSuiteStartOrder->laneSizeByWriteLaneKey[$writeLaneKey] ?? 0) + 1;
            }
        }
        $agentEvalSuiteStartOrder->positionByTaskId = array_flip($agentEvalSuiteTasks->getTaskIds());
        return $agentEvalSuiteStartOrder;
    }

    /** The number of writing tasks in the lane `$writeLaneKey`; 0 for a lane without one. */
    public function getLaneSize(string $writeLaneKey): int
    {
        return $this->laneSizeByWriteLaneKey[$writeLaneKey] ?? 0;
    }

    /** Negative when `$left` starts before `$right`, positive when after, 0 for the same position. */
    public function compareForStart(AgentEvalSuiteTask $left, AgentEvalSuiteTask $right): int
    {
        $lowestRank = count(static::RANK_BY_ACCESS);
        $byAccess = (static::RANK_BY_ACCESS[$left->access] ?? $lowestRank) <=> (static::RANK_BY_ACCESS[$right->access] ?? $lowestRank);
        if ($byAccess !== 0) {
            return $byAccess;
        }
        if ($left->waveGroup !== '' && $left->waveGroup === $right->waveGroup && $left->waveIndex !== $right->waveIndex) {
            return $left->waveIndex <=> $right->waveIndex;
        }
        if ($left->access !== AgentEvalSuiteTask::ACCESS_NONE) {
            // a read is ranked by the write lane that waits for it (its scope and resource)
            $byLaneSize = $this->getLaneSize($right->getWriteLaneKey()) <=> $this->getLaneSize($left->getWriteLaneKey());
            if ($byLaneSize !== 0) {
                return $byLaneSize;
            }
        }
        return ($this->positionByTaskId[$left->taskId] ?? 0) <=> ($this->positionByTaskId[$right->taskId] ?? 0);
    }
}
