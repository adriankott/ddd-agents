<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The plan of a suite run in numbers, for the dry run and the suite record: tasks per suite, access and run, the write
 * lanes with their length, the waves per wave group, and the wall time of a simulated schedule — the schedule itself
 * run over the duration priors of the tasks ({@see AgentEvalSuiteSchedule::summarizePlan()}), so the estimate follows
 * every rule the real run follows. {@see $boundBy} names what finished last.
 *
 * {@see toArray()} gives the plan with the keys and in the order a suite record stores it.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuitePlanSummary extends ValueObject
{
    /** @var int The planned tasks */
    public int $tasks = 0;

    /** @var int[] Suite → its tasks, in the order the suites first occur */
    public array $tasksBySuite = [];

    /** @var int[] Access → its tasks, in the order the accesses first occur */
    public array $tasksByAccess = [];

    /** @var int[] Run key (`<model>|<flavor>`) → its tasks */
    public array $tasksByRun = [];

    /** @var int[] Write lane key → its writing tasks, the longest lane first */
    public array $writingTasksByLane = [];

    /** @var int[] Wave group → its number of waves */
    public array $wavesByWaveGroup = [];

    /** @var int The seconds of every task one after the other, by the duration priors */
    public int $sequentialWallSeconds = 0;

    /** @var int The seconds of the simulated schedule */
    public int $estimatedWallSeconds = 0;

    /** @var string What finished last in the simulated schedule: `lane <key>`, `waves <group>`, a concurrency group or `the read pool` */
    public string $boundBy = '';

    /** @var int The tasks that could never start under the schedule's rules (0 for a consistent plan) */
    public int $unschedulableTasks = 0;

    /**
     * The plan as a suite record stores it under `plan`: part of the stored document of `<suite run>/_suite.json`.
     *
     * @return array{tasks: int, bySuite: int[], byAccess: int[], byRun: int[], lanes: int[], waves: int[], sequentialWallSeconds: int, estimatedWallSeconds: int, boundBy: string, unschedulableTasks: int}
     */
    public function toArray(): array
    {
        return [
            'tasks' => $this->tasks,
            'bySuite' => $this->tasksBySuite,
            'byAccess' => $this->tasksByAccess,
            'byRun' => $this->tasksByRun,
            'lanes' => $this->writingTasksByLane,
            'waves' => $this->wavesByWaveGroup,
            'sequentialWallSeconds' => $this->sequentialWallSeconds,
            'estimatedWallSeconds' => $this->estimatedWallSeconds,
            'boundBy' => $this->boundBy,
            'unschedulableTasks' => $this->unschedulableTasks,
        ];
    }
}
