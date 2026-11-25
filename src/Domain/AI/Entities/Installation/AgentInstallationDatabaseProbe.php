<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Installation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the installation check's own database probe found
 * ({@see \DDD\Domain\AI\Services\Installation\AgentInstallationCheckService::findDatabaseProbe()}): the probe opens a
 * connection of its own with the parameters of the default connection and a short connect timeout, lists the tables
 * and closes it — the application's connection is never opened by it, so a database that does not answer costs the
 * check at most the timeout, never the driver's default wait.
 */
class AgentInstallationDatabaseProbe extends ValueObject
{
    /** @var bool Whether the database answered and listed its tables */
    public bool $answered = false;

    /** @var string[] The table names of the default connection, without a schema prefix (empty when it did not answer) */
    public array $liveTableNames = [];

    /** @var string|null The short class name of the failure when it did not answer (the driver's text is never kept) */
    public ?string $failureClassShortName = null;

    /** @var bool Whether the failure came at the connect timeout (the host did not answer at all) */
    public bool $reachedConnectTimeout = false;

    /** @var int The connect timeout of the probe in seconds */
    public int $connectTimeoutSeconds = 0;

    /** @var float How long the probe took in seconds */
    public float $elapsedSeconds = 0.0;

    /**
     * @param string[] $liveTableNames
     */
    public static function createAnswered(array $liveTableNames, int $connectTimeoutSeconds, float $elapsedSeconds): static
    {
        $agentInstallationDatabaseProbe = static::newInstance();
        $agentInstallationDatabaseProbe->answered = true;
        $agentInstallationDatabaseProbe->liveTableNames = array_values(array_map('strval', $liveTableNames));
        $agentInstallationDatabaseProbe->connectTimeoutSeconds = $connectTimeoutSeconds;
        $agentInstallationDatabaseProbe->elapsedSeconds = $elapsedSeconds;
        return $agentInstallationDatabaseProbe;
    }

    public static function createFailed(string $failureClassShortName, bool $reachedConnectTimeout, int $connectTimeoutSeconds, float $elapsedSeconds): static
    {
        $agentInstallationDatabaseProbe = static::newInstance();
        $agentInstallationDatabaseProbe->failureClassShortName = $failureClassShortName;
        $agentInstallationDatabaseProbe->reachedConnectTimeout = $reachedConnectTimeout;
        $agentInstallationDatabaseProbe->connectTimeoutSeconds = $connectTimeoutSeconds;
        $agentInstallationDatabaseProbe->elapsedSeconds = $elapsedSeconds;
        return $agentInstallationDatabaseProbe;
    }
}
