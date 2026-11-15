<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\JevProbe;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Whom the decision probe runs for: the agent space a fixture names, the account that owns it (the run acts as it and
 * the probe's model calls are booked against its budget) and the entity within the account the calls are billed to.
 *
 * A plain object of one probe run; no container service.
 */
#[Exclude]
class AgentJevProbeScope
{
    /**
     * @param AgentSpace $agentSpace the space the fixture names
     * @param Account $ownerAccount the account that owns the space
     * @param Entity|null $billingEntity the entity the calls are billed to; null = the account is billed
     */
    public function __construct(
        public readonly AgentSpace $agentSpace,
        public readonly Account $ownerAccount,
        public readonly ?Entity $billingEntity,
    ) {
    }
}
