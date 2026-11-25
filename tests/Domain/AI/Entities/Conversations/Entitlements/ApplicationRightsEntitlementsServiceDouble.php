<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Entitlements;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;

/** An application's entitlements service: its own rights object, with or without an application mapping */
class ApplicationRightsEntitlementsServiceDouble extends AIConversationEntitlementsService
{
    public function __construct(protected AgentAccountRightsInterface $applicationAccountRights, protected bool $shipsApplicationMapping)
    {
    }

    public function findAccountRightsOfCurrentAccount(): ?AgentAccountRightsInterface
    {
        return $this->applicationAccountRights;
    }

    public function isAccountRightsMappingShippedByTheApplication(): bool
    {
        return $this->shipsApplicationMapping;
    }
}
