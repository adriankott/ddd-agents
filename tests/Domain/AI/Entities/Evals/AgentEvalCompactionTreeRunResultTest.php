<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalCompactionTreeRunResult;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimRunResult;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * What a compaction-tree run returns: a judged run's result plus the tree after each owner turn (by turn number) and the
 * final tree.
 */
class AgentEvalCompactionTreeRunResultTest extends UnitTestCase
{
    public function testTheTreeResultCarriesTheSnapshotsAndTheFinalTree(): void
    {
        $conversation = AIConversation::newInstance();
        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput('{"verdict":"FAIL","treeGrew":true,"rollUpsFormed":false,"reasons":"no roll-up"}');
        $treeSnapshots = [1 => '(the tree is empty)', 2 => 'L0 [0–3] 4msg leaf: first topic'];

        $agentEvalCompactionTreeRunResult = AgentEvalCompactionTreeRunResult::forTreeRun(
            $agentEvalJudgeVerdict,
            AgentEvalSimRunResult::STOP_REASON_MAX_TURNS,
            2,
            $treeSnapshots,
            'L0 [0–3] 4msg leaf: first topic',
            $conversation
        );

        self::assertInstanceOf(AgentEvalCompactionTreeRunResult::class, $agentEvalCompactionTreeRunResult);
        self::assertSame($treeSnapshots, $agentEvalCompactionTreeRunResult->getTreeSnapshots());
        self::assertSame('L0 [0–3] 4msg leaf: first topic', $agentEvalCompactionTreeRunResult->getFinalTree());
        self::assertSame($agentEvalJudgeVerdict, $agentEvalCompactionTreeRunResult->getAgentEvalJudgeVerdict());
        self::assertSame(2, $agentEvalCompactionTreeRunResult->getTurns());
        self::assertSame($conversation, $agentEvalCompactionTreeRunResult->getConversation());
    }
}
