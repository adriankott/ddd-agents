<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;

/** The installation check whose default connection has fixture parameters (read, never opened by the check) */
class AgentInstallationCheckWithFixtureConnectionDouble extends AgentInstallationCheckService
{
    /** @var array<string, mixed> The parameters of the default connection */
    public array $fixtureDefaultConnectionParams = [];

    /** @var int|null The connect timeout of the probe; null = the one of the config */
    public ?int $fixtureConnectTimeoutSeconds = null;

    public function getDatabaseConnectTimeoutSeconds(): int
    {
        return $this->fixtureConnectTimeoutSeconds ?? parent::getDatabaseConnectTimeoutSeconds();
    }

    protected function findDefaultConnectionParams(): array
    {
        return $this->fixtureDefaultConnectionParams;
    }

    /**
     * The parameters the probe connection is built with.
     *
     * @return array<string, mixed>
     */
    public function buildProbeConnectionParamsForTest(): array
    {
        return $this->buildProbeConnectionParams();
    }
}
