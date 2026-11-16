<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One task of a suite run whose child process is running, as the progress file of the run names it: its case, suite,
 * run key, lane and access, the process id and the seconds it has run.
 *
 * The unique key of a running task is its task id.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteRunningTask extends ValueObject
{
    /** @var string The task id ({@see AgentEvalSuiteTask::$taskId}) */
    public string $taskId = '';

    /** @var string The case id */
    public string $caseId = '';

    /** @var string The suite */
    public string $suite = '';

    /** @var string The run key `<model>|<flavor>` */
    public string $runKey = '';

    /** @var string The lane `<scope>:<resource>` */
    public string $lane = '';

    /** @var string The task's access ({@see AgentEvalSuiteTask}`::ACCESS_*`) */
    public string $access = '';

    /** @var int|null The process id of the child process; null when the process reports none */
    public ?int $processId = null;

    /** @var int The whole seconds the child process has run */
    public int $seconds = 0;

    /** The running task `$agentEvalSuiteTask`, its child process `$processId` running for `$seconds` seconds. */
    public static function forTask(AgentEvalSuiteTask $agentEvalSuiteTask, ?int $processId, int $seconds): static
    {
        $agentEvalSuiteRunningTask = static::newInstance();
        $agentEvalSuiteRunningTask->taskId = $agentEvalSuiteTask->taskId;
        $agentEvalSuiteRunningTask->caseId = $agentEvalSuiteTask->caseId;
        $agentEvalSuiteRunningTask->suite = $agentEvalSuiteTask->suite;
        $agentEvalSuiteRunningTask->runKey = $agentEvalSuiteTask->getRunKey();
        $agentEvalSuiteRunningTask->lane = $agentEvalSuiteTask->getLane();
        $agentEvalSuiteRunningTask->access = $agentEvalSuiteTask->access;
        $agentEvalSuiteRunningTask->processId = $processId;
        $agentEvalSuiteRunningTask->seconds = $seconds;
        return $agentEvalSuiteRunningTask;
    }

    /**
     * The entry as the progress file lists it under `running`: part of the stored document of `<suite run>/_progress.json`.
     *
     * @return array{case: string, suite: string, run: string, lane: string, access: string, pid: int|null, seconds: int}
     */
    public function toArray(): array
    {
        return [
            'case' => $this->caseId,
            'suite' => $this->suite,
            'run' => $this->runKey,
            'lane' => $this->lane,
            'access' => $this->access,
            'pid' => $this->processId,
            'seconds' => $this->seconds,
        ];
    }

    /** The task id. */
    public function uniqueKey(): string
    {
        return self::class . '_' . $this->taskId;
    }
}
