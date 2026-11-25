<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

/**
 * The port through which a package (or the application) adds a check to `app:agent:check-installation`: every service
 * implementing it is collected by its tag ({@see AgentInstallationCheckService::setAgentInstallationChecks()}) and run
 * after the built-in checks, in container order. A package ships its check as a service in a public `…\Services\`
 * namespace; autoconfiguration tags it.
 *
 * A check reads only (configuration, the PATH, the container), never writes, and answers within a few seconds. A check
 * that throws becomes one WARNING finding naming it; the other checks run.
 */
#[AutoconfigureTag(AgentInstallationCheckInterface::TAG)]
interface AgentInstallationCheckInterface
{
    public const string TAG = 'ai.agent_installation_check';

    /** @return string The name of the check, shown as the `checkName` of its findings (`toolImageStore`) */
    public function getInstallationCheckName(): string;

    /**
     * The findings of the check; an empty set = nothing to report.
     *
     * @return AgentInstallationFindings
     * @throws Throwable reported as one WARNING finding of this check
     */
    public function findInstallationFindings(): AgentInstallationFindings;
}
