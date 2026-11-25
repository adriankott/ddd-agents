<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\AgentCheckInstallationCommand;

/** The installation check without the default account for console work (the test database has no account) */
class AgentCheckInstallationProbeCommand extends AgentCheckInstallationCommand
{
    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return false;
    }
}
