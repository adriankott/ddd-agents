<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Tests\Agents\Support\UnitTestCase;

/** A judge never overrules the deterministic gates of its case: a PASS with a missed gate is a FAIL. */
class AgentEvalJudgeVerdictDeterministicGatesTest extends UnitTestCase
{
    public function testAPassWithAMissedGateBecomesAFailThatListsTheGates(): void
    {
        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromArray(['verdict' => 'PASS', 'reasons' => 'looks good', 'scorePercent' => 90]);
        $agentEvalJudgeVerdict->applyDeterministicGateFailures(['required tool not completed: x']);
        self::assertSame(AgentEvalJudgeVerdict::VERDICT_FAIL, $agentEvalJudgeVerdict->getVerdict());
        self::assertSame('Deterministic gate failed (the judge said PASS): required tool not completed: x. Judge: looks good', $agentEvalJudgeVerdict->getReasons());
        self::assertSame(['required tool not completed: x'], $agentEvalJudgeVerdict->getDeterministicGateFailures());
        self::assertSame(90, $agentEvalJudgeVerdict->getScorePercent(), 'the judge\'s other fields stay');
    }

    public function testAFailKeepsItsReasonsAndListsTheGatesAnErrorAndAMetGateChangeNothing(): void
    {
        $failVerdict = AgentEvalJudgeVerdict::fromArray(['verdict' => 'FAIL', 'reasons' => 'wrong answer']);
        $failVerdict->applyDeterministicGateFailures(['gate']);
        self::assertSame('wrong answer', $failVerdict->getReasons());
        self::assertSame(['gate'], $failVerdict->getDeterministicGateFailures());

        $errorVerdict = AgentEvalJudgeVerdict::forFailedJudgeCall('provider down');
        $errorVerdict->applyDeterministicGateFailures(['gate']);
        self::assertSame(AgentEvalJudgeVerdict::VERDICT_ERROR, $errorVerdict->getVerdict());
        self::assertSame([], $errorVerdict->getDeterministicGateFailures());

        $passVerdict = AgentEvalJudgeVerdict::fromArray(['verdict' => 'PASS', 'reasons' => 'ok']);
        $passVerdict->applyDeterministicGateFailures([]);
        self::assertTrue($passVerdict->isPass());
        self::assertArrayNotHasKey(AgentEvalJudgeVerdict::KEY_DETERMINISTIC_GATE_FAILURES, $passVerdict->toArray());
    }
}
