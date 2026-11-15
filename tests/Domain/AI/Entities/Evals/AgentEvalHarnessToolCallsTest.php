<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCalls;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The harness calls of a case keep every call under the key of its own object: a repeated call is a step of its own,
 * and the calls run in the order they were added.
 */
class AgentEvalHarnessToolCallsTest extends UnitTestCase
{
    public function testTwoEqualCallsBothStayInTheOrderTheyWereAdded(): void
    {
        $firstRemoval = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_UNDO, 'deleteNote', ['noteId' => 12], true);
        $otherRemoval = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_UNDO, 'unpinNote', ['noteId' => 12], true);
        $equalRemoval = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_UNDO, 'deleteNote', ['noteId' => 12], true);
        $agentEvalHarnessToolCalls = AgentEvalHarnessToolCalls::newInstance();

        $agentEvalHarnessToolCalls->add($firstRemoval, $otherRemoval, $equalRemoval);

        self::assertSame(3, $agentEvalHarnessToolCalls->count(), 'an equal call is a call of its own');
        self::assertSame([$firstRemoval, $otherRemoval, $equalRemoval], $agentEvalHarnessToolCalls->getElements());
        $iteratedToolNames = [];
        foreach ($agentEvalHarnessToolCalls as $agentEvalHarnessToolCall) {
            $iteratedToolNames[] = $agentEvalHarnessToolCall->toolName;
        }
        self::assertSame(['deleteNote', 'unpinNote', 'deleteNote'], $iteratedToolNames, 'iteration keeps the order of insertion');
    }

    public function testTheSameCallAddedTwiceIsHeldOnce(): void
    {
        $removal = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_UNDO, 'deleteNote', ['noteId' => 12], true);
        $agentEvalHarnessToolCalls = AgentEvalHarnessToolCalls::newInstance();

        $agentEvalHarnessToolCalls->add($removal);
        $agentEvalHarnessToolCalls->add($removal);

        self::assertSame([$removal], $agentEvalHarnessToolCalls->getElements());
    }
}
