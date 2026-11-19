<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** The evaluation hooks with the accounts a run may name held in memory, and the space-only scope observable. */
class AgentEvalRunHooksServiceWithKnownAccountsDouble extends AgentEvalRunHooksService
{
    /** @var array<int, Account> by id */
    public array $accountsById = [];

    /** @var int The calls of the space-only scope ({@see resolveEvalScope()}) */
    public int $spaceOnlyScopeCalls = 0;

    public function resolveEvalScope(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId = null): AgentEvalScope
    {
        $this->spaceOnlyScopeCalls++;
        return parent::resolveEvalScope($agentEvalCase, $fallbackAgentSpaceId);
    }

    protected function findAccountOfEvalScope(int $accountId): ?Account
    {
        return $this->accountsById[$accountId] ?? null;
    }
}
