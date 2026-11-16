<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What still waits or runs in one scheduling pass of a suite ({@see AgentEvalSuiteSchedule::selectRunnableTasks()}):
 * the queued reads per read key (a write waits for the reads it excludes to drain), the queued reads and writes per
 * scope (an exclusive task waits for everything else of its scope), and the queued or running tasks per wave of each
 * wave group (a later wave waits for the earlier ones).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteQueueCounts extends ValueObject
{
    /** @var bool Whether a write excludes every read of its scope, not only the reads of its resource */
    protected bool $isolateWholeScope = false;

    /** @var int[] Read key ({@see AgentEvalSuiteTask::getReadKey()}) → queued reads */
    protected array $queuedReadsByReadKey = [];

    /** @var int[] Scope id → queued reads and writes */
    protected array $queuedNonExclusiveTasksByScopeId = [];

    /** @var array Wave group → (wave index → queued or running tasks) */
    protected array $pendingTasksByWaveGroup = [];

    /** The counts of the queue `$queuedTasks` beside the running tasks `$runningTasks`. */
    public static function forQueue(AgentEvalSuiteTasks $queuedTasks, AgentEvalSuiteTasks $runningTasks, bool $isolateWholeScope): static
    {
        $agentEvalSuiteQueueCounts = static::newInstance();
        $agentEvalSuiteQueueCounts->isolateWholeScope = $isolateWholeScope;
        foreach ($queuedTasks->getElements() as $queuedTask) {
            $agentEvalSuiteQueueCounts->countQueuedTask($queuedTask, 1);
        }
        foreach (array_merge($queuedTasks->getElements(), $runningTasks->getElements()) as $pendingTask) {
            if ($pendingTask->waveGroup !== '') {
                $agentEvalSuiteQueueCounts->pendingTasksByWaveGroup[$pendingTask->waveGroup][$pendingTask->waveIndex]
                    = ($agentEvalSuiteQueueCounts->pendingTasksByWaveGroup[$pendingTask->waveGroup][$pendingTask->waveIndex] ?? 0) + 1;
            }
        }
        return $agentEvalSuiteQueueCounts;
    }

    /** A task selected in this pass leaves the queue: it no longer holds back the writes and exclusive tasks of its scope. */
    public function countStartOf(AgentEvalSuiteTask $startedTask): void
    {
        $this->countQueuedTask($startedTask, -1);
    }

    /** The queued reads a write of `$writingTask` waits for (its read key). */
    public function countQueuedReadsExcludedBy(AgentEvalSuiteTask $writingTask): int
    {
        return $this->queuedReadsByReadKey[$writingTask->getReadKey($this->isolateWholeScope)] ?? 0;
    }

    /** The queued reads and writes of the scope `$scopeId`. */
    public function countQueuedNonExclusiveTasksOfScope(?int $scopeId): int
    {
        return $scopeId === null ? 0 : ($this->queuedNonExclusiveTasksByScopeId[$scopeId] ?? 0);
    }

    /** Whether a task of an earlier wave of the wave group of `$agentEvalSuiteTask` is still queued or running. */
    public function hasPendingEarlierWave(AgentEvalSuiteTask $agentEvalSuiteTask): bool
    {
        if ($agentEvalSuiteTask->waveGroup === '') {
            return false;
        }
        foreach ($this->pendingTasksByWaveGroup[$agentEvalSuiteTask->waveGroup] ?? [] as $waveIndex => $pendingTasks) {
            if ($waveIndex < $agentEvalSuiteTask->waveIndex && $pendingTasks > 0) {
                return true;
            }
        }
        return false;
    }

    /** Adds `$step` (1 or -1) to the counts a queued task of a scope is part of. */
    protected function countQueuedTask(AgentEvalSuiteTask $queuedTask, int $step): void
    {
        $scopeId = $queuedTask->getScopeId();
        if ($scopeId === null) {
            return;
        }
        if ($queuedTask->access === AgentEvalSuiteTask::ACCESS_READ) {
            $readKey = $queuedTask->getReadKey($this->isolateWholeScope);
            $this->queuedReadsByReadKey[$readKey] = ($this->queuedReadsByReadKey[$readKey] ?? 0) + $step;
        }
        if (in_array($queuedTask->access, [AgentEvalSuiteTask::ACCESS_READ, AgentEvalSuiteTask::ACCESS_WRITE], true)) {
            $this->queuedNonExclusiveTasksByScopeId[$scopeId] = ($this->queuedNonExclusiveTasksByScopeId[$scopeId] ?? 0) + $step;
        }
    }
}
