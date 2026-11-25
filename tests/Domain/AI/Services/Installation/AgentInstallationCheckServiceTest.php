<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentRoleMappedAccountRights;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableCondition;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Entities\Installation\AgentPackageRenames;
use DDD\Domain\AI\Entities\Mandates\AgentMandate;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\WebSearchMCPPackage;
use DDD\Symfony\Commands\Agents\AgentCheckInstallationCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionProperty;
use Symfony\Component\Console\Application;

/**
 * The installation check reports per check: bindings that do not resolve (BLOCKING), package rights no role holds, a
 * skill that does not name its package's class-level right, renamed config keys still set, package commands the
 * console does not resolve, pending renames; accepted names are not reported, a check that throws becomes one WARNING
 * and the others run, and the checks other packages add follow the built-in ones.
 */
class AgentInstallationCheckServiceTest extends UnitTestCase
{
    /** @var array<string, mixed> */
    protected array $configValuesBefore = [];

    protected ?string $fixtureDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $this->configValuesBefore = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Config::class, 'configTree'))->setValue(null, $this->configValuesBefore);
        if ($this->fixtureDirectory !== null) {
            array_map('unlink', glob($this->fixtureDirectory . '/*') ?: []);
            rmdir($this->fixtureDirectory);
        }
        parent::tearDown();
    }

    /** Sets `AI.<key>` where Config reads it first */
    protected function setAIConfigValue(string $key, mixed $value): void
    {
        $configTree = new ReflectionProperty(Config::class, 'configTree');
        $configValues = $configTree->isInitialized() ? (array)$configTree->getValue() : [];
        $configValues['AI'][$key] = $value;
        $configTree->setValue(null, $configValues);
    }

    /** Runs only `$checkName` (every other built-in check skipped, no added check) */
    protected function runOnlyCheck(string $checkName, ?AgentInstallationCheckService $agentInstallationCheckService = null, array $installationCheckConfig = [], ?Application $consoleApplication = null): AgentInstallationFindings
    {
        $installationCheckConfig['skippedChecks'] = array_values(array_diff(array_keys(AgentInstallationCheckService::BUILT_IN_CHECK_METHOD_NAMES), [$checkName]));
        $this->setAIConfigValue('installationCheck', $installationCheckConfig);
        $agentInstallationCheckService ??= new AgentInstallationCheckService();
        $agentInstallationCheckService->setAgentInstallationChecks([]);
        return $agentInstallationCheckService->findInstallationFindings($consoleApplication);
    }

    public function testABindingToAMissingClassAnUnknownIdOrNoPackageIsBlocking(): void
    {
        $this->setAIConfigValue('skillPackages', [
            'Agent.Tasks' => [AgentTaskSchedulesMCPPackage::class],
            'Demo.Missing' => ['App\\Presentation\\Mcp\\RemovedMCPPackage'],
            'Demo.Unknown' => ['demo.unknown.package'],
            'Demo.Plain' => [AgentCheckInstallationCommand::class],
        ]);

        $findings = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_SKILL_BINDINGS);

        self::assertSame(['Demo.Missing', 'Demo.Unknown', 'Demo.Plain'], $findings->getSubjects());
        self::assertSame(3, $findings->countBlockingFindings());
        self::assertStringContainsString('a class that does not exist', $findings->getElements()[0]->message);
        self::assertStringContainsString('an id no installed MCP package has', $findings->getElements()[1]->message);
        self::assertStringContainsString('no MCP package', $findings->getElements()[2]->message);
    }

    public function testAPackageRightNoRoleHoldsIsReportedUnlessMappedDelegatedOrAccepted(): void
    {
        $this->setAIConfigValue('accountRights', [
            AgentAccountRights::OPERATE_PLATFORM => [Role::SUPERADMIN],
            AgentAccountRights::USE_WEB => [AgentRoleMappedAccountRights::DELEGATED_RIGHT_KEY => 'researchTheWeb'],
            AgentAccountRights::USE_AGENT_MEMORY => [],
        ]);

        $findings = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_RIGHTS_HELD_BY_NOBODY, null, ['acceptedUnmappedRights' => [AgentAccountRights::USE_UI]]);
        $reportedRightNames = $findings->getSubjects();

        self::assertContains(AgentAccountRights::USE_AGENT_MEMORY, $reportedRightNames, 'a right mapped to no role is held by nobody');
        self::assertContains(AgentAccountRights::SCHEDULE_AGENT_TASKS, $reportedRightNames, 'a right the mapping does not name is held by nobody');
        self::assertNotContains(AgentAccountRights::OPERATE_PLATFORM, $reportedRightNames);
        self::assertNotContains(AgentAccountRights::USE_WEB, $reportedRightNames, 'a delegation holds the right through the application');
        self::assertNotContains(AgentAccountRights::USE_UI, $reportedRightNames, 'an accepted right is not reported');
        self::assertSame(0, $findings->countBlockingFindings());
        self::assertStringContainsString("unless the application's rights object holds it", $findings->getElements()[0]->message);
    }

    public function testAnApplicationRightsObjectWithoutAMappingOfItsOwnNamesThePackageRightsHeldByNobody(): void
    {
        $findings = $this->runOnlyCheck(
            AgentInstallationCheckService::CHECK_ACCOUNT_RIGHTS_MAPPING,
            new ApplicationRightsWithoutMappingInstallationCheckServiceDouble(),
            ['acceptedUnmappedRights' => [AgentAccountRights::USE_WEB]]
        );

        self::assertCount(1, $findings->getElements());
        self::assertSame(AgentInstallationFinding::SEVERITY_WARNING, $findings->getElements()[0]->severity);
        self::assertStringContainsString(AgentAccountRights::USE_UI, $findings->getElements()[0]->message);
        self::assertStringContainsString(AgentAccountRights::SCHEDULE_AGENT_TASKS, $findings->getElements()[0]->message);
        self::assertStringNotContainsString(AgentAccountRights::USE_WEB . ',', $findings->getElements()[0]->message . ',');
        self::assertSame([], $this->runOnlyCheck(AgentInstallationCheckService::CHECK_RIGHTS_HELD_BY_NOBODY, new ApplicationRightsWithoutMappingInstallationCheckServiceDouble())->getElements());
        self::assertSame([], $this->runOnlyCheck(AgentInstallationCheckService::CHECK_ACCOUNT_RIGHTS_MAPPING)->getElements(), 'the package rights model is not reported');
    }

    public function testASkillThatDoesNotNameTheClassLevelRightOfItsPackageIsReportedUnlessAccepted(): void
    {
        $this->setAIConfigValue('skillPackages', [
            'Agent.Tasks' => [WebSearchMCPPackage::class],
            'Agent.Web.Search' => [WebSearchMCPPackage::class],
        ]);

        $findings = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_SKILL_RIGHTS);
        self::assertSame(['Agent.Tasks'], $findings->getSubjects());
        self::assertStringContainsString(AgentAccountRights::USE_WEB, $findings->getElements()[0]->message);
        self::assertStringContainsString("requiredAccountRights: ['useWeb']", (string)$findings->getElements()[0]->hint);

        $acceptedFindings = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_SKILL_RIGHTS, null, ['acceptedSkillRightGaps' => [WebSearchMCPPackage::class]]);
        self::assertSame(0, $acceptedFindings->count());
    }

    public function testAToolOverlayWithAnUnknownKeyOrAnUnknownToolNameIsReported(): void
    {
        $this->setAIConfigValue('skillPackages', ['Agent.Tasks' => [AgentTaskSchedulesMCPPackage::class]]);
        $this->setAIConfigValue('toolOverlays', [
            'getTaskSchedules' => ['description' => 'The tasks of the space.', 'sumary' => 'typo'],
            'scheduleOneTimeTask' => ['accountRights' => []],
            'removedTool' => ['description' => 'gone'],
        ]);

        $findings = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_TOOL_OVERLAYS);

        self::assertSame(['getTaskSchedules', 'removedTool'], $findings->getSubjects());
        self::assertStringContainsString('sumary', $findings->getElements()[0]->message);
        self::assertStringContainsString('no package of the skill binding has', $findings->getElements()[1]->message);
    }

    public function testARenamedConfigKeyStillSetIsReportedWithItsNewName(): void
    {
        $agentInstallationCheckService = new AgentInstallationCheckServiceWithFixtureRenamesDouble();
        $agentInstallationCheckService->fixturePackageRenames = $this->buildFixturePackageRenames();
        $this->setAIConfigValue('installationCheckDemoOldKey', ['enabled' => true]);

        $findings = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_RENAMED_CONFIG_KEYS, $agentInstallationCheckService);

        self::assertSame(['AI.installationCheckDemoOldKey'], $findings->getSubjects());
        self::assertStringContainsString("renamed to 'AI.installationCheckDemoNewKey' in acme/agent-demo 0.3.0", $findings->getElements()[0]->message);
    }

    public function testRenamesNewerThanTheRecordAndNotNewerThanTheInstalledVersionArePending(): void
    {
        $agentInstallationCheckService = new AgentInstallationCheckServiceWithFixtureRenamesDouble();
        $agentInstallationCheckService->fixturePackageRenames = $this->buildFixturePackageRenames();

        $findingsWithoutRecord = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_PENDING_RENAMES, $agentInstallationCheckService);
        self::assertSame(['acme/agent-demo'], $findingsWithoutRecord->getSubjects());
        self::assertStringContainsString('3 rename(s) of acme/agent-demo are pending (0.3.0, 0.4.0)', $findingsWithoutRecord->getElements()[0]->message, 'the 0.5.0 rename is newer than the installed 0.4.0');

        $agentInstallationCheckService->fixtureAppliedThroughVersionByPackageName = ['acme/agent-demo' => '0.3.0'];
        $findingsAfterRecord = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_PENDING_RENAMES, $agentInstallationCheckService);
        self::assertStringContainsString('1 rename(s) of acme/agent-demo are pending (0.4.0)', $findingsAfterRecord->getElements()[0]->message);

        $agentInstallationCheckService->fixtureAppliedThroughVersionByPackageName = ['acme/agent-demo' => '0.4.0'];
        self::assertSame(0, $this->runOnlyCheck(AgentInstallationCheckService::CHECK_PENDING_RENAMES, $agentInstallationCheckService)->count());
    }

    public function testAPackageCommandTheConsoleDoesNotResolveIsReported(): void
    {
        $consoleApplication = new Application();
        $consoleApplication->add(new AgentCheckInstallationCommand());

        $findings = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_CONSOLE_COMMANDS, null, [], $consoleApplication);

        self::assertContains('app:agent:reap', $findings->getSubjects());
        self::assertContains('app:agent:validate-conversation', $findings->getSubjects());
        self::assertContains('app:ai-agent:validate-conversation', $findings->getSubjects(), 'an alias is a name too');
        self::assertNotContains('app:agent:check-installation', $findings->getSubjects());
        self::assertSame(0, $this->runOnlyCheck(AgentInstallationCheckService::CHECK_CONSOLE_COMMANDS)->count(), 'without a console the check is skipped');
    }

    public function testAThrowingCheckIsOneWarningAndTheAddedChecksFollowTheBuiltInOnes(): void
    {
        $this->setAIConfigValue('skillPackages', ['Demo.Missing' => ['App\\Presentation\\Mcp\\RemovedMCPPackage']]);
        $this->setAIConfigValue('installationCheck', [
            'skippedChecks' => array_values(array_diff(array_keys(AgentInstallationCheckService::BUILT_IN_CHECK_METHOD_NAMES), [AgentInstallationCheckService::CHECK_SKILL_BINDINGS])),
        ]);
        $agentInstallationCheckService = new AgentInstallationCheckService();
        $agentInstallationCheckService->setAgentInstallationChecks([new AgentInstallationCheckThrowingDouble(), new AgentInstallationCheckWithOneFindingDouble()]);

        $findings = $agentInstallationCheckService->findInstallationFindings();

        self::assertSame(['Demo.Missing', 'brokenHostCheck', 'featureStore'], $findings->getSubjects());
        $brokenCheckFinding = $findings->getElements()[1];
        self::assertSame('brokenHostCheck', $brokenCheckFinding->checkName);
        self::assertSame(AgentInstallationFinding::SEVERITY_WARNING, $brokenCheckFinding->severity);
        self::assertSame('The check could not run: the host store did not answer', $brokenCheckFinding->message);
        self::assertSame(2, $findings->countBlockingFindings());
        self::assertContains('hostFeatureStore', $agentInstallationCheckService->getCheckNames());
    }

    public function testADatabaseThatDoesNotAnswerIsReportedWithoutTheDriverText(): void
    {
        $agentInstallationCheckService = new AgentInstallationCheckWithFixtureTablesDouble();
        $agentInstallationCheckService->setAgentFeatureTableConditionProviders([]);
        $agentInstallationCheckService->fixtureLiveTableNames = null;

        $findings = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_MISSING_TABLES, $agentInstallationCheckService);

        self::assertSame(['database'], $findings->getSubjects());
        $message = $findings->getElements()[0]->message;
        self::assertStringContainsString('the database did not answer (RuntimeException;', $message);
        foreach (['agent_app', 'db.internal.example', 'secret', 'SQLSTATE', 'mysql://'] as $leakedPart) {
            self::assertStringNotContainsString($leakedPart, $message);
        }
    }

    public function testAMissingTableOfAFeatureThatIsOffOrUnboundIsNotBlocking(): void
    {
        $agentInstallationCheckService = new AgentInstallationCheckWithFixtureTablesDouble();
        $agentInstallationCheckService->fixtureLiveTableNames = [];
        $agentInstallationCheckService->setAgentFeatureTableConditionProviders([]);
        $findingsWithoutConditions = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_MISSING_TABLES, $agentInstallationCheckService);
        self::assertContains('AgentMandates', $findingsWithoutConditions->getSubjects());
        self::assertContains('AgentSpaces', $findingsWithoutConditions->getSubjects());

        $agentFeatureTableConditions = new AgentFeatureTableConditions();
        $mandatesCondition = AgentFeatureTableCondition::create(AgentMandate::class, 'the mandates (demo switch)', false);
        $agentFeatureTableConditions->add($mandatesCondition);
        $spacesCondition = AgentFeatureTableCondition::create(AgentSpace::class, 'the spaces', null, 'Demo.NotBound');
        $agentFeatureTableConditions->add($spacesCondition);
        $agentInstallationCheckService->setAgentFeatureTableConditionProviders([new AgentFeatureTableConditionProviderDouble($agentFeatureTableConditions)]);
        $findingsWithConditions = $this->runOnlyCheck(AgentInstallationCheckService::CHECK_MISSING_TABLES, $agentInstallationCheckService);

        self::assertNotContains('AgentMandates', $findingsWithConditions->getSubjects(), 'switched off');
        self::assertNotContains('AgentSpaces', $findingsWithConditions->getSubjects(), 'its skill is not bound');
        self::assertSame($findingsWithoutConditions->count() - 2, $findingsWithConditions->count());

        $this->setAIConfigValue('skillPackages', ['Demo.NotBound' => []]);
        self::assertSame("the skill 'Demo.NotBound' is not bound", AgentFeatureTableCondition::create(AgentSpace::class, 'the spaces', true, 'Demo.NotBound')->findReasonTableIsNotRequired([]));
        self::assertNull(AgentFeatureTableCondition::create(AgentSpace::class, 'the spaces', true, 'Demo.NotBound')->findReasonTableIsNotRequired(['Demo.NotBound']));
    }

    public function testTheRenamesRecordIsFoundInAParentDirectoryUpToTheRepositoryRootOrAtTheConfiguredPath(): void
    {
        $this->fixtureDirectory = sys_get_temp_dir() . '/agent-installation-check-' . bin2hex(random_bytes(4));
        $repositoryDirectory = $this->fixtureDirectory . '/repository';
        mkdir($repositoryDirectory . '/.git', 0777, true);
        mkdir($repositoryDirectory . '/app');
        file_put_contents($repositoryDirectory . '/.ddd-renames.json', '{}');
        file_put_contents($this->fixtureDirectory . '/.ddd-renames.json', '{}');
        $agentInstallationCheckService = new AgentInstallationCheckWithFixtureTablesDouble();
        $agentInstallationCheckService->fixtureProjectDirectory = $repositoryDirectory . '/app';
        $this->setAIConfigValue('installationCheck', []);

        self::assertSame($repositoryDirectory . '/.ddd-renames.json', $agentInstallationCheckService->findRenamesStateFile(), 'the repository root of a project in a sub-folder');

        unlink($repositoryDirectory . '/.ddd-renames.json');
        self::assertNull($agentInstallationCheckService->findRenamesStateFile(), 'never above the repository root');

        mkdir($repositoryDirectory . '/state');
        file_put_contents($repositoryDirectory . '/state/renames.json', '{}');
        $this->setAIConfigValue('installationCheck', ['renamesStateFile' => '../state/renames.json']);
        self::assertSame($repositoryDirectory . '/app/../state/renames.json', $agentInstallationCheckService->findRenamesStateFile());
        exec('rm -rf ' . escapeshellarg($this->fixtureDirectory));
        $this->fixtureDirectory = null;
    }

    protected function buildFixturePackageRenames(): AgentPackageRenames
    {
        $this->fixtureDirectory = sys_get_temp_dir() . '/agent-installation-check-' . bin2hex(random_bytes(4));
        mkdir($this->fixtureDirectory);
        file_put_contents($this->fixtureDirectory . '/composer.json', json_encode(['name' => 'acme/agent-demo', 'version' => '0.4.0']));
        file_put_contents($this->fixtureDirectory . '/renames.json', json_encode([
            'format' => 1,
            'renames' => [
                ['version' => '0.3.0', 'kind' => 'configKey', 'old' => 'AI.installationCheckDemoOldKey', 'new' => 'AI.installationCheckDemoNewKey'],
                ['version' => '0.3.0', 'kind' => 'skillName', 'old' => 'Demo.Old', 'new' => 'Demo.New'],
                ['version' => '0.4.0', 'kind' => 'consoleCommand', 'old' => 'app:demo:old', 'new' => 'app:demo:new'],
                ['version' => '0.5.0', 'kind' => 'envVar', 'old' => 'DEMO_OLD', 'new' => 'DEMO_NEW'],
                ['version' => 'next', 'kind' => 'envVar', 'old' => 'DEMO_SKIPPED', 'new' => 'DEMO_NEWER'],
            ],
        ]));
        $agentPackageRenames = AgentPackageRenames::newInstance();
        $agentPackageRenames->addRenamesOfPackageDirectory($this->fixtureDirectory, AgentInstallationCheckService::RENAMES_FILE_NAME);
        return $agentPackageRenames;
    }
}
