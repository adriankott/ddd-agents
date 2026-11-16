<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRunningTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Process\Process;

/**
 * One case of an evaluation run whose child process is running: the task, its process, the moment it started and the
 * result file the child writes. The unique key is the task id, so a set holds one running case per task.
 *
 * A plain object of one command run (it holds a live process); no container service, never stored.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentEvalSuiteRunningCase extends ValueObject
{
    /** @var AgentEvalSuiteTask The task the child process runs */
    public AgentEvalSuiteTask $task;

    /** @var Process The child process */
    public Process $process;

    /** @var float The moment the child process started (`microtime(true)`) */
    public float $startedAtMicrotime = 0.0;

    /** @var string The result file the child process writes */
    public string $resultFile = '';

    /**
     * The running case of `$agentEvalSuiteTask`, its child process `$process` started at `$startedAtMicrotime`.
     */
    public static function forStartedProcess(AgentEvalSuiteTask $agentEvalSuiteTask, Process $process, float $startedAtMicrotime, string $resultFile): static
    {
        $agentEvalSuiteRunningCase = static::newInstance();
        $agentEvalSuiteRunningCase->task = $agentEvalSuiteTask;
        $agentEvalSuiteRunningCase->process = $process;
        $agentEvalSuiteRunningCase->startedAtMicrotime = $startedAtMicrotime;
        $agentEvalSuiteRunningCase->resultFile = $resultFile;
        return $agentEvalSuiteRunningCase;
    }

    /** The seconds the child process has run at `$nowMicrotime`. */
    public function getRunSeconds(float $nowMicrotime): float
    {
        return $nowMicrotime - $this->startedAtMicrotime;
    }

    /** The entry the progress file lists for this case at `$nowMicrotime` (the whole seconds rounded). */
    public function buildRunningTask(float $nowMicrotime): AgentEvalSuiteRunningTask
    {
        return AgentEvalSuiteRunningTask::forTask($this->task, $this->process->getPid(), (int)round($this->getRunSeconds($nowMicrotime)));
    }

    /** The key of the running case of task `$taskId` in its set (the module's class name, also for a subclass). */
    public static function uniqueKeyOfTaskId(string $taskId): string
    {
        return self::class . '_' . $taskId;
    }

    /** The task id. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyOfTaskId($this->task->taskId);
    }
}
