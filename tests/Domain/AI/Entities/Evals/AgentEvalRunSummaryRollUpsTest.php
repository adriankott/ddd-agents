<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The four roll-ups of a tools summary are objects whose `toArray()` gives the stored `metrics` blocks: the task-plan
 * size (maximum and median per figure; the median of two values is their mean), the repetitions per case (first-seen
 * order), the plan-dynamics and the skill-preload figures — keys, order and value types as stored.
 */
class AgentEvalRunSummaryRollUpsTest extends UnitTestCase
{
    protected function buildRows(): AgentEvalCaseRows
    {
        return AgentEvalCaseRows::fromRowArrays([
            [
                'case' => 'a', 'passed' => true, 'durationMs' => 1000, 'toolCallCount' => 2,
                'taskPlanSize' => ['nodes' => 3, 'maxDepth' => 1, 'tailTokensMax' => 10, 'tailTokensSum' => 30],
                'planDynamics' => ['expectPlan' => 'direct', 'verdict' => 'planOnDirect', 'planCalls' => 2, 'retiredFinishedPlan' => true],
                'skillPreload' => ['judged' => 2, 'hits' => 1, 'wrongPicks' => 1, 'skips' => 0, 'notAsked' => 0, 'modelFirstLoadHits' => 1],
            ],
            [
                'case' => 'a', 'passed' => false, 'error' => 'the child process left no result', 'durationMs' => 3000, 'toolCallCount' => 4,
                'taskPlanSize' => ['nodes' => 5, 'maxDepth' => 2, 'tailTokensMax' => 20, 'tailTokensSum' => 50],
                'planDynamics' => ['expectPlan' => 'required', 'verdict' => 'missingOnRequired', 'planCalls' => 1],
            ],
            ['case' => 'b', 'passed' => true, 'durationMs' => 2000, 'toolCallCount' => 3],
            ['case' => 'c', 'passed' => null, 'outcome' => 'notApplicable'],
        ]);
    }

    public function testTheRollUpsGiveTheStoredMetricsBlocks(): void
    {
        $metrics = AgentEvalRunSummary::buildForToolsRun($this->buildRows(), 'VENDOR.MODEL', 'call', [], 2, 'on', 'shadow')->toArray()['metrics'];

        self::assertSame(['cases' => 2, 'nodesMax' => 5, 'nodesMedian' => 4, 'maxDepthMax' => 2, 'maxDepthMedian' => 1.5, 'tailTokensMaxMax' => 20, 'tailTokensMaxMedian' => 15, 'tailTokensSumMax' => 50, 'tailTokensSumMedian' => 40], $metrics['taskPlanSize']);
        self::assertSame([
            ['case' => 'a', 'runs' => 2, 'passed' => 1, 'errors' => 1, 'passRate' => 0.5],
            ['case' => 'b', 'runs' => 1, 'passed' => 1, 'errors' => 0, 'passRate' => 1.0],
        ], $metrics['repetitions'], 'a not-applicable row is left out');
        self::assertSame([
            'arm' => 'on', 'cases' => 2, 'directCases' => 1, 'plansOnDirect' => 1, 'plansOnDirectRate' => 1.0, 'requiredCases' => 1,
            'missingOnRequired' => 1, 'retiredPlans' => 1, 'avgPlanCallsPerCase' => 1.5, 'avgToolCallCount' => 3.0, 'avgDurationMs' => 2000.0,
        ], $metrics['planDynamics']);
        self::assertSame([
            'arm' => 'shadow', 'judgedTurns' => 2, 'hits' => 1, 'wrongPicks' => 1, 'skips' => 0, 'notAsked' => 0, 'hitRate' => 0.5,
            'wrongPickRate' => 0.5, 'modelFirstLoadHits' => 1, 'modelFirstLoadHitRate' => 0.5,
        ], $metrics['skillPreload']);
        self::assertSame(['totalDurationMs', 'totalTokens', 'totalCostUsd', 'avgDurationMs', 'avgTotalTokens', 'avgCostPerCaseUsd', 'avgToolCallCount', 'avgWorkToolCallCount', 'costPerPassUsd', 'performance', 'taskPlanSize', 'repetitions', 'planDynamics', 'skillPreload'], array_keys($metrics));
    }

    public function testWithoutBlocksAndWithoutRepetitionsTheRollUpsAreAbsent(): void
    {
        $rows = AgentEvalCaseRows::fromRowArrays([['case' => 'b', 'passed' => true, 'durationMs' => 2000]]);

        $agentEvalRunSummary = AgentEvalRunSummary::buildForToolsRun($rows, null, 'call', [], 1, 'on', 'shadow');
        $metrics = $agentEvalRunSummary->toArray()['metrics'];

        self::assertNull($agentEvalRunSummary->taskPlanSizeRollUp);
        self::assertNull($metrics['taskPlanSize'], 'the stored block is null without a plan');
        self::assertArrayNotHasKey('repetitions', $metrics);
        self::assertArrayNotHasKey('planDynamics', $metrics);
        self::assertArrayNotHasKey('skillPreload', $metrics);
        self::assertNull($rows->rollUpPlanDynamics('on'));
        self::assertNull($rows->rollUpSkillPreload('shadow'));
    }
}
