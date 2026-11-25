<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;

/** Opens the findings about which class runs under the names of one package command to a test. */
class AgentInstallationCheckExposingCommandNameOwnersDouble extends AgentInstallationCheckService
{
    /**
     * @param array<string, string> $registeredClassNamesByCommandName
     * @param string[] $applicationCommandClassNames
     */
    public function findCommandNameOwnerFindings(
        string $commandClassName,
        array $registeredClassNamesByCommandName,
        array $applicationCommandClassNames = []
    ): AgentInstallationFindings {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        $this->addCommandNameOwnerFindings($agentInstallationFindings, $commandClassName, $registeredClassNamesByCommandName, $applicationCommandClassNames);
        return $agentInstallationFindings;
    }
}
