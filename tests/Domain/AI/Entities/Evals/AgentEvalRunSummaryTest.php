<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunSummary;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The summary of one run in its two formats: the suite's run summary and the tools evaluation's summary — counts,
 * totals of the timed rows, the performance roll-up, the rolled-up decision figures, keys and order as written.
 */
class AgentEvalRunSummaryTest extends UnitTestCase
{
    use AgentEvalCaseRowsFixtureTrait;

    public function testTheSuiteSummaryKeepsItsFormatAndDropsTheTraces(): void
    {
        $passing = $this->measuredRow('a', true, 2, 1, [10.0], 20000, 0.004, 400, 18000, 15000) + ['durationMs' => 11000, 'toolCallCount' => 2, 'workToolCallCount' => 1, 'messages' => ['a long trace']];
        $failing = $this->measuredRow('b', false, 6, 3, [8.0, 12.0, 4.0], 60000, 0.012, 1300, 55000, 50000) + ['durationMs' => 30000, 'toolCallCount' => 6, 'workToolCallCount' => 4];

        $summary = AgentEvalRunSummary::buildForSuite(
            $this->rows($passing, $failing, ['case' => 'c', 'passed' => false, 'error' => 'timed out']),
            'VENDOR.MODEL',
            'judge',
            ['reasoning-effort' => 'low']
        )->toArray();

        self::assertSame(['total', 'passed', 'errors', 'notApplicable', 'model', 'flavor', 'arms', 'metrics', 'cases'], array_keys($summary));
        self::assertSame(3, $summary['total']);
        self::assertSame(1, $summary['passed']);
        self::assertSame(1, $summary['errors']);
        self::assertSame('VENDOR.MODEL', $summary['model']);
        self::assertSame('judge', $summary['flavor']);
        self::assertSame(['reasoning-effort' => 'low'], $summary['arms']);
        self::assertSame(41000, $summary['metrics']['totalDurationMs']);
        self::assertSame(80000, $summary['metrics']['totalTokens']);
        self::assertSame(0.016, $summary['metrics']['totalCostUsd']);
        self::assertSame(0.008, $summary['metrics']['avgCostPerCaseUsd']);
        self::assertSame(0.016, $summary['metrics']['costPerPassUsd']);
        self::assertSame(4.0, $summary['metrics']['avgToolCallCount']);
        self::assertSame(4.0, $summary['metrics']['performance']['avgModelCallsPerCase']);
        self::assertSame(
            ['totalDurationMs', 'totalTokens', 'totalCostUsd', 'avgDurationMs', 'avgTotalTokens', 'avgCostPerCaseUsd', 'avgToolCallCount', 'avgWorkToolCallCount', 'costPerPassUsd', 'performance'],
            array_keys($summary['metrics'])
        );
        self::assertArrayNotHasKey('messages', $summary['cases'][0]);
        self::assertCount(3, $summary['cases']);
    }

