<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Whom the decision calls of a fixture run are made for: the account that owns what the fixture names (the run acts as
 * it, the calls are booked against its budget and their usage envelopes are opened for it) and the entity within the
 * account the calls are billed to.
 *
 * A plain object of one command run; no container service.
 */
#[Exclude]
class AgentDecisionFixtureScope
{
    /**
     * @param Account $ownerAccount the account the run acts as and books the calls on
     * @param Entity|null $billingEntity the entity the calls are billed to; null = the account is billed
     */
    public function __construct(
        public readonly Account $ownerAccount,
        public readonly ?Entity $billingEntity,
    ) {
    }

    /** The id of the account the calls are booked on. */
    public function getOwnerAccountId(): int
    {
        return (int)$this->ownerAccount->id;
    }
}
