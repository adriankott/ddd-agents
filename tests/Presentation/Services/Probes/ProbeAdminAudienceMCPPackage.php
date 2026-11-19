<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAdminWithAccessToAllResources;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;

/** A package of the admin audience with one read. */
#[MCPRequiresAdminWithAccessToAllResources]
class ProbeAdminAudienceMCPPackage extends MCPPackage
{
    /** Reads the accounts of the platform. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    public function probeListAccountsOfThePlatform(): string
    {
        return 'accounts';
    }
}