    public function testANotApplicableRowLeavesThePassRateUntouched(): void
    {
        $notApplicableRow = AgentEvalCaseRow::buildNotApplicable(
            ['skill' => 'Notes.Mentions', 'case' => 'mentions.list'],
            AgentEvalNotApplicableReason::withReasonAndDetail(AgentEvalNotApplicableReason::REASON_SKILL_NOT_AVAILABLE, 'withheld')
        );
        self::assertTrue($notApplicableRow->isNotApplicable());
        self::assertNull($notApplicableRow->toArray()['passed']);

        $summary = AgentEvalRunSummary::buildForSuite(
            $this->rows(
                ['case' => 'a', 'passed' => true, 'durationMs' => 1000, 'costUsd' => 0.002],
                ['case' => 'b', 'passed' => false, 'durationMs' => 1000, 'costUsd' => 0.002],
                $notApplicableRow->toArray()
            ),
            'VENDOR.MODEL',
            'call'
        )->toArray();

        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['passed']);
        self::assertSame(1, $summary['notApplicable']);
        self::assertCount(3, $summary['cases']);
    }

    public function testAJudgedRowWithAnEvaluationErrorCountsAsAnErrorNotAsAPass(): void
    {
        $errorVerdict = AgentEvalJudgeVerdict::fromJudgeOutput('{"verdict":"PASS","scorePercent":91,"criteria":[{"name":"the entries are list');
        $errorVerdict->markAsEvaluationError('Judge output truncated twice — evaluation error, not a verdict.');
        $caseRows = $this->rows(
            ['case' => 'a', 'passed' => true, 'flavor' => 'judge', 'verdict' => ['verdict' => 'PASS']],
            ['case' => 'b', 'passed' => false, 'flavor' => 'judge', 'verdict' => $errorVerdict->toArray(), 'error' => 'judge evaluation error: cut off twice']
        );

        $runSummary = AgentEvalRunSummary::buildForSuite($caseRows, 'model', 'judge');

        self::assertSame(2, $runSummary->total);
        self::assertSame(1, $runSummary->passed);
        self::assertSame(1, $runSummary->errors);
        self::assertSame(1, $caseRows->rollUpRepetitionsByCase()->toArrays()[1]['errors']);
    }

    public function testTheToolsSummaryKeepsItsFormatAndItsRolledUpDecisionFigures(): void
    {
        $planRow = ['case' => 'plan.direct', 'passed' => true, 'durationMs' => 2000, 'toolCallCount' => 3, 'workToolCallCount' => 1, 'totalTokens' => 100, 'costUsd' => 0.0123,
            'taskPlanSize' => ['nodes' => 4, 'maxDepth' => 2, 'tailTokensMax' => 300, 'tailTokensSum' => 900],
            'planDynamics' => ['expectPlan' => 'direct', 'verdict' => 'planOnDirect', 'planCalls' => 3, 'retiredFinishedPlan' => true],
            'skillPreload' => ['judged' => 2, 'hits' => 1, 'wrongPicks' => 1, 'skips' => 0, 'notAsked' => 0, 'modelFirstLoadHits' => 2],
            'messages' => ['trace'],
        ];
        $secondRun = ['case' => 'plan.direct', 'passed' => false, 'durationMs' => 4000, 'toolCallCount' => 5, 'workToolCallCount' => 2, 'totalTokens' => 300, 'costUsd' => 0.0047,
            'taskPlanSize' => ['nodes' => 6, 'maxDepth' => 3, 'tailTokensMax' => 500, 'tailTokensSum' => 1500],
            'planDynamics' => ['expectPlan' => 'required', 'verdict' => 'missingOnRequired', 'planCalls' => 0],
            'skillPreload' => ['judged' => 1, 'hits' => 1, 'modelFirstLoadHits' => 0],
        ];
        $errorRow = ['case' => 'other', 'passed' => false, 'error' => 'no parseable JSON from child process', 'repetition' => 1];
        $caseRows = $this->rows($planRow, $secondRun, $errorRow);

        $summary = AgentEvalRunSummary::buildForToolsRun($caseRows, 'VENDOR.MODEL', 'call', ['plan-dynamics' => 'on'], 2, 'on', 'default');

        $summaryArray = $summary->toArray();
        self::assertSame(['total', 'passed', 'notApplicable', 'model', 'flavor', 'arms', 'repeat', 'metrics', 'cases'], array_keys($summaryArray));
        self::assertSame(3, $summaryArray['total']);
        self::assertSame(2, $summaryArray['repeat']);
        self::assertSame(
            ['totalDurationMs', 'totalTokens', 'totalCostUsd', 'avgDurationMs', 'avgTotalTokens', 'avgCostPerCaseUsd', 'avgToolCallCount', 'avgWorkToolCallCount', 'costPerPassUsd', 'performance', 'taskPlanSize', 'repetitions', 'planDynamics', 'skillPreload'],
            array_keys($summaryArray['metrics'])
        );
        self::assertSame(0.01, $summaryArray['metrics']['avgCostPerCaseUsd'], 'the tools summary rounds the average cost to two decimals first');
        self::assertSame(0.017, $summaryArray['metrics']['totalCostUsd']);
        self::assertSame(0.017, $summaryArray['metrics']['costPerPassUsd']);
        self::assertSame(['cases' => 2, 'nodesMax' => 6, 'nodesMedian' => 5, 'maxDepthMax' => 3, 'maxDepthMedian' => 2.5, 'tailTokensMaxMax' => 500, 'tailTokensMaxMedian' => 400, 'tailTokensSumMax' => 1500, 'tailTokensSumMedian' => 1200], $summaryArray['metrics']['taskPlanSize']);
        self::assertSame([
            ['case' => 'plan.direct', 'runs' => 2, 'passed' => 1, 'errors' => 0, 'passRate' => 0.5],
            ['case' => 'other', 'runs' => 1, 'passed' => 0, 'errors' => 1, 'passRate' => 0.0],
        ], $summaryArray['metrics']['repetitions']);
        self::assertSame([
            'arm' => 'on', 'cases' => 2, 'directCases' => 1, 'plansOnDirect' => 1, 'plansOnDirectRate' => 1.0, 'requiredCases' => 1, 'missingOnRequired' => 1,
            'retiredPlans' => 1, 'avgPlanCallsPerCase' => 1.5, 'avgToolCallCount' => 4.0, 'avgDurationMs' => 3000.0,
        ], $summaryArray['metrics']['planDynamics']);
        self::assertSame([
            'arm' => 'default', 'judgedTurns' => 3, 'hits' => 2, 'wrongPicks' => 1, 'skips' => 0, 'notAsked' => 0, 'hitRate' => 0.667,
            'wrongPickRate' => 0.333, 'modelFirstLoadHits' => 2, 'modelFirstLoadHitRate' => 0.667,
        ], $summaryArray['metrics']['skillPreload']);
        self::assertArrayNotHasKey('messages', $summaryArray['cases'][0], 'the summary file holds no traces');
        self::assertSame(['trace'], $summary->toArray(true)['cases'][0]['messages'], 'the result file does');
    }

    public function testAToolsSummaryWithoutRepetitionsOrDecisionFiguresWritesOnlyThePlanSize(): void
    {
        $summary = AgentEvalRunSummary::buildForToolsRun($this->rows(['case' => 'a', 'passed' => true, 'durationMs' => 10]), null, 'judge', [], 1, 'default', 'default')->toArray();

        self::assertSame(
            ['totalDurationMs', 'totalTokens', 'totalCostUsd', 'avgDurationMs', 'avgTotalTokens', 'avgCostPerCaseUsd', 'avgToolCallCount', 'avgWorkToolCallCount', 'costPerPassUsd', 'performance', 'taskPlanSize'],
            array_keys($summary['metrics'])
        );
        self::assertNull($summary['metrics']['taskPlanSize']);
        self::assertNull($summary['model']);
        self::assertNull(AgentEvalRunSummary::buildForSuite($this->rows(['case' => 'a', 'passed' => false]), 'm', 'call')->costPerPassUsd);
        self::assertSame(0.0, AgentEvalRunSummary::buildForSuite($this->rows(['case' => 'a', 'passed' => false]), 'm', 'call')->avgCostPerCaseUsd, 'no timed row');
    }

    public function testASummaryReadBackFromItsFileGivesTheSameSummary(): void
    {
        $caseRows = $this->rows(
            $this->measuredRow('a', true, 2, 1, [10.0], 20000, 0.004, 400, 18000, 15000) + ['durationMs' => 11000, 'toolCallCount' => 2],
            ['case' => 'b', 'passed' => false, 'durationMs' => 3000, 'planDynamics' => ['expectPlan' => 'direct', 'verdict' => 'planOnDirect', 'planCalls' => 1]]
        );
        $suiteSummary = AgentEvalRunSummary::buildForSuite($caseRows, 'VENDOR.MODEL', 'judge', ['effort' => 'low'])->toArray();
        $toolsSummary = AgentEvalRunSummary::buildForToolsRun($caseRows, null, 'call', [], 3, 'on', 'default')->toArray();

        $writtenSuiteSummary = json_decode((string)json_encode($suiteSummary), true);
        self::assertSame((string)json_encode($suiteSummary), (string)json_encode(AgentEvalRunSummary::fromArray($writtenSuiteSummary)->toArray()), 'the file it would write again is the same file');
        self::assertSame($toolsSummary, AgentEvalRunSummary::fromArray($toolsSummary)->toArray());
        self::assertSame(2, AgentEvalRunSummary::fromArray($suiteSummary)->caseRows->count());
    }
}
