<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimRunResult;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;

/** The judged runner with the gate step reachable and an application gate scripted. */
class AgentEvalJudgedRunServiceWithHostGateDouble extends AgentEvalJudgedRunService
{
    /** @var string[] What the application's own gate reports */
    public array $hostGateFailures = [];

    public function applyGates(AgentEvalSimRunResult $agentEvalSimRunResult, AgentEvalScope $agentEvalScope, AgentEvalSimCase $agentEvalSimCase): AgentEvalSimRunResult
    {
        return $this->applyDeterministicGatesToRunResult($agentEvalSimRunResult, $agentEvalScope, $agentEvalSimCase);
    }

    protected function buildHostGateFailures(AIConversation $conversation, AgentEvalScope $agentEvalScope, AgentEvalSimCase $agentEvalSimCase): array
    {
        return $this->hostGateFailures;
    }

    protected function getAgentEvalRunHooksService(): AgentEvalRunHooksService
    {
        return new AgentEvalRunHooksService();
    }
}
