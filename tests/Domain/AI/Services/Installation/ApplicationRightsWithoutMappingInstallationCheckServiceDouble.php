<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;

/** The installation check of an application that answers rights with its own object and ships no mapping */
class ApplicationRightsWithoutMappingInstallationCheckServiceDouble extends AgentInstallationCheckService
{
    protected function isApplicationRightsObjectWithoutApplicationMapping(): bool
    {
        return true;
    }
}
