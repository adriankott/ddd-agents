<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalBaselineComparison;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A run against its baseline: the roll-ups over the common measured cases, the figures that grew or shrank beyond
 * tolerance and noise floor, the cases whose model calls grew, and no verdict without a common case.
 */
class AgentEvalBaselineComparisonTest extends UnitTestCase
{
    use AgentEvalCaseRowsFixtureTrait;

    public function testTheComparisonReadsTheCommonCasesAndNamesWhatGrew(): void
    {
        $baseline = $this->rows(
            $this->measuredRow('a', true, 3, 1, [10.0], 30000, 0.005, 400, 28000, 20000),
            $this->measuredRow('b', true, 4, 1, [12.0], 40000, 0.007, 400, 38000, 30000),
            $this->measuredRow('onlyInBaseline', true, 40, 1, [300.0], 900000, 0.5, 400, 800000, 1000)
        );
        $slower = $this->rows(
            $this->measuredRow('a', true, 3, 1, [11.0], 30500, 0.005, 400, 28000, 20000),
            $this->measuredRow('b', true, 9, 1, [31.0], 95000, 0.016, 400, 90000, 60000),
            $this->measuredRow('onlyInCurrent', true, 1, 1, [1.0], 1000, 0.0001, 0, 900, 0)
        );

        $comparison = AgentEvalBaselineComparison::compareCaseRows($slower, $baseline, 15.0);

        self::assertTrue($comparison->comparable);
        self::assertSame(2, $comparison->commonCases);
        $regressedMetrics = $comparison->regressions->getMetrics();
        self::assertContains('avgModelCallsPerCase', $regressedMetrics);
        self::assertContains('avgSecondsPerOwnerTurn', $regressedMetrics);
        self::assertContains('avgCostPerCaseUsd', $regressedMetrics);
        self::assertNotContains('avgOwnerTurnsPerCase', $regressedMetrics);
        self::assertSame([['case' => 'b', 'baselineModelCalls' => 4, 'modelCalls' => 9, 'baselineAgentSeconds' => 12.0, 'agentSeconds' => 31.0]], $comparison->toArray()['caseRegressions']);
        self::assertSame([], $comparison->toArray()['improvements']);
        self::assertSame(['comparable', 'tolerancePercent', 'commonCases', 'regressions', 'improvements', 'caseRegressions'], array_keys($comparison->toArray()));
        self::assertSame(['metric' => 'avgModelCallsPerCase', 'baseline' => 3.5, 'current' => 6.0, 'changePercent' => 71.4], $comparison->toArray()['regressions'][0]);

        self::assertFalse(AgentEvalBaselineComparison::compareCaseRows($baseline, $baseline)->hasRegressions(), 'the same run against itself');
        $wobble = $this->rows(
            $this->measuredRow('a', true, 3, 1, [10.9], 31000, 0.0052, 400, 28000, 20000),
            $this->measuredRow('b', true, 5, 1, [12.5], 41000, 0.0071, 400, 38000, 30000)
        );
        $wobbleComparison = AgentEvalBaselineComparison::compareCaseRows($wobble, $baseline, 15.0);
        self::assertSame([], $wobbleComparison->toArray()['regressions']);
        self::assertSame([], $wobbleComparison->toArray()['caseRegressions'], 'one model call more is noise');

        $improved = AgentEvalBaselineComparison::compareCaseRows($baseline, $slower, 15.0);
        self::assertContains('avgModelCallsPerCase', $improved->improvements->getMetrics());
        self::assertFalse($improved->hasRegressions());

        $foreign = AgentEvalBaselineComparison::compareCaseRows($this->rows($this->measuredRow('x', true, 1, 1, [1.0], 10, 0.0, 0, 5, 0)), $baseline);
        self::assertFalse($foreign->comparable);
        self::assertSame(['comparable' => false, 'tolerancePercent' => 15.0, 'commonCases' => 0, 'regressions' => [], 'improvements' => [], 'caseRegressions' => []], $foreign->toArray());
    }

    public function testTheCaseRegressionsComeLargestGrowthFirst(): void
    {
        $baseline = $this->rows(
            $this->measuredRow('small', true, 2, 1, [1.0], 10, 0.0, 0, 5, 0),
            $this->measuredRow('large', true, 2, 1, [1.0], 10, 0.0, 0, 5, 0),
            $this->measuredRow('alsoSmall', true, 2, 1, [1.0], 10, 0.0, 0, 5, 0)
        );
        $current = $this->rows(
            $this->measuredRow('small', true, 5, 1, [1.0], 10, 0.0, 0, 5, 0),
            $this->measuredRow('large', true, 12, 1, [1.0], 10, 0.0, 0, 5, 0),
            $this->measuredRow('alsoSmall', true, 5, 1, [1.0], 10, 0.0, 0, 5, 0),
            ['case' => 'large', 'passed' => false, 'error' => 'unmeasured']
        );

        $caseRegressions = AgentEvalBaselineComparison::compareCaseRows($current, $baseline)->toArray()['caseRegressions'];

        self::assertSame(['large', 'small', 'alsoSmall'], array_column($caseRegressions, 'case'), 'equal growth keeps the order of the rows');
    }
}
