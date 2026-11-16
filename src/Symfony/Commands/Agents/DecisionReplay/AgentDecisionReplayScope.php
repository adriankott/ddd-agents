<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionReplay;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Whom a replayed decision is made for: the account the run acts as (the call is booked against its budget and its
 * evaluation envelope is opened for it), the entity within the account the call is billed to, and the agent space the
 * replayed conversation works for.
 *
 * A plain object of one command run; no container service.
 */
#[Exclude]
class AgentDecisionReplayScope
{
    /**
     * @param Account $ownerAccount the account the run acts as and books the call on
     * @param Entity|null $billingEntity the entity the call is billed to; null = the account is billed
     * @param AgentSpace|null $agentSpace the space the replayed work is for; null = account level
     */
    public function __construct(
        public readonly Account $ownerAccount,
        public readonly ?Entity $billingEntity,
        public readonly ?AgentSpace $agentSpace,
    ) {
    }

    /** The id of the account the call is booked on. */
    public function getOwnerAccountId(): int
    {
        return (int)$this->ownerAccount->id;
    }
}
