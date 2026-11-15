<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimRunResult;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * What a judged run returns: the verdict object, the stop reason, the owner turns and the conversation.
 */
class AgentEvalSimRunResultTest extends UnitTestCase
{
    public function testTheResultCarriesWhatTheRunGave(): void
    {
        $conversation = AIConversation::newInstance();
        $agentEvalJudgeVerdict = AgentEvalJudgeVerdict::fromJudgeOutput('{"verdict":"PASS","scorePercent":95,"reasons":"ok"}');

        $agentEvalSimRunResult = AgentEvalSimRunResult::forRun($agentEvalJudgeVerdict, AgentEvalSimRunResult::STOP_REASON_DONE, 3, $conversation);

        self::assertSame($agentEvalJudgeVerdict, $agentEvalSimRunResult->getAgentEvalJudgeVerdict());
        self::assertSame('DONE', $agentEvalSimRunResult->getStopReason());
        self::assertSame(3, $agentEvalSimRunResult->getTurns());
        self::assertSame($conversation, $agentEvalSimRunResult->getConversation());
    }

    public function testTheStopReasonsKeepTheirStoredValues(): void
    {
        self::assertSame('MAX_TURNS', AgentEvalSimRunResult::STOP_REASON_MAX_TURNS);
        self::assertSame('DONE', AgentEvalSimRunResult::STOP_REASON_DONE);
        self::assertSame('GIVE_UP', AgentEvalSimRunResult::STOP_REASON_GIVE_UP);
    }

    public function testAnApplicationsOwnStopReasonIsKept(): void
    {
        $agentEvalSimRunResult = AgentEvalSimRunResult::forRun(AgentEvalJudgeVerdict::fromArray([]), 'HANDOVER', 1, AIConversation::newInstance());

        self::assertSame('HANDOVER', $agentEvalSimRunResult->getStopReason());
        self::assertSame([], $agentEvalSimRunResult->getAgentEvalJudgeVerdict()->toArray());
    }
}
