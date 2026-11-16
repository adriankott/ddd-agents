<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Base\Entities\Entity;

/**
 * A space of an application whose model calls are billed to an entity of its own.
 */
class AgentSpaceWithBillingEntityDouble extends AgentSpace
{
    /** @var Entity|null The entity the test bills to */
    public ?Entity $billingEntity = null;

    public function findBillingEntity(): ?Entity
    {
        return $this->billingEntity;
    }
}
