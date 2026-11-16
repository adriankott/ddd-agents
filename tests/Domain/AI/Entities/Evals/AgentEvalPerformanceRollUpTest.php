<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalPerformanceRollUp;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The performance and speed of a run over the cases it measured: averages over the measured rows only, nearest-rank
 * percentiles, speed figures that are null when nothing measured them, and the stored keys in their order.
 */
class AgentEvalPerformanceRollUpTest extends UnitTestCase
{
    use AgentEvalCaseRowsFixtureTrait;

    public function testTheRollUpAveragesOverTheMeasuredCasesOnly(): void
    {
        $rollUp = AgentEvalPerformanceRollUp::fromCaseRows($this->rows(
            $this->measuredRow('a', true, 2, 1, [10.0], 20000, 0.004, 400, 18000, 15000),
            $this->measuredRow('b', true, 6, 3, [8.0, 12.0, 4.0], 60000, 0.012, 1300, 55000, 50000),
            ['case' => 'c', 'passed' => false, 'error' => 'the child process left no result']
        ))->toArray();

        self::assertSame(2, $rollUp['measuredCases']);
        self::assertSame(8, $rollUp['modelCalls']);
        self::assertSame(4.0, $rollUp['avgModelCallsPerCase']);
        self::assertSame(2.0, $rollUp['avgModelCallsPerOwnerTurn']);
        self::assertSame(2.0, $rollUp['avgOwnerTurnsPerCase']);
        self::assertSame(34.0, $rollUp['agentSeconds']);
        self::assertSame(17.0, $rollUp['avgAgentSecondsPerCase']);
        self::assertSame(8.5, $rollUp['avgSecondsPerOwnerTurn']);
        self::assertSame(8.0, $rollUp['medianSecondsPerOwnerTurn']);
        self::assertSame(12.0, $rollUp['p90SecondsPerOwnerTurn']);
        self::assertSame(12.0, $rollUp['maxSecondsPerOwnerTurn']);
        self::assertSame(40000.0, $rollUp['avgTotalTokensPerCase']);
        self::assertSame(0.008, $rollUp['avgCostPerCaseUsd']);
        self::assertSame(0.004, $rollUp['avgCostPerOwnerTurnUsd']);
        self::assertSame(425, $rollUp['avgDecisionModelMsPerOwnerTurn']);
        self::assertSame(0.89, round((float)$rollUp['cachedInputShare'], 2));

        $empty = AgentEvalPerformanceRollUp::fromCaseRows($this->rows())->toArray();
        self::assertSame(0, $empty['measuredCases']);
        self::assertSame(0.0, $empty['avgSecondsPerOwnerTurn']);
        self::assertNull($empty['cachedInputShare']);
    }

