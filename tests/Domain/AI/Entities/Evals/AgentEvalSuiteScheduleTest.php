<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteSchedule;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The schedule of a parallel suite — pure, so every lane rule is pinned here: what runs beside what, what waits for
 * what, the start order, waves and concurrency groups, and the wall-time estimate of a simulated schedule.
 */
class AgentEvalSuiteScheduleTest extends UnitTestCase
{
    public function testReadsRunBesideWritesOfOtherResourcesAndNeverBesideTheirOwn(): void
    {
        $profileWrite = $this->task('w1', AgentEvalSuiteTask::ACCESS_WRITE, 1, 'Notes.Profile');
        $profileRead = $this->task('r1', AgentEvalSuiteTask::ACCESS_READ, 1, 'Notes.Profile');
        $rankingsRead = $this->task('r2', AgentEvalSuiteTask::ACCESS_READ, 1, 'Notes.Rankings');
        $otherScopeRead = $this->task('r3', AgentEvalSuiteTask::ACCESS_READ, 2, 'Notes.Profile');
        $schedule = AgentEvalSuiteSchedule::forProcesses(8);

        self::assertSame(['r2', 'r3'], $this->select($schedule, [$profileRead, $rankingsRead, $otherScopeRead], [$profileWrite]), 'while the write runs, the profile read waits');
        self::assertSame(['r1'], $this->select($schedule, [$profileRead, $profileWrite], []), 'the write waits for the queued read of its resource');
        self::assertSame([], $this->select($schedule, [$profileWrite], [$profileRead]), 'and still waits while that read runs');
        self::assertSame(['w1', 'r2'], $this->select($schedule, [$rankingsRead, $profileWrite], []), 'a queued read of another resource does not hold the write back');

        $isolatingSchedule = AgentEvalSuiteSchedule::forProcesses(8, 0.0, true);
        self::assertSame(['r2'], $this->select($isolatingSchedule, [$rankingsRead, $profileWrite], []), 'isolating the scope: every read of it excludes the write');
        self::assertSame(['r3'], $this->select($isolatingSchedule, [$rankingsRead, $otherScopeRead], [$profileWrite]));
    }

    public function testTwoWritesShareAScopeOnlyOnDifferentResourcesAcrossModelsAndFlavors(): void
    {
        $profileWriteCall = $this->task('profile|call', AgentEvalSuiteTask::ACCESS_WRITE, 1, 'Notes.Profile');
        $profileWriteJudge = $this->task('profile|judge', AgentEvalSuiteTask::ACCESS_WRITE, 1, 'Notes.Profile', 'judge');
        $profileWriteOtherModel = $this->task('profile|other', AgentEvalSuiteTask::ACCESS_WRITE, 1, 'Notes.Profile', 'call', 'OTHER');
        $postsWrite = $this->task('posts', AgentEvalSuiteTask::ACCESS_WRITE, 1, 'posts');
        $profileWriteElsewhere = $this->task('profile|elsewhere', AgentEvalSuiteTask::ACCESS_WRITE, 2, 'Notes.Profile');
        $schedule = AgentEvalSuiteSchedule::forProcesses(8);

        self::assertSame(
            ['profile|call', 'posts', 'profile|elsewhere'],
            $this->select($schedule, [$profileWriteCall, $profileWriteJudge, $profileWriteOtherModel, $postsWrite, $profileWriteElsewhere], [])
        );
        self::assertSame(['profile|judge'], $this->select($schedule, [$profileWriteJudge, $profileWriteOtherModel], [$postsWrite]));
    }

    public function testAnExclusiveTaskRunsAfterEverythingElseOfItsScopeAndAlone(): void
    {
        $grant = $this->task('grant', AgentEvalSuiteTask::ACCESS_EXCLUSIVE, 1, 'Assistant.Grants');
        $secondGrant = $this->task('grant2', AgentEvalSuiteTask::ACCESS_EXCLUSIVE, 1, 'Assistant.Grants');
        $read = $this->task('read', AgentEvalSuiteTask::ACCESS_READ, 1, 'Notes.Rankings');
        $write = $this->task('write', AgentEvalSuiteTask::ACCESS_WRITE, 1, 'posts');
        $elsewhere = $this->task('elsewhere', AgentEvalSuiteTask::ACCESS_READ, 2, 'Notes.Rankings');
        $schedule = AgentEvalSuiteSchedule::forProcesses(8);

        self::assertSame(['write', 'read'], $this->select($schedule, [$read, $write, $grant], []), 'queued work of the scope comes first');
        self::assertSame([], $this->select($schedule, [$grant], [$read]), 'not beside a running read');
        self::assertSame(['grant'], $this->select($schedule, [$grant, $secondGrant], [$elsewhere]), 'one at a time; another scope does not matter');
        self::assertSame(['elsewhere'], $this->select($schedule, [$read, $elsewhere], [$grant]), 'nothing of its scope beside it');
    }

