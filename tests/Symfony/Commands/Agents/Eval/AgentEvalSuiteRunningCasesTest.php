<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunningCase;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalSuiteRunningCases;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Process\Process;

/**
 * The cases whose child processes run: one per task id, in the order they started; the schedule reads their tasks,
 * the progress file their entries with the whole seconds each has run.
 */
class AgentEvalSuiteRunningCasesTest extends UnitTestCase
{
    public function testTheRunningCasesGiveTheirTasksAndTheirProgressEntries(): void
    {
        $agentEvalSuiteRunningCases = AgentEvalSuiteRunningCases::newInstance();
        $firstCase = AgentEvalSuiteRunningCase::forStartedProcess($this->task('a', 7), new Process(['php', '-v']), 100.0, '/run/.result-tools-a.json');
        $secondCase = AgentEvalSuiteRunningCase::forStartedProcess($this->task('b', 8), new Process(['php', '-v']), 103.6, '/run/.result-tools-b.json');
        $agentEvalSuiteRunningCases->add($firstCase);
        $agentEvalSuiteRunningCases->add($secondCase);

        self::assertSame(['m|call|tools|a', 'm|call|tools|b'], $agentEvalSuiteRunningCases->buildTasks()->getTaskIds());
        self::assertSame($secondCase, $agentEvalSuiteRunningCases->getByTaskId('m|call|tools|b'));
        self::assertNull($agentEvalSuiteRunningCases->getByTaskId('m|call|tools|c'));
        self::assertSame(
            [
                ['case' => 'a', 'suite' => 'tools', 'run' => 'm|call', 'lane' => '7:catalog', 'access' => 'read', 'pid' => null, 'seconds' => 10],
                ['case' => 'b', 'suite' => 'tools', 'run' => 'm|call', 'lane' => '8:catalog', 'access' => 'read', 'pid' => null, 'seconds' => 6],
            ],
            $agentEvalSuiteRunningCases->buildRunningTasks(109.6)->toArrays()
        );
        self::assertEqualsWithDelta(9.6, $firstCase->getRunSeconds(109.6), 0.0001);

        $agentEvalSuiteRunningCases->remove($firstCase);
        self::assertSame(['m|call|tools|b'], $agentEvalSuiteRunningCases->buildTasks()->getTaskIds());
    }

    protected function task(string $caseId, int $agentSpaceId): AgentEvalSuiteTask
    {
        $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
        $agentEvalSuiteTask->caseId = $caseId;
        $agentEvalSuiteTask->suite = 'tools';
        $agentEvalSuiteTask->modelName = 'm';
        $agentEvalSuiteTask->agentSpaceId = $agentSpaceId;
        $agentEvalSuiteTask->resourceKey = 'catalog';
        $agentEvalSuiteTask->taskId = 'm|call|tools|' . $caseId;
        return $agentEvalSuiteTask;
    }
}
