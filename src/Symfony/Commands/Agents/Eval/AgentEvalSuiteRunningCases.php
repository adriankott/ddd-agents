<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRunningTasks;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Domain\Base\Entities\ObjectSet;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The cases of an evaluation run whose child processes are running ({@see AgentEvalSuiteRunningCase}), in the order
 * they started, one per task id.
 *
 * A plain object of one command run; no container service.
 *
 * @property AgentEvalSuiteRunningCase[] $elements
 * @method AgentEvalSuiteRunningCase[] getElements()
 * @method AgentEvalSuiteRunningCase|null first()
 * @method AgentEvalSuiteRunningCase|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentEvalSuiteRunningCases extends ObjectSet
{
    /** The running case of task `$taskId`; null when none runs. */
    public function getByTaskId(string $taskId): ?AgentEvalSuiteRunningCase
    {
        return $this->getByUniqueKey(AgentEvalSuiteRunningCase::uniqueKeyOfTaskId($taskId));
    }

    /** The tasks of the running cases, in their order: what the schedule reads as running. */
    public function buildTasks(): AgentEvalSuiteTasks
    {
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::newInstance();
        foreach ($this->getElements() as $agentEvalSuiteRunningCase) {
            $runningTask = $agentEvalSuiteRunningCase->task;
            $agentEvalSuiteTasks->add($runningTask);
        }
        return $agentEvalSuiteTasks;
    }

    /** The entries the progress file lists at `$nowMicrotime`, in the order the cases started. */
    public function buildRunningTasks(float $nowMicrotime): AgentEvalSuiteRunningTasks
    {
        $agentEvalSuiteRunningTasks = AgentEvalSuiteRunningTasks::newInstance();
        foreach ($this->getElements() as $agentEvalSuiteRunningCase) {
            $agentEvalSuiteRunningTask = $agentEvalSuiteRunningCase->buildRunningTask($nowMicrotime);
            $agentEvalSuiteRunningTasks->add($agentEvalSuiteRunningTask);
        }
        return $agentEvalSuiteRunningTasks;
    }
}
