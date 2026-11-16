<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRows;

/**
 * Case rows as a run writes them, with a `performance` block of the run figures — for the tests of the roll-ups, the
 * run summary and the baseline comparison.
 */
trait AgentEvalCaseRowsFixtureTrait
{
    /**
     * The row of one measured case.
     *
     * @param float[] $secondsPerOwnerTurn
     * @return array
     */
    protected function measuredRow(string $caseId, bool $passed, int $modelCalls, int $ownerTurns, array $secondsPerOwnerTurn, int $totalTokens, float $costUsd, int $decisionModelMs, int $inputTokens, int $cachedInputTokens): array
    {
        return [
            'case' => $caseId,
            'passed' => $passed,
            'totalTokens' => $totalTokens,
            'costUsd' => $costUsd,
            'performance' => [
                'ownerTurns' => $ownerTurns,
                'modelCalls' => $modelCalls,
                'toolCalls' => $modelCalls,
                'agentSeconds' => array_sum($secondsPerOwnerTurn),
                'secondsPerOwnerTurn' => $secondsPerOwnerTurn,
                'inputTokens' => $inputTokens,
                'cachedInputTokens' => $cachedInputTokens,
                'totalTokens' => $totalTokens,
                'costUsd' => $costUsd,
                'decisionModelMs' => $decisionModelMs,
            ],
        ];
    }

    /**
     * @param array ...$rowArrays
     */
    protected function rows(array ...$rowArrays): AgentEvalCaseRows
    {
        return AgentEvalCaseRows::fromRowArrays($rowArrays);
    }

    protected function row(array $rowFields): AgentEvalCaseRow
    {
        return AgentEvalCaseRow::fromArray($rowFields);
    }
}
