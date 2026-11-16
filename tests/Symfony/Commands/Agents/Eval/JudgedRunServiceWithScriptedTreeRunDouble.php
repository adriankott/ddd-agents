<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCompactionTreeRunResult;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\Common\Entities\Accounts\Account;
use RuntimeException;

/**
 * The judged-run service with the compaction-tree run replaced: the scope of any space is that space with one owner,
 * and the run answers the result the test scripted (no result = the run fails). Records the case and options it got.
 */
class JudgedRunServiceWithScriptedTreeRunDouble extends AgentEvalJudgedRunService
{
    public const int OWNER_ACCOUNT_ID = 5;

    public const string RUN_FAILURE_MESSAGE = 'the driver model is not reachable';

    public ?AgentEvalCompactionTreeRunResult $scriptedRunResult = null;

    /** @var array<int, array{0: ?int, 1: ?int}> Per scope resolution: the case's space id and the fallback space id */
    public array $resolvedScopeSpaceIds = [];

    public ?AgentEvalSimCase $receivedSimCase = null;

    public ?AgentEvalJudgedRunOptions $receivedRunOptions = null;

    public ?AgentEvalScope $receivedScope = null;

    public function resolveEvalScope(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId = null): AgentEvalScope
    {
        $this->resolvedScopeSpaceIds[] = [$agentEvalCase->getAgentSpaceId(), $fallbackAgentSpaceId];
        $agentSpace = AgentSpace::newInstance();
        $agentSpace->id = $agentEvalCase->getAgentSpaceId() ?? $fallbackAgentSpaceId;
        $ownerAccount = Account::newInstance();
        $ownerAccount->id = self::OWNER_ACCOUNT_ID;
        return AgentEvalScope::forAgentSpace($agentSpace, $ownerAccount);
    }

    public function runCompactionTreeEval(AgentEvalScope $agentEvalScope, AgentEvalSimCase $agentEvalSimCase, AgentEvalJudgedRunOptions $agentEvalJudgedRunOptions): AgentEvalCompactionTreeRunResult
    {
        $this->receivedScope = $agentEvalScope;
        $this->receivedSimCase = $agentEvalSimCase;
        $this->receivedRunOptions = $agentEvalJudgedRunOptions;
        if ($this->scriptedRunResult === null) {
            throw new RuntimeException(self::RUN_FAILURE_MESSAGE);
        }
        return $this->scriptedRunResult;
    }
}
