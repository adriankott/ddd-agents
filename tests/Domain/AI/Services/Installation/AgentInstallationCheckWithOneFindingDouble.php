<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckInterface;

/** An added check of another package that reports one blocking finding */
class AgentInstallationCheckWithOneFindingDouble implements AgentInstallationCheckInterface
{
    public function getInstallationCheckName(): string
    {
        return 'hostFeatureStore';
    }

    public function findInstallationFindings(): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        $agentInstallationFindings->addFinding('hostFeatureStore', AgentInstallationFinding::SEVERITY_BLOCKING, 'featureStore', 'The feature store is not configured.');
        return $agentInstallationFindings;
    }
}
