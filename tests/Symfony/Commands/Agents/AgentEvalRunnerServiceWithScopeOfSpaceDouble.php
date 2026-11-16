<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\Common\Entities\Accounts\Account;
use ReflectionClass;

/**
 * A runner whose scope is always the stored space `$agentSpaceId` of the owner `$ownerAccountId`, read from no
 * database; authenticating the owner records the scope instead.
 */
class AgentEvalRunnerServiceWithScopeOfSpaceDouble extends AgentEvalRunnerService
{
    public ?AgentEvalScope $authenticatedScope = null;

    public function __construct(public int $agentSpaceId, public int $ownerAccountId)
    {
    }

    public function resolveEvalScope(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId = null): AgentEvalScope
    {
        $agentSpace = (new ReflectionClass(AgentSpace::class))->newInstanceWithoutConstructor();
        $agentSpace->id = $fallbackAgentSpaceId ?? $this->agentSpaceId;
        $ownerAccount = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
        $ownerAccount->id = $this->ownerAccountId;
        return AgentEvalScope::forAgentSpace($agentSpace, $ownerAccount);
    }

    public function authenticateOwnerOfScope(AgentEvalScope $agentEvalScope): void
    {
        $this->authenticatedScope = $agentEvalScope;
    }
}
