<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use RuntimeException;

/** The installation check over a fixture project directory and a database that answers with fixture tables, or fails */
class AgentInstallationCheckWithFixtureTablesDouble extends AgentInstallationCheckService
{
    /** @var string[]|null The live tables; null = the database does not answer */
    public ?array $fixtureLiveTableNames = [];

    public ?string $fixtureProjectDirectory = null;

    /** @var string The message of the driver when the database does not answer */
    public const string DRIVER_MESSAGE = "SQLSTATE[HY000] [1045] Access denied for user 'agent_app'@'db.internal.example' (using password: YES) mysql://agent_app:secret@db.internal.example/agents";

    protected function findLiveTableNames(): array
    {
        if ($this->fixtureLiveTableNames === null) {
            throw new RuntimeException(static::DRIVER_MESSAGE);
        }
        return $this->fixtureLiveTableNames;
    }

    protected function getProjectDirectory(): string
    {
        return $this->fixtureProjectDirectory ?? parent::getProjectDirectory();
    }
}
