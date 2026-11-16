<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowsByRun;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The state of one suite run while its child processes run: the queue in start order, the running cases, the rows of
 * the finished cases per run, the retried cases, the schedule that decides what may start, the run folder, the arms
 * every child gets, and the counters and moments the start rules and the progress file read (the first case runs
 * alone until it finished or its head start passed; afterwards one start per stagger interval).
 *
 * A plain object of one command run; no container service, never stored.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentEvalSuiteRunState extends ValueObject
{
    /** @var AgentEvalSuiteTasks The tasks not started yet, in start order */
    public AgentEvalSuiteTasks $queue;

    /** @var AgentEvalSuiteRunningCases The cases whose child process runs */
    public AgentEvalSuiteRunningCases $runningCases;

    /** @var AgentEvalCaseRowsByRun The rows of the finished cases per run */
    public AgentEvalCaseRowsByRun $rowsByRun;

    /** @var AgentEvalSuiteRetriedCases The cases that run once more after an infrastructure error */
    public AgentEvalSuiteRetriedCases $retriedCases;

    /** @var AgentEvalSuiteSchedule What may run beside what, and how many processes at a time */
    public AgentEvalSuiteSchedule $schedule;

    /** @var string The folder of the suite run */
    public string $suiteDirectory = '';

    /** @var string[] The arms of the run: option name → value, handed to every child */
    public array $arms = [];

    /** @var int Seconds the first case runs alone unless it finishes earlier */
    public int $headStartSeconds = 0;

    /** @var int The tasks of the run */
    public int $totalTasks = 0;

    /** @var int The tasks that finished (a retried task once) */
    public int $finishedCount = 0;

    /** @var int The finished tasks whose case passed */
    public int $passedCount = 0;

    /** @var int The finished tasks whose case does not apply */
    public int $notApplicableCount = 0;

    /** @var float|null The moment the first case started (`microtime(true)`); null before */
    public ?float $firstStartAtMicrotime = null;

    /** @var bool Whether a case finished (the head start is over) */
    public bool $firstTaskFinished = false;

    /** @var float The moment the last case started (`microtime(true)`) */
    public float $lastStartAtMicrotime = 0.0;

    /** @var float The moment the progress file was written last (`microtime(true)`) */
    public float $lastProgressAtMicrotime = 0.0;

    /**
     * The state of a run of the tasks `$queue` (in start order) in the folder `$suiteDirectory` under `$schedule`.
     *
     * @param AgentEvalSuiteTasks $queue the tasks in start order
     * @param AgentEvalSuiteSchedule $schedule
     * @param string $suiteDirectory
     * @param string[] $arms option name → value
     * @param int $headStartSeconds
     * @return static
     */
    public static function forQueue(AgentEvalSuiteTasks $queue, AgentEvalSuiteSchedule $schedule, string $suiteDirectory, array $arms, int $headStartSeconds): static
    {
        $agentEvalSuiteRunState = static::newInstance();
        $agentEvalSuiteRunState->queue = $queue;
        $agentEvalSuiteRunState->runningCases = AgentEvalSuiteRunningCases::newInstance();
        $agentEvalSuiteRunState->rowsByRun = AgentEvalCaseRowsByRun::newInstance();
        $agentEvalSuiteRunState->retriedCases = AgentEvalSuiteRetriedCases::newInstance();
        $agentEvalSuiteRunState->schedule = $schedule;
        $agentEvalSuiteRunState->suiteDirectory = $suiteDirectory;
        $agentEvalSuiteRunState->arms = $arms;
        $agentEvalSuiteRunState->headStartSeconds = $headStartSeconds;
        $agentEvalSuiteRunState->totalTasks = $queue->count();
        return $agentEvalSuiteRunState;
    }

    /** Whether a task waits or runs. */
    public function hasWork(): bool
    {
        return $this->queue->count() > 0 || $this->runningCases->count() > 0;
    }

    /**
     * Whether a case may start at `$nowMicrotime`: a task waits, the head start is over (nothing started yet, a case
     * finished, or the first case ran its head start) and the stagger interval since the last start passed.
     */
    public function isStartDue(float $nowMicrotime): bool
    {
        $headStartOver = $this->firstStartAtMicrotime === null
            || $this->firstTaskFinished
            || ($nowMicrotime - $this->firstStartAtMicrotime) >= $this->headStartSeconds;
        return $this->queue->count() > 0 && $headStartOver && ($nowMicrotime - $this->lastStartAtMicrotime) >= $this->schedule->startStaggerSeconds;
    }

    /** Whether the next start takes one task only: the first start, or a run with a stagger interval. */
    public function startsOneAtATime(): bool
    {
        return $this->firstStartAtMicrotime === null || $this->schedule->startStaggerSeconds > 0;
    }

    /** Records a start at `$startedAtMicrotime`. */
    public function recordStart(float $startedAtMicrotime): void
    {
        $this->firstStartAtMicrotime ??= $startedAtMicrotime;
        $this->lastStartAtMicrotime = $startedAtMicrotime;
    }

    /** Counts the finished case of `$agentEvalCaseRow` (the head start is over). */
    public function countFinishedRow(AgentEvalCaseRow $agentEvalCaseRow): void
    {
        $this->finishedCount++;
        $this->passedCount += $agentEvalCaseRow->isPassed() ? 1 : 0;
        $this->notApplicableCount += $agentEvalCaseRow->isNotApplicable() ? 1 : 0;
        $this->firstTaskFinished = true;
    }
}
