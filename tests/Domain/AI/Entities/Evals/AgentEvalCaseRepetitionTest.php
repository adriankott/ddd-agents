<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRepetition;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use PHPUnit\Framework\TestCase;

/**
 * The repetitions of a case keep why its failed runs failed (error first, else the first failure, one line, cut), at
 * most five, and keep them out of the stored entry.
 */
class AgentEvalCaseRepetitionTest extends TestCase
{
    public function testTheFailedRunsKeepTheirDetailOutsideTheStoredEntry(): void
    {
        $agentEvalCaseRepetition = AgentEvalCaseRepetition::forCase('case.a');
        $agentEvalCaseRepetition->countRun(AgentEvalCaseRow::fromArray(['passed' => true, 'failures' => []]));
        $agentEvalCaseRepetition->countRun(AgentEvalCaseRow::fromArray(['passed' => false, 'failures' => ["tool not called:\n'listItems'", 'second']]));
        $agentEvalCaseRepetition->countRun(AgentEvalCaseRow::fromArray(['passed' => false, 'failures' => ['ignored'], 'error' => 'child timed out']));
        $agentEvalCaseRepetition->countRun(AgentEvalCaseRow::fromArray(['passed' => false, 'failures' => [str_repeat('x', 300)]]));

        self::assertSame(["tool not called: 'listItems'", 'child timed out', str_repeat('x', 199) . '…'], $agentEvalCaseRepetition->failureDetails);
        self::assertSame(['case' => 'case.a', 'runs' => 4, 'passed' => 1, 'errors' => 1, 'passRate' => 0.25], $agentEvalCaseRepetition->toArray());

        for ($runIndex = 0; $runIndex < 10; $runIndex++) {
            $agentEvalCaseRepetition->countRun(AgentEvalCaseRow::fromArray(['passed' => false]));
        }
        self::assertCount(AgentEvalCaseRepetition::MAX_FAILURE_DETAILS, $agentEvalCaseRepetition->failureDetails);
        self::assertSame('failed', $agentEvalCaseRepetition->failureDetails[3]);
    }
}
