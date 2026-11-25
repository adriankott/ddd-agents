<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Installation\AgentDocumentToolsInstallationCheckService;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use DDD\Domain\AI\Services\Installation\AgentToolImageStoreInstallationCheckService;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Symfony\Commands\Agents\AgentCheckInstallationCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:agent:check-installation` exits 1 on a BLOCKING finding and 0 otherwise, and `--format=json` prints `ok`,
 * `blocking`, `warnings` and one object per finding.
 */
class AgentCheckInstallationCommandTest extends UnitTestCase
{
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

    /** Only the binding check runs, over `$skillPackages` */
    protected function configureOnlyTheBindingCheck(array $skillPackages): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues['AI']['skillPackages'] = $skillPackages;
        $configValues['AI']['installationCheck'] = ['skippedChecks' => [
            ...array_values(array_diff(array_keys(AgentInstallationCheckService::BUILT_IN_CHECK_METHOD_NAMES), [AgentInstallationCheckService::CHECK_SKILL_BINDINGS])),
            AgentToolImageStoreInstallationCheckService::CHECK_NAME,
            AgentDocumentToolsInstallationCheckService::CHECK_NAME,
        ]];
        $configTree->setValue(null, $configValues);
    }

    public function testTheCommandIsNamedAndReadOnly(): void
    {
        $asCommand = (new ReflectionClass(AgentCheckInstallationCommand::class))->getAttributes(AsCommand::class)[0]->newInstance();
        self::assertSame('app:agent:check-installation', $asCommand->name);
    }

    public function testABlockingFindingExitsOneAndJsonCarriesTheFindings(): void
    {
        $this->configureOnlyTheBindingCheck(['Demo.Missing' => ['App\\Presentation\\Mcp\\RemovedMCPPackage']]);
        $commandTester = new CommandTester(new AgentCheckInstallationProbeCommand());

        $commandTester->execute(['--format' => 'json']);

        self::assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $jsonDocument = json_decode($commandTester->getDisplay(), true);
        self::assertFalse($jsonDocument['ok']);
        self::assertSame(1, $jsonDocument['blocking']);
        self::assertSame(0, $jsonDocument['warnings']);
        self::assertSame(['checkName', 'severity', 'subject', 'message', 'hint'], array_keys($jsonDocument['findings'][0]));
        self::assertSame(['skillBindings', 'BLOCKING', 'Demo.Missing'], [$jsonDocument['findings'][0]['checkName'], $jsonDocument['findings'][0]['severity'], $jsonDocument['findings'][0]['subject']]);
    }

    public function testNoBlockingFindingExitsZeroAndAnUnknownFormatIsInvalid(): void
    {
        $this->configureOnlyTheBindingCheck(['Agent.Tasks' => [AgentTaskSchedulesMCPPackage::class]]);
        $commandTester = new CommandTester(new AgentCheckInstallationProbeCommand());

        $commandTester->execute([]);
        self::assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        self::assertStringContainsString('OK: no finding.', $commandTester->getDisplay());

        $commandTester->execute(['--format' => 'yaml']);
        self::assertSame(Command::INVALID, $commandTester->getStatusCode());
    }
}
