<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The rules by which a parallel evaluation suite starts its tasks ({@see AgentEvalSuiteTask}): pure — tasks and the
 * running set in, the next runnable tasks out; no process, no clock.
 *
 * Writes collide per scope and per resource, never globally, so they are scheduled in lanes:
 *
 *  - a READ runs beside other reads and beside writes on other resources of its scope; the reads of a resource drain
 *    before that resource's first write;
 *  - a WRITE runs beside writes on other resources of its scope, never beside a read or a write on the same resource —
 *    also across models and flavors, so two runs never change the same resource at once;
 *  - an EXCLUSIVE task runs alone on its scope, after everything else of that scope;
 *  - a WAVE group starts in waves: no task of a later wave starts while one of an earlier wave is queued or running;
 *  - a CONCURRENCY group never runs more tasks at once than its limit;
 *  - a task without live state (`none`) or without a scope runs whenever a process slot is free.
 *
 * With {@see $isolateWholeScope} a write excludes every read of its scope, not only those of its resource — the
 * conservative mode for a fixture whose reads depend on a resource another fixture writes.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteSchedule extends ValueObject
{
    /** @var int How many task processes run at the same time */
    public int $maxProcesses = 1;

    /** @var float Seconds between two process starts (0 = every runnable task starts at once) */
    public float $startStaggerSeconds = 0.0;

    /** @var bool Whether a write excludes every read of its scope, not only the reads of its resource */
    public bool $isolateWholeScope = false;

    /** A schedule of `$maxProcesses` processes. */
    public static function forProcesses(int $maxProcesses, float $startStaggerSeconds = 0.0, bool $isolateWholeScope = false): static
    {
        $agentEvalSuiteSchedule = static::newInstance();
        $agentEvalSuiteSchedule->maxProcesses = $maxProcesses;
        $agentEvalSuiteSchedule->startStaggerSeconds = $startStaggerSeconds;
        $agentEvalSuiteSchedule->isolateWholeScope = $isolateWholeScope;
        return $agentEvalSuiteSchedule;
    }

    /** The tasks in start order ({@see AgentEvalSuiteStartOrder}), as a new set. */
    public function orderTasksForStart(AgentEvalSuiteTasks $agentEvalSuiteTasks): AgentEvalSuiteTasks
    {
        $agentEvalSuiteStartOrder = AgentEvalSuiteStartOrder::forTasks($agentEvalSuiteTasks);
        $tasksInStartOrder = $agentEvalSuiteTasks->getElements();
        usort($tasksInStartOrder, $agentEvalSuiteStartOrder->compareForStart(...));
        return AgentEvalSuiteTasks::fromTasks(...$tasksInStartOrder);
    }

    /**
     * The tasks that may start now, in queue order, given what runs and how many process slots are free. Writes are
     * considered before reads for the free slots: a lane is sequential, so every moment it idles is wall time lost,
     * while a read only needs any slot.
     *
     * @param AgentEvalSuiteTasks $queuedTasks the queue in start order
     * @param AgentEvalSuiteTasks $runningTasks the running tasks
     * @return AgentEvalSuiteTasks
     */
    public function selectRunnableTasks(AgentEvalSuiteTasks $queuedTasks, AgentEvalSuiteTasks $runningTasks): AgentEvalSuiteTasks
    {
        $selectedTasks = AgentEvalSuiteTasks::newInstance();
        $freeSlots = $this->maxProcesses - $runningTasks->count();
        if ($freeSlots <= 0) {
            return $selectedTasks;
        }
        $agentEvalSuiteQueueCounts = AgentEvalSuiteQueueCounts::forQueue($queuedTasks, $runningTasks, $this->isolateWholeScope);
        $activeTasks = $runningTasks->buildCopy();
        $candidateTasks = $queuedTasks->buildNonReadingTasks();
        $candidateTasks->addTasksOf($queuedTasks->buildReadingTasks());
        foreach ($candidateTasks->getElements() as $candidateTask) {
            if ($selectedTasks->count() >= $freeSlots) {
                break;
            }
            if (!$this->mayRunBeside($candidateTask, $activeTasks, $agentEvalSuiteQueueCounts)) {
                continue;
            }
            $selectedTasks->add($candidateTask);
            $activeTasks->add($candidateTask);
            $agentEvalSuiteQueueCounts->countStartOf($candidateTask);
        }
        return $selectedTasks;
    }

    /**
     * Whether `$agentEvalSuiteTask` may start beside `$activeTasks` (the running tasks plus the ones selected in this
     * pass) under the counts of the queue.
     */
    public function mayRunBeside(AgentEvalSuiteTask $agentEvalSuiteTask, AgentEvalSuiteTasks $activeTasks, AgentEvalSuiteQueueCounts $agentEvalSuiteQueueCounts): bool
    {
        if ($agentEvalSuiteQueueCounts->hasPendingEarlierWave($agentEvalSuiteTask)) {
            return false; // an earlier wave of this group is still queued or running
        }
        if ($agentEvalSuiteTask->concurrencyGroup !== ''
            && $activeTasks->countTasksOfConcurrencyGroup($agentEvalSuiteTask->concurrencyGroup) >= max(1, $agentEvalSuiteTask->concurrencyLimit)
        ) {
            return false; // the group runs as many tasks as it may
        }
        $scopeId = $agentEvalSuiteTask->getScopeId();
        if ($agentEvalSuiteTask->access === AgentEvalSuiteTask::ACCESS_NONE || $scopeId === null) {
            return true;
        }
        if ($agentEvalSuiteTask->access === AgentEvalSuiteTask::ACCESS_WRITE && $agentEvalSuiteQueueCounts->countQueuedReadsExcludedBy($agentEvalSuiteTask) > 0) {
            return false; // the reads this write excludes drain first
        }
        if ($agentEvalSuiteTask->access === AgentEvalSuiteTask::ACCESS_EXCLUSIVE && $agentEvalSuiteQueueCounts->countQueuedNonExclusiveTasksOfScope($scopeId) > 0) {
            return false; // alone on the scope means after everything else of it
        }
        foreach ($activeTasks->getElements() as $activeTask) {
            if ($this->excludesEachOther($agentEvalSuiteTask, $activeTask)) {
                return false;
            }
        }
        return true;
    }

    /** Whether two tasks of a scope may not run at the same time (the same resource, an exclusive task, or an isolated scope). */
    public function excludesEachOther(AgentEvalSuiteTask $agentEvalSuiteTask, AgentEvalSuiteTask $activeTask): bool
    {
        if ($activeTask->getScopeId() !== $agentEvalSuiteTask->getScopeId() || $activeTask->access === AgentEvalSuiteTask::ACCESS_NONE) {
            return false;
        }
        if ($agentEvalSuiteTask->access === AgentEvalSuiteTask::ACCESS_EXCLUSIVE || $activeTask->access === AgentEvalSuiteTask::ACCESS_EXCLUSIVE) {
            return true;
        }
        $bothRead = $agentEvalSuiteTask->access === AgentEvalSuiteTask::ACCESS_READ && $activeTask->access === AgentEvalSuiteTask::ACCESS_READ;
        if ($bothRead) {
            return false;
        }
        $bothWrite = $agentEvalSuiteTask->access === AgentEvalSuiteTask::ACCESS_WRITE && $activeTask->access === AgentEvalSuiteTask::ACCESS_WRITE;
        // the same resource — or, isolating the scope, any read beside any write
        return $activeTask->resourceKey === $agentEvalSuiteTask->resourceKey || ($this->isolateWholeScope && !$bothWrite);
    }

    /**
     * The plan of `$agentEvalSuiteTasks` in numbers ({@see AgentEvalSuitePlanSummary}), with the wall time of a
     * simulated schedule over the tasks' duration priors ({@see AgentEvalSuiteTask::$estimatedSeconds}).
     */
    public function summarizePlan(AgentEvalSuiteTasks $agentEvalSuiteTasks): AgentEvalSuitePlanSummary
    {
        $agentEvalSuitePlanSummary = AgentEvalSuitePlanSummary::newInstance();
        $workSeconds = 0.0;
        foreach ($agentEvalSuiteTasks->getElements() as $agentEvalSuiteTask) {
            $agentEvalSuitePlanSummary->tasksBySuite[$agentEvalSuiteTask->suite] = ($agentEvalSuitePlanSummary->tasksBySuite[$agentEvalSuiteTask->suite] ?? 0) + 1;
            $agentEvalSuitePlanSummary->tasksByAccess[$agentEvalSuiteTask->access] = ($agentEvalSuitePlanSummary->tasksByAccess[$agentEvalSuiteTask->access] ?? 0) + 1;
            $runKey = $agentEvalSuiteTask->getRunKey();
            $agentEvalSuitePlanSummary->tasksByRun[$runKey] = ($agentEvalSuitePlanSummary->tasksByRun[$runKey] ?? 0) + 1;
            if ($agentEvalSuiteTask->writesLiveState()) {
                $writeLaneKey = $agentEvalSuiteTask->getWriteLaneKey();
                $agentEvalSuitePlanSummary->writingTasksByLane[$writeLaneKey] = ($agentEvalSuitePlanSummary->writingTasksByLane[$writeLaneKey] ?? 0) + 1;
            }
            if ($agentEvalSuiteTask->waveGroup !== '') {
                $waveGroup = $agentEvalSuiteTask->waveGroup;
                $agentEvalSuitePlanSummary->wavesByWaveGroup[$waveGroup] = max($agentEvalSuitePlanSummary->wavesByWaveGroup[$waveGroup] ?? 0, $agentEvalSuiteTask->waveIndex + 1);
            }
            $workSeconds += $agentEvalSuiteTask->estimatedSeconds;
        }
        arsort($agentEvalSuitePlanSummary->writingTasksByLane);
        $agentEvalSuitePlanSummary->tasks = $agentEvalSuiteTasks->count();
        $agentEvalSuitePlanSummary->sequentialWallSeconds = (int)round($workSeconds);
        $this->simulateScheduleInto($agentEvalSuiteTasks, $agentEvalSuitePlanSummary);
        return $agentEvalSuitePlanSummary;
    }

    /**
     * Runs the schedule over the duration priors — start what may start (one start per stagger interval), advance to
     * the next finish, repeat — and writes the wall seconds, what finished last and the tasks that could never start
     * into `$agentEvalSuitePlanSummary`.
     */
    protected function simulateScheduleInto(AgentEvalSuiteTasks $agentEvalSuiteTasks, AgentEvalSuitePlanSummary $agentEvalSuitePlanSummary): void
    {
        $queuedTasks = $this->orderTasksForStart($agentEvalSuiteTasks);
        $runningTasks = AgentEvalSuiteTasks::newInstance();
        /** @var float[] $endsAtByTaskId task id → the simulated second its run ends */
        $endsAtByTaskId = [];
        $now = 0.0;
        $nextStartAllowedAt = 0.0;
        $lastFinished = '';
        while ($queuedTasks->count() > 0 || $runningTasks->count() > 0) {
            $started = false;
            if ($now >= $nextStartAllowedAt) {
                $runnableTasks = $this->selectRunnableTasks($queuedTasks, $runningTasks);
                if ($runnableTasks->count() > 0) {
                    // with a stagger, one start per interval; without, everything runnable starts at once
                    $startingTasks = $this->startStaggerSeconds > 0.0 ? [$runnableTasks->first()] : $runnableTasks->getElements();
                    foreach ($startingTasks as $startingTask) {
                        $runningTasks->add($startingTask);
                        $endsAtByTaskId[$startingTask->taskId] = $now + $startingTask->estimatedSeconds;
                        $queuedTasks->removeTask($startingTask);
                    }
                    $nextStartAllowedAt = $now + $this->startStaggerSeconds;
                    $started = true;
                }
            }
            if ($started && $this->startStaggerSeconds <= 0.0) {
                continue;
            }
            // advance to whatever comes first: the next finish, or the next moment a start is allowed again
            $nextMoments = [];
            if ($runningTasks->count() > 0) {
                $nextMoments[] = min(array_map(static fn (AgentEvalSuiteTask $runningTask): float => $endsAtByTaskId[$runningTask->taskId], $runningTasks->getElements()));
            }
            if ($queuedTasks->count() > 0 && $nextStartAllowedAt > $now) {
                $nextMoments[] = $nextStartAllowedAt;
            }
            if ($nextMoments === []) {
                break; // nothing runs and nothing may start: an unschedulable rest (cannot happen with a consistent plan)
            }
            $now = min($nextMoments);
            foreach ($runningTasks->getElements() as $runningTask) {
                if ($endsAtByTaskId[$runningTask->taskId] <= $now) {
                    $lastFinished = $this->describeWhatFinished($runningTask);
                    $runningTasks->removeTask($runningTask);
                }
            }
        }
        $agentEvalSuitePlanSummary->estimatedWallSeconds = (int)round($now);
        $agentEvalSuitePlanSummary->boundBy = $lastFinished;
        $agentEvalSuitePlanSummary->unschedulableTasks = $queuedTasks->count();
    }

    /** What a finished task stands for in the plan's `boundBy`: its write lane, its wave group, its concurrency group or the read pool. */
    public function describeWhatFinished(AgentEvalSuiteTask $finishedTask): string
    {
        if ($finishedTask->writesLiveState()) {
            return 'lane ' . $finishedTask->getWriteLaneKey();
        }
        if ($finishedTask->waveGroup !== '') {
            return 'waves ' . $finishedTask->waveGroup;
        }
        return $finishedTask->concurrencyGroup !== '' ? $finishedTask->concurrencyGroup : 'the read pool';
    }
}
