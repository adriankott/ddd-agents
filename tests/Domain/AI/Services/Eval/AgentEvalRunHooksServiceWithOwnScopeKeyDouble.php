<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\Common\Entities\Accounts\Account;
use RuntimeException;

/**
 * An application's scope hook with a scope key of its own: a case naming `hostRecordId` runs in the agent space of
 * that record (held in memory); a record without a space fails the case. Other cases take the module's scope.
 */
class AgentEvalRunHooksServiceWithOwnScopeKeyDouble extends AgentEvalRunHooksServiceWithKnownAccountsDouble
{
    /** @var array<int, AgentSpace> the agent space of each record of the application, by record id */
    public array $agentSpacesByHostRecordId = [];

    public ?Account $ownerAccountOfHostSpaces = null;

    public function resolveEvalScope(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId = null): AgentEvalScope
    {
        $hostRecordId = $agentEvalCase->toArray()['hostRecordId'] ?? null;
        if ($hostRecordId === null) {
            return parent::resolveEvalScope($agentEvalCase, $fallbackAgentSpaceId);
        }
        $this->spaceOnlyScopeCalls++;
        $agentSpace = $this->agentSpacesByHostRecordId[(int)$hostRecordId] ?? null;
        if (!$agentSpace instanceof AgentSpace || !$this->ownerAccountOfHostSpaces instanceof Account) {
            throw new RuntimeException("Record {$hostRecordId} has no agent space");
        }
        return AgentEvalScope::forAgentSpace($agentSpace, $this->ownerAccountOfHostSpaces, ['hostRecordId' => (int)$hostRecordId]);
    }
}
