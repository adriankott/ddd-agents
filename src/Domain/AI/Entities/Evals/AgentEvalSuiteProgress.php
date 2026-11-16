<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The progress of a running suite run, as its progress file shows it: when it was written, the tasks of the run, how
 * many finished, passed and did not apply, how many wait in the queue, and the tasks whose child process runs.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteProgress extends ValueObject
{
    /** @var string When the progress was taken, `Y-m-d H:i:s` in the server's time zone */
    public string $takenAt = '';

    /** @var int The tasks of the run */
    public int $total = 0;

    /** @var int The tasks that finished */
    public int $finished = 0;

    /** @var int The finished tasks whose case passed */
    public int $passed = 0;

    /** @var int The finished tasks whose case does not apply */
    public int $notApplicable = 0;

    /** @var int The tasks still in the queue */
    public int $queued = 0;

    /** @var AgentEvalSuiteRunningTasks The tasks whose child process runs */
    public AgentEvalSuiteRunningTasks $runningTasks;

    /** The progress taken at the second `$takenAtTimestamp` (Unix time), before any count. */
    public static function takenAt(int $takenAtTimestamp): static
    {
        $agentEvalSuiteProgress = static::newInstance();
        $agentEvalSuiteProgress->takenAt = date('Y-m-d H:i:s', $takenAtTimestamp);
        $agentEvalSuiteProgress->runningTasks = AgentEvalSuiteRunningTasks::newInstance();
        return $agentEvalSuiteProgress;
    }

    /**
     * The stored document of `<suite run>/_progress.json`.
     *
     * @return array{at: string, total: int, finished: int, passed: int, notApplicable: int, queued: int, running: array}
     */
    public function toArray(): array
    {
        return [
            'at' => $this->takenAt,
            'total' => $this->total,
            'finished' => $this->finished,
            'passed' => $this->passed,
            'notApplicable' => $this->notApplicable,
            'queued' => $this->queued,
            'running' => $this->runningTasks->toArrays(),
        ];
    }
}
