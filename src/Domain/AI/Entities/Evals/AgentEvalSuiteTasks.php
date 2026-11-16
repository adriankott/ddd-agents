<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Tasks of a parallel evaluation suite ({@see AgentEvalSuiteTask}), in the order they were added: the planned tasks,
 * the queue in start order, the running tasks. A task id is unique within a suite run; the set holds each task under
 * the key of its own object and finds and removes tasks by their task id.
 *
 * @property AgentEvalSuiteTask[] $elements
 * @method AgentEvalSuiteTask[] getElements()
 * @method AgentEvalSuiteTask|null first()
 * @method AgentEvalSuiteTask|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteTasks extends ObjectSet
{
    /**
     * A new set with the tasks of `$agentEvalSuiteTasks`, in their order.
     *
     * @param AgentEvalSuiteTask ...$agentEvalSuiteTasks
     * @return static
     */
    public static function fromTasks(AgentEvalSuiteTask ...$agentEvalSuiteTasks): static
    {
        $tasksSet = static::newInstance();
        foreach ($agentEvalSuiteTasks as $agentEvalSuiteTask) {
            $tasksSet->add($agentEvalSuiteTask);
        }
        return $tasksSet;
    }

    /** A new set with the same tasks in the same order. */
    public function buildCopy(): static
    {
        return static::fromTasks(...$this->getElements());
    }

    /** Whether the set holds a task with this task id. */
    public function hasTaskId(string $taskId): bool
    {
        return $this->getByTaskId($taskId) !== null;
    }

    /** The first task with this task id, or null. */
    public function getByTaskId(string $taskId): ?AgentEvalSuiteTask
    {
        foreach ($this->getElements() as $agentEvalSuiteTask) {
            if ($agentEvalSuiteTask->taskId === $taskId) {
                return $agentEvalSuiteTask;
            }
        }
        return null;
    }

    /**
     * Removes every task with the task id of `$agentEvalSuiteTask` (the task itself when it has no id); the order of the
     * rest stays.
     */
    public function removeTask(AgentEvalSuiteTask $agentEvalSuiteTask): void
    {
        foreach ($this->getElements() as $heldTask) {
            $sameTask = $agentEvalSuiteTask->taskId !== '' ? $heldTask->taskId === $agentEvalSuiteTask->taskId : $heldTask === $agentEvalSuiteTask;
            if ($sameTask) {
                $this->remove($heldTask);
            }
        }
    }

    /** Puts `$agentEvalSuiteTask` at the head of the set (a task that runs once more starts next when its lane is free). */
    public function prependTask(AgentEvalSuiteTask $agentEvalSuiteTask): void
    {
        $tasksInOrder = $this->getElements();
        $this->clear();
        $this->add($agentEvalSuiteTask);
        foreach ($tasksInOrder as $heldTask) {
            $this->add($heldTask);
        }
    }

    /** Adds every task of `$otherTasks` in its order. */
    public function addTasksOf(AgentEvalSuiteTasks $otherTasks): void
    {
        foreach ($otherTasks->getElements() as $agentEvalSuiteTask) {
            $this->add($agentEvalSuiteTask);
        }
    }

    /**
     * The task ids in the order of the set.
     *
     * @return string[]
     */
    public function getTaskIds(): array
    {
        return array_map(static fn (AgentEvalSuiteTask $agentEvalSuiteTask): string => $agentEvalSuiteTask->taskId, $this->getElements());
    }

    /** The tasks that read a live scope, in their order. */
    public function buildReadingTasks(): static
    {
        return static::fromTasks(...array_values(array_filter(
            $this->getElements(),
            static fn (AgentEvalSuiteTask $agentEvalSuiteTask): bool => $agentEvalSuiteTask->access === AgentEvalSuiteTask::ACCESS_READ
        )));
    }

    /** Every task that does not read a live scope (none, write, exclusive), in their order. */
    public function buildNonReadingTasks(): static
    {
        return static::fromTasks(...array_values(array_filter(
            $this->getElements(),
            static fn (AgentEvalSuiteTask $agentEvalSuiteTask): bool => $agentEvalSuiteTask->access !== AgentEvalSuiteTask::ACCESS_READ
        )));
    }

    /** How many tasks of the concurrency group `$concurrencyGroup` the set holds. */
    public function countTasksOfConcurrencyGroup(string $concurrencyGroup): int
    {
        $tasksOfGroup = 0;
        foreach ($this->getElements() as $agentEvalSuiteTask) {
            if ($agentEvalSuiteTask->concurrencyGroup === $concurrencyGroup) {
                $tasksOfGroup++;
            }
        }
        return $tasksOfGroup;
    }

    /**
     * The planned case ids per run folder (`<model>-<flavor>`, the folder a run's summary is written to): what a
     * former run must share with this run to serve as its baseline.
     */
    public function buildPlannedCaseIdsByRunFolder(): AgentEvalCaseIdsByRunFolder
    {
        $plannedCaseIds = AgentEvalCaseIdsByRunFolder::newInstance();
        foreach ($this->getElements() as $agentEvalSuiteTask) {
            $plannedCaseIds->addCaseId($agentEvalSuiteTask->getRunFolderName(), $agentEvalSuiteTask->caseId);
        }
        return $plannedCaseIds;
    }
}