    public function testTheSpeedFiguresRollUpOverEveryOwnerTurnAndEveryCase(): void
    {
        $caseA = $this->measuredRow('a', true, 4, 2, [10.0, 20.0], 60000, 0.004, 0, 50000, 30000);
        $caseA['performance'] += [
            'firstModelOutputSecondsPerOwnerTurn' => [2.0, 3.0],
            'firstAnswerSecondsPerOwnerTurn' => [4.0, 12.0],
            'uncachedInputTokensPerOwnerTurn' => [15000, 5000],
            'uncachedInputTokens' => 20000,
            'conversationWallSeconds' => 95.0,
        ];
        $caseB = $this->measuredRow('b', true, 6, 3, [8.0, 9.0, 30.0], 90000, 0.006, 0, 80000, 40000);
        $caseB['performance'] += [
            'firstModelOutputSecondsPerOwnerTurn' => [4.0, 8.0, null],
            'firstAnswerSecondsPerOwnerTurn' => [6.0, null, null],
            'uncachedInputTokensPerOwnerTurn' => [10000, 20000, 10000],
            'uncachedInputTokens' => 40000,
            'conversationWallSeconds' => 210.0,
        ];
        // a row written before the speed figures existed: only its conversation's uncached tokens (input − cached) count
        $olderCase = $this->measuredRow('c', true, 1, 1, [5.0], 10000, 0.001, 0, 9000, 6000);
        $agentEvalPerformanceRollUp = AgentEvalPerformanceRollUp::fromCaseRows($this->rows($caseA, $caseB, $olderCase));
        $rollUp = $agentEvalPerformanceRollUp->toArray();

        self::assertSame(3.0, $rollUp['medianFirstModelOutputSeconds'], 'nearest rank over 2, 3, 4, 8');
        self::assertSame(8.0, $rollUp['p90FirstModelOutputSeconds']);
        self::assertSame(6.0, $rollUp['medianFirstAnswerSeconds']);
        self::assertSame(12.0, $rollUp['p90FirstAnswerSeconds']);
        self::assertSame(63000, $rollUp['uncachedInputTokens']);
        self::assertSame(10500, $rollUp['avgUncachedInputTokensPerOwnerTurn']);
        self::assertSame(10000.0, $rollUp['medianUncachedInputTokensPerOwnerTurn']);
        self::assertSame(21000, $rollUp['avgUncachedInputTokensPerCase']);
        self::assertSame(20000.0, $rollUp['medianUncachedInputTokensPerCase']);
        self::assertSame(95.0, $rollUp['medianConversationWallSeconds']);
        self::assertSame(210.0, $rollUp['p90ConversationWallSeconds']);
        self::assertSame([
            'speed: first model output median 3.0 s · p90 8.0 s · first answer median 6.0 s · p90 12.0 s · conversation wall median 95.0 s · p90 210.0 s',
            'uncached input tokens: per owner turn avg 10.5k · median 10.0k · per conversation avg 21.0k · median 20.0k',
        ], $agentEvalPerformanceRollUp->buildSpeedKpiLines());

        $empty = AgentEvalPerformanceRollUp::fromCaseRows($this->rows());
        self::assertNull($empty->toArray()['medianFirstModelOutputSeconds']);
        self::assertNull($empty->toArray()['avgUncachedInputTokensPerOwnerTurn']);
        self::assertNull($empty->toArray()['medianConversationWallSeconds']);
        self::assertStringContainsString('first model output median n/a', $empty->buildSpeedKpiLines()[0]);
    }

    public function testThePercentileIsTheNearestRank(): void
    {
        $agentEvalPerformanceRollUp = AgentEvalPerformanceRollUp::newInstance();

        self::assertSame(0.0, $agentEvalPerformanceRollUp->percentile([], 50));
        self::assertSame(3.0, $agentEvalPerformanceRollUp->percentile([5.0, 1.0, 3.0], 50));
        self::assertSame(5.0, $agentEvalPerformanceRollUp->percentile([5.0, 1.0, 3.0], 90));
        self::assertSame(2.0, $agentEvalPerformanceRollUp->percentile([1.0, 2.0, 3.0, 4.0], 50));
    }

    public function testTheStoredKeysKeepTheirOrder(): void
    {
        self::assertSame([
            'measuredCases', 'ownerTurns', 'modelCalls', 'avgModelCallsPerCase', 'avgModelCallsPerOwnerTurn', 'avgOwnerTurnsPerCase',
            'avgToolCallsPerCase', 'agentSeconds', 'avgAgentSecondsPerCase', 'medianAgentSecondsPerCase', 'p90AgentSecondsPerCase',
            'avgSecondsPerOwnerTurn', 'medianSecondsPerOwnerTurn', 'p90SecondsPerOwnerTurn', 'maxSecondsPerOwnerTurn', 'totalTokens',
            'avgTotalTokensPerCase', 'cachedInputShare', 'costUsd', 'avgCostPerCaseUsd', 'avgCostPerOwnerTurnUsd', 'decisionModelMs',
            'avgDecisionModelMsPerOwnerTurn', 'medianFirstModelOutputSeconds', 'p90FirstModelOutputSeconds', 'medianFirstAnswerSeconds',
            'p90FirstAnswerSeconds', 'uncachedInputTokens', 'avgUncachedInputTokensPerOwnerTurn', 'medianUncachedInputTokensPerOwnerTurn',
            'avgUncachedInputTokensPerCase', 'medianUncachedInputTokensPerCase', 'medianConversationWallSeconds', 'p90ConversationWallSeconds',
        ], array_keys(AgentEvalPerformanceRollUp::fromCaseRows($this->rows())->toArray()));
        self::assertSame(4.0, AgentEvalPerformanceRollUp::fromCaseRows($this->rows($this->measuredRow('a', true, 4, 1, [3.0], 10, 0.0, 0, 5, 0)))->getFigure('avgModelCallsPerCase'));
    }
}