    public function testATaskWithoutScopeOrLiveStateRunsWheneverASlotIsFree(): void
    {
        $unscopedWrite = $this->task('unscoped', AgentEvalSuiteTask::ACCESS_WRITE, null, 'Notes.Profile');
        $throwaway = $this->task('throwaway', AgentEvalSuiteTask::ACCESS_NONE, 1, 'Notes.Profile');
        $exclusive = $this->task('exclusive', AgentEvalSuiteTask::ACCESS_EXCLUSIVE, 1, 'Assistant.Grants');

        self::assertSame(['unscoped', 'throwaway'], $this->select(AgentEvalSuiteSchedule::forProcesses(8), [$unscopedWrite, $throwaway], [$exclusive]));
    }

    public function testTheProcessLimitBoundsEveryPassAndWritesTakeTheSlotsFirst(): void
    {
        $reads = [];
        for ($index = 0; $index < 6; $index++) {
            $reads[] = $this->task('read' . $index, AgentEvalSuiteTask::ACCESS_READ, 1, 'Notes.Rankings');
        }
        $write = $this->task('write', AgentEvalSuiteTask::ACCESS_WRITE, 1, 'posts');
        $schedule = AgentEvalSuiteSchedule::forProcesses(3);

        self::assertSame(['write', 'read0', 'read1'], $this->select($schedule, array_merge($reads, [$write]), []));
        self::assertSame([], $this->select($schedule, $reads, [$write, $reads[0], $reads[1]]));
        self::assertSame(['read2'], $this->select($schedule, array_slice($reads, 2), [$write, $reads[0]]));
    }

    public function testAWaveStartsOnlyWhenEveryTaskOfAnEarlierWaveOfItsGroupFinished(): void
    {
        $firstWave = $this->wavedTask('a', 'shared:7', 0);
        $secondFirstWave = $this->wavedTask('b', 'shared:7', 0);
        $secondWave = $this->wavedTask('c', 'shared:7', 1);
        $otherGroupSecondWave = $this->wavedTask('d', 'shared:8', 1);
        $otherGroupFirstWave = $this->wavedTask('e', 'shared:8', 0);
        $schedule = AgentEvalSuiteSchedule::forProcesses(8);

        self::assertSame(['a', 'b', 'e'], $this->select($schedule, [$firstWave, $secondFirstWave, $secondWave, $otherGroupSecondWave, $otherGroupFirstWave], []), 'wave 0 of both groups');
        self::assertSame([], $this->select($schedule, [$secondWave], [$secondFirstWave]), 'wave 1 waits while one task of wave 0 still runs');
        self::assertSame(['c'], $this->select($schedule, [$secondWave], [$otherGroupFirstWave]), 'the other group\'s wave does not hold it back');
        self::assertSame(
            ['a', 'b', 'c', 'e', 'd'],
            AgentEvalSuiteSchedule::forProcesses(8)->orderTasksForStart(AgentEvalSuiteTasks::fromTasks($firstWave, $secondFirstWave, $secondWave, $otherGroupSecondWave, $otherGroupFirstWave))->getTaskIds(),
            'within a group a later wave starts after an earlier one; across groups the planned order stays'
        );
    }

    public function testAConcurrencyGroupRunsNoMoreTasksAtOnceThanItsLimit(): void
    {
        $tasks = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $taskId) {
            $task = $this->task($taskId, AgentEvalSuiteTask::ACCESS_NONE, 7, 'pool');
            $task->concurrencyGroup = 'pool:7,8,9';
            $task->concurrencyLimit = 3;
            $tasks[] = $task;
        }
        $schedule = AgentEvalSuiteSchedule::forProcesses(8);

