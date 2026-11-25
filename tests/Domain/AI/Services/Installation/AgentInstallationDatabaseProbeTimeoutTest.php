<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * The table check reads the tables over a probe connection of its own with a short connect timeout
 * (`databaseConnectTimeoutSeconds` of `AI.installationCheck`): a host that does not answer costs the check the timeout,
 * not the driver's default wait, and is reported as one WARNING naming the timeout. The probe copies the default
 * connection's parameters and adds the timeout only to its own driver options.
 */
class AgentInstallationDatabaseProbeTimeoutTest extends UnitTestCase
{
    /** @var string An address of TEST-NET-1 (RFC 5737): routed nowhere, a connect to it never gets an answer */
    protected const string UNREACHABLE_HOST = '192.0.2.1';

    /** @var array<string, mixed> */
    protected array $configValuesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configValuesBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configValuesBefore);
        parent::tearDown();
    }

    /** Runs only `missingTables` with a connect timeout of `$connectTimeoutSeconds` */
    protected function configureOnlyTheTableCheck(int $connectTimeoutSeconds): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues['AI']['installationCheck'] = [
            'skippedChecks' => array_values(array_diff(array_keys(AgentInstallationCheckService::BUILT_IN_CHECK_METHOD_NAMES), [AgentInstallationCheckService::CHECK_MISSING_TABLES])),
            AgentInstallationCheckService::CONFIG_KEY_DATABASE_CONNECT_TIMEOUT_SECONDS => $connectTimeoutSeconds,
        ];
        $configTree->setValue(null, $configValues);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function provideDriversOfAnUnreachableHost(): array
    {
        return [
            'MariaDB over PDO' => ['pdo_mysql', 'pdo_mysql', 3306],
            'PostgreSQL over PDO' => ['pdo_pgsql', 'pdo_pgsql', 5432],
        ];
    }

    #[DataProvider('provideDriversOfAnUnreachableHost')]
    public function testAHostThatDoesNotAnswerCostsTheTimeoutAndIsOneWarningNamingIt(string $driverName, string $requiredExtension, int $port): void
    {
        if (!extension_loaded($requiredExtension)) {
            self::markTestSkipped("The extension $requiredExtension is not loaded");
        }
        $this->configureOnlyTheTableCheck(1);
        $agentInstallationCheckService = new AgentInstallationCheckWithFixtureConnectionDouble();
        $agentInstallationCheckService->setAgentInstallationChecks([]);
        $agentInstallationCheckService->setAgentFeatureTableConditionProviders([]);
        // set on the service: the entity scan of the table check reloads the config files over the test's config tree
        $agentInstallationCheckService->fixtureConnectTimeoutSeconds = 1;
        $agentInstallationCheckService->fixtureDefaultConnectionParams = [
            'driver' => $driverName,
            'host' => static::UNREACHABLE_HOST,
            'port' => $port,
            'user' => 'probe',
            'password' => 'probe',
            'dbname' => 'probe',
        ];

        $startedAt = microtime(true);
        $findings = $agentInstallationCheckService->findInstallationFindings();
        $elapsedSeconds = microtime(true) - $startedAt;

        self::assertLessThan(10, $elapsedSeconds, 'the connect is bounded by the timeout of 1 s (one attempt, not the driver default)');
        self::assertSame(['database'], $findings->getSubjects());
        $databaseFinding = $findings->getElements()[0];
        self::assertSame(AgentInstallationCheckService::CHECK_MISSING_TABLES, $databaseFinding->checkName);
        self::assertSame(AgentInstallationFinding::SEVERITY_WARNING, $databaseFinding->severity);
        self::assertStringContainsString('connect timeout of 1 s', $databaseFinding->message);
        self::assertStringNotContainsString(static::UNREACHABLE_HOST, $databaseFinding->message);
        self::assertStringContainsString('databaseConnectTimeoutSeconds', (string)$databaseFinding->hint);
    }

    public function testTheProbeAddsTheTimeoutToItsOwnDriverOptionsAndLeavesTheDefaultParametersAlone(): void
    {
        $this->configureOnlyTheTableCheck(3);
        $agentInstallationCheckService = new AgentInstallationCheckWithFixtureConnectionDouble();
        $agentInstallationCheckService->fixtureDefaultConnectionParams = [
            'driver' => 'pdo_mysql',
            'host' => 'replica.example',
            'server_version' => 'mariadb-11.8.5',
            'wrapperClass' => \stdClass::class,
            'driverOptions' => [\PDO::ATTR_EMULATE_PREPARES => true],
            'primary' => ['host' => 'primary.example', 'user' => 'app'],
            'replica' => [['host' => 'replica.example']],
        ];

        $probeConnectionParams = $agentInstallationCheckService->buildProbeConnectionParamsForTest();

        self::assertSame('primary.example', $probeConnectionParams['host']);
        self::assertSame('app', $probeConnectionParams['user']);
        self::assertSame('mariadb-11.8.5', $probeConnectionParams['serverVersion']);
        self::assertSame([\PDO::ATTR_EMULATE_PREPARES => true, \PDO::ATTR_TIMEOUT => 3], $probeConnectionParams['driverOptions']);
        foreach (['primary', 'replica', 'wrapperClass', 'pdo'] as $removedKey) {
            self::assertArrayNotHasKey($removedKey, $probeConnectionParams);
        }
        self::assertSame([\PDO::ATTR_EMULATE_PREPARES => true], $agentInstallationCheckService->fixtureDefaultConnectionParams['driverOptions']);

        $agentInstallationCheckService->fixtureDefaultConnectionParams = ['driver' => 'pdo_pgsql', 'host' => 'db.example'];
        self::assertSame([\PDO::ATTR_TIMEOUT => 3], $agentInstallationCheckService->buildProbeConnectionParamsForTest()['driverOptions']);
        if (defined('MYSQLI_OPT_CONNECT_TIMEOUT')) {
            $agentInstallationCheckService->fixtureDefaultConnectionParams = ['driver' => 'mysqli', 'host' => 'db.example'];
            self::assertSame([\MYSQLI_OPT_CONNECT_TIMEOUT => 3], $agentInstallationCheckService->buildProbeConnectionParamsForTest()['driverOptions']);
        }
    }

    public function testTheTimeoutDefaultsToFiveSecondsAndIsAtLeastOne(): void
    {
        $agentInstallationCheckService = new AgentInstallationCheckWithFixtureConnectionDouble();
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues['AI']['installationCheck'] = [];
        $configTree->setValue(null, $configValues);
        self::assertSame(AgentInstallationCheckService::DEFAULT_DATABASE_CONNECT_TIMEOUT_SECONDS, $agentInstallationCheckService->getDatabaseConnectTimeoutSeconds());
        self::assertSame(5, AgentInstallationCheckService::DEFAULT_DATABASE_CONNECT_TIMEOUT_SECONDS);

        $this->configureOnlyTheTableCheck(0);
        self::assertSame(1, $agentInstallationCheckService->getDatabaseConnectTimeoutSeconds());
    }
}
