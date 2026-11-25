<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentPackageRenames;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;

/** The installation check over fixture rename maps and a fixture record of applied renames */
class AgentInstallationCheckServiceWithFixtureRenamesDouble extends AgentInstallationCheckService
{
    public ?AgentPackageRenames $fixturePackageRenames = null;

    /** @var array<string, string> */
    public array $fixtureAppliedThroughVersionByPackageName = [];

    protected function findPackageRenames(): AgentPackageRenames
    {
        return $this->fixturePackageRenames ?? AgentPackageRenames::newInstance();
    }

    protected function readAppliedThroughVersionByPackageName(): array
    {
        return $this->fixtureAppliedThroughVersionByPackageName;
    }
}