        self::assertSame(['a', 'b', 'c'], $this->select($schedule, $tasks, []), 'one task per pool item');
        self::assertSame([], $this->select($schedule, [$tasks[3], $tasks[4]], [$tasks[0], $tasks[1], $tasks[2]]), 'every pool item is in use');
        self::assertCount(1, $this->select($schedule, [$tasks[3], $tasks[4]], [$tasks[0], $tasks[1]]), 'a finished task frees one item');
        $plan = $schedule->summarizePlan(AgentEvalSuiteTasks::fromTasks(...$tasks));
        self::assertSame(0, $plan->unschedulableTasks);
        self::assertSame('pool:7,8,9', $plan->boundBy);
    }

    public function testTheEstimateFollowsTheLongestLaneNotTheNumberOfTasks(): void
    {
        $secondsPerTask = 35.0;
        $tasks = [];
        for ($index = 0; $index < 10; $index++) {
            $tasks[] = $this->task('profile' . $index, AgentEvalSuiteTask::ACCESS_WRITE, 1, 'externalProfile', 'call', 'MODEL', $secondsPerTask);
        }
        for ($index = 0; $index < 4; $index++) {
            $tasks[] = $this->task('posts' . $index, AgentEvalSuiteTask::ACCESS_WRITE, 1, 'posts', 'call', 'MODEL', $secondsPerTask);
        }
        for ($index = 0; $index < 20; $index++) {
            $tasks[] = $this->task('read' . $index, AgentEvalSuiteTask::ACCESS_READ, 1, 'Notes.Rankings', 'call', 'MODEL', $secondsPerTask);
        }
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::fromTasks(...$tasks);
        $plan = AgentEvalSuiteSchedule::forProcesses(12)->summarizePlan($agentEvalSuiteTasks);

        self::assertSame(34, $plan->tasks);
        self::assertSame(0, $plan->unschedulableTasks);
        self::assertSame(['1:externalProfile' => 10, '1:posts' => 4], $plan->writingTasksByLane);
        $mixed = [
            $this->task('rankingsRead', AgentEvalSuiteTask::ACCESS_READ, 1, 'Notes.Rankings'),
            $this->task('postsRead', AgentEvalSuiteTask::ACCESS_READ, 1, 'posts'),
            $this->task('profileRead', AgentEvalSuiteTask::ACCESS_READ, 1, 'externalProfile'),
        ];
        self::assertSame(
            ['profileRead', 'postsRead', 'rankingsRead'],
            array_slice(AgentEvalSuiteSchedule::forProcesses(12)->orderTasksForStart(AgentEvalSuiteTasks::fromTasks(...array_merge($mixed, $tasks)))->getTaskIds(), 0, 3),
            'the reads a lane waits for lead the queue, the longest lane\'s first'
        );
        self::assertSame((int)round(34 * $secondsPerTask), $plan->sequentialWallSeconds);
        self::assertSame((int)round(10 * $secondsPerTask), $plan->estimatedWallSeconds, 'ten writes of one lane, one after the other, beside everything else');
        self::assertSame('lane 1:externalProfile', $plan->boundBy);

        self::assertSame((int)round(34 * $secondsPerTask), AgentEvalSuiteSchedule::forProcesses(1)->summarizePlan($agentEvalSuiteTasks)->estimatedWallSeconds, 'one process: everything one after the other');
        $staggered = AgentEvalSuiteSchedule::forProcesses(12, 5.0)->summarizePlan($agentEvalSuiteTasks);
        self::assertSame(0, $staggered->unschedulableTasks);
        self::assertGreaterThanOrEqual($plan->estimatedWallSeconds, $staggered->estimatedWallSeconds, 'a stagger delays the starts, never the lanes\' order');
    }

    public function testThePlanSummaryKeepsTheStoredKeysAndOrder(): void
    {
        $waved = $this->wavedTask('waved', 'shared:7', 1);
        $plan = AgentEvalSuiteSchedule::forProcesses(4)->summarizePlan(AgentEvalSuiteTasks::fromTasks(
            $this->task('write', AgentEvalSuiteTask::ACCESS_WRITE, 1, 'posts', 'call', 'MODEL', 10.0),
            $this->task('read', AgentEvalSuiteTask::ACCESS_READ, 1, 'Notes.Rankings', 'judge', 'MODEL', 20.0),
            $waved
        ));

        self::assertSame([
            'tasks' => 3,
            'bySuite' => ['tools' => 3],
            'byAccess' => ['write' => 1, 'read' => 1, 'none' => 1],
            'byRun' => ['MODEL|call' => 2, 'MODEL|judge' => 1],
            'lanes' => ['1:posts' => 1],
            'waves' => ['shared:7' => 2],
            'sequentialWallSeconds' => 90,
            'estimatedWallSeconds' => 60,
            'boundBy' => 'waves shared:7',
            'unschedulableTasks' => 0,
        ], $plan->toArray());
    }

    public function testATaskKnowsItsRunItsLaneAndItsRunFolder(): void
    {
        $task = $this->task('t', AgentEvalSuiteTask::ACCESS_EXCLUSIVE, 4, 'Assistant.Grants', 'judge', 'VENDOR.MODEL 5/large');

        self::assertSame('VENDOR.MODEL 5/large|judge', $task->getRunKey());
        self::assertSame('VENDOR.MODEL_5_large-judge', $task->getRunFolderName());
        self::assertSame('4:Assistant.Grants', $task->getLane());
        self::assertSame('4:*', $task->getWriteLaneKey());
        self::assertTrue($task->writesLiveState());
        $defaultModelTask = $this->task('d', AgentEvalSuiteTask::ACCESS_READ, null, 'Notes');
        $defaultModelTask->modelName = null;
        self::assertSame('default|call', $defaultModelTask->getRunKey());
        self::assertSame(':Notes', $defaultModelTask->getLane());
    }

    public function testASetRemovesTasksByTheirIdAndPutsARetriedTaskFirst(): void
    {
        $first = $this->task('first', AgentEvalSuiteTask::ACCESS_READ, 1, 'a');
        $second = $this->task('second', AgentEvalSuiteTask::ACCESS_READ, 1, 'a');
        $sameIdAsFirst = $this->task('first', AgentEvalSuiteTask::ACCESS_WRITE, 2, 'b');
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::fromTasks($first, $second, $sameIdAsFirst);

        self::assertSame(['first', 'second', 'first'], $agentEvalSuiteTasks->getTaskIds(), 'a planned task is never dropped');
        self::assertSame($first, $agentEvalSuiteTasks->getByTaskId('first'));
        $agentEvalSuiteTasks->removeTask($first);
        self::assertSame(['second'], $agentEvalSuiteTasks->getTaskIds(), 'every task of the id is removed, as a started task leaves the queue');
        $agentEvalSuiteTasks->removeTask($first);
        self::assertSame(1, $agentEvalSuiteTasks->count(), 'removing a task the set does not hold changes nothing');
        $agentEvalSuiteTasks->prependTask($first);
        self::assertSame(['first', 'second'], $agentEvalSuiteTasks->getTaskIds());
        self::assertSame(['MODEL-call' => ['first', 'second']], ['MODEL-call' => $agentEvalSuiteTasks->buildPlannedCaseIdsByRunFolder()->getCaseIdsOfRunFolder('MODEL-call')]);
    }

    /**
     * @param AgentEvalSuiteTask[] $queuedTasks
     * @param AgentEvalSuiteTask[] $runningTasks
     * @return string[]
     */
    protected function select(AgentEvalSuiteSchedule $schedule, array $queuedTasks, array $runningTasks): array
    {
        return $schedule->selectRunnableTasks(AgentEvalSuiteTasks::fromTasks(...$queuedTasks), AgentEvalSuiteTasks::fromTasks(...$runningTasks))->getTaskIds();
    }

    protected function task(string $taskId, string $access, ?int $agentSpaceId, string $resourceKey, string $flavor = 'call', string $modelName = 'MODEL', float $estimatedSeconds = 60.0): AgentEvalSuiteTask
    {
        $task = AgentEvalSuiteTask::newInstance();
        $task->taskId = $taskId;
        $task->caseId = $taskId;
        $task->suite = 'tools';
        $task->access = $access;
        $task->agentSpaceId = $agentSpaceId;
        $task->resourceKey = $resourceKey;
        $task->flavor = $flavor;
        $task->modelName = $modelName;
        $task->estimatedSeconds = $estimatedSeconds;
        return $task;
    }

    protected function wavedTask(string $taskId, string $waveGroup, int $waveIndex): AgentEvalSuiteTask
    {
        $task = $this->task($taskId, AgentEvalSuiteTask::ACCESS_NONE, 7, 'shared');
        $task->waveGroup = $waveGroup;
        $task->waveIndex = $waveIndex;
        return $task;
    }
}
