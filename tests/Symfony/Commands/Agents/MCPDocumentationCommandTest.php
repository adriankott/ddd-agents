<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\MCPDocumentationCommand;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Archive\ProbeArchiveMCPPackage;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents\ProbeDocumentsMCPBundle;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents\ProbeDocumentsMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The documentation command lists the bundles and packages of its source roots, documents one of them fresh unless
 * `--cache` is passed, and runs a tool call as the owner of the space the input names (the space on the provenance)
 * or as an admin account; a tool that reports an error ends the run with a failure on both paths. The measurement
 * points enclose the tool call for a space; a scope that cannot be resolved ends the run before the tool.
 */
class MCPDocumentationCommandTest extends UnitTestCase
{
    public const int ADMIN_ACCOUNT_ID = 11;

    protected ?Account $accountBeforeTest = null;

    protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble;

    protected RecordingToolExecutorDouble $toolExecutorDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        self::setSingletonInstance(AuthService::class, new CountingAuthServiceDouble());
        $this->agentSpacesServiceDouble = new AgentSpacesServiceConsoleScopeDouble();
        $this->toolExecutorDouble = new RecordingToolExecutorDouble();
        ProbeDocumentsMCPBundle::$noCacheFlagsOfBuilds = [];
        ProbeDocumentsMCPPackage::$noCacheFlagsOfBuilds = [];
    }

    protected function tearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBeforeTest);
        self::resetSingletonInstance(AuthService::class);
        parent::tearDown();
    }

    public function testTheCommandKeepsItsNameOptionsAndRunsOnTheBase(): void
    {
        $asCommandAttributes = (new ReflectionClass(MCPDocumentationCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:mcp:documentation', $asCommandAttributes[0]->newInstance()->name);

        $mCPDocumentationCommand = $this->buildProbeCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $mCPDocumentationCommand);
        foreach (['bundle', 'package', 'list', 'cache', 'call', 'asAdminAccountId', 'arguments', 'dump-images', AgentConsoleCommand::OPTION_IGNORE_BUDGET, AgentConsoleCommand::OPTION_AGENT_SPACE_ID, AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME] as $optionName) {
            self::assertTrue($mCPDocumentationCommand->getDefinition()->hasOption($optionName), $optionName);
        }
    }

    public function testTheListNamesTheConcreteBundlesAndPackagesOfTheSourceRoots(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        $exitCode = $commandTester->execute(['--list' => true]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertMatchesRegularExpression('/ProbeDocumentsMCPBundle\s+Probe documents bundle/', $display);
        self::assertMatchesRegularExpression('/ProbeArchiveMCPPackage\s+Probe archive package\n\s+ProbeDocumentsMCPPackage\s+Probe documents package/', $display);
        self::assertStringNotContainsString('AbstractProbeMCPPackage', $display);
        self::assertStringNotContainsString('ProbeDocumentsNoteFormatter', $display);
    }

    public function testABundleAndAPackageAreDocumentedFreshUnlessTheCacheIsAskedFor(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        self::assertSame(Command::SUCCESS, $commandTester->execute(['--bundle' => 'probedocumentsmcpbundle']));
        self::assertStringContainsString('"name": "probe-documents"', $commandTester->getDisplay());
        self::assertStringContainsString('── total (whole document)', $commandTester->getDisplay());
        self::assertSame(Command::SUCCESS, $commandTester->execute(['--bundle' => 'ProbeDocumentsMCPBundle', '--cache' => true]));
        self::assertSame([true, false], ProbeDocumentsMCPBundle::$noCacheFlagsOfBuilds);

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--package' => 'ProbeDocumentsMCPPackage']));
        self::assertSame(Command::SUCCESS, $commandTester->execute(['--package' => '\\' . ProbeDocumentsMCPPackage::class, '--cache' => true]));
        self::assertSame([true, false], ProbeDocumentsMCPPackage::$noCacheFlagsOfBuilds, 'a package is rebuilt fresh by default, as a bundle is');
    }

    public function testAnUnknownOrWrongKindOfClassIsRefused(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        self::assertSame(Command::FAILURE, $commandTester->execute(['--bundle' => 'NoSuchBundle']));
        self::assertStringContainsString("Class not found for 'NoSuchBundle'. Use --list to see available classes.", $commandTester->getDisplay());
        self::assertSame(Command::FAILURE, $commandTester->execute(['--bundle' => ProbeArchiveMCPPackage::class]));
        self::assertStringContainsString(ProbeArchiveMCPPackage::class . ' is not a MCPBundle.', $commandTester->getDisplay());
        self::assertSame(Command::INVALID, $commandTester->execute([]));
        self::assertStringContainsString('Provide --bundle=… , --package=… , --call=… , or --list.', $commandTester->getDisplay());
    }

    public function testACallRunsAsTheOwnerOfTheNamedSpaceWithTheSpaceOnTheProvenance(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        $exitCode = $commandTester->execute([
            '--call' => 'searchDocuments',
            '--' . AgentConsoleCommand::OPTION_AGENT_SPACE_ID => (string)AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            '--arguments' => '{"query":"invoice"}',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('searchDocuments', $this->toolExecutorDouble->calledToolName);
        self::assertSame(['query' => 'invoice'], $this->toolExecutorDouble->calledToolArguments);
        self::assertSame([ProbeArchiveMCPPackage::class, ProbeDocumentsMCPPackage::class], $this->toolExecutorDouble->calledPackageClassNames);
        $agentToolCallProvenance = $this->toolExecutorDouble->calledServerProvidedInput;
        self::assertInstanceOf(AgentToolCallProvenance::class, $agentToolCallProvenance);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID, $agentToolCallProvenance->agentSpace?->id);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID, $this->toolExecutorDouble->accountIdDuringCall);
        self::assertStringContainsString('{"documents":[]}', $commandTester->getDisplay());
        self::assertStringContainsString('response:', $commandTester->getDisplay());
    }

    public function testACallFindsItsSpaceThroughTheApplicationsOption(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        $exitCode = $commandTester->execute([
            '--call' => 'searchDocuments',
            '--' . AgentSpacesServiceConsoleScopeDouble::SCOPE_OPTION_NAME => AgentSpacesServiceConsoleScopeDouble::KNOWN_SCOPE_OPTION_VALUE,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $this->toolExecutorDouble->calledToolArguments);
        self::assertSame(AgentSpacesServiceConsoleScopeDouble::AGENT_SPACE_ID_OF_KNOWN_SCOPE_OPTION_VALUE, $this->toolExecutorDouble->calledServerProvidedInput?->agentSpace?->id);
    }

    public function testACallWithoutASpaceOrWithAnUnreadableSpaceOrBadArgumentsIsRefusedBeforeTheTool(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        self::assertSame(Command::INVALID, $commandTester->execute(['--call' => 'searchDocuments']));
        self::assertStringContainsString('--call needs a space: --agentSpaceId or --externalSpaceKey (or --asAdminAccountId for an admin tool).', $commandTester->getDisplay());

        self::assertSame(Command::INVALID, $commandTester->execute(['--call' => 'searchDocuments', '--agentSpaceId' => '999']));
        self::assertStringContainsString('No space with the id 999', $commandTester->getDisplay());

        self::assertSame(Command::INVALID, $commandTester->execute(['--call' => 'searchDocuments', '--agentSpaceId' => '7', '--arguments' => '"text"']));
        self::assertStringContainsString('--arguments must be a JSON object.', $commandTester->getDisplay());

        self::assertSame(Command::INVALID, $commandTester->execute(['--call' => 'searchDocuments', '--asAdminAccountId' => 'x']));
        self::assertStringContainsString('--asAdminAccountId must be numeric.', $commandTester->getDisplay());
        self::assertSame(0, $this->toolExecutorDouble->callCount);
    }

    public function testAToolErrorFailsTheRunOnTheSpacePathAndOnTheAdminPath(): void
    {
        $this->toolExecutorDouble->scriptedToolResult = new ToolResult('refused: admin access required', true);
        $adminAccount = Account::newInstance();
        $adminAccount->id = self::ADMIN_ACCOUNT_ID;
        $commandTester = new CommandTester($this->buildProbeCommand([self::ADMIN_ACCOUNT_ID => $adminAccount]));

        self::assertSame(Command::FAILURE, $commandTester->execute(['--call' => 'findAgentConversations', '--asAdminAccountId' => (string)self::ADMIN_ACCOUNT_ID]));
        self::assertNull($this->toolExecutorDouble->calledServerProvidedInput, 'an admin call carries no space');
        self::assertSame(self::ADMIN_ACCOUNT_ID, $this->toolExecutorDouble->accountIdDuringCall);
        self::assertStringContainsString('[tool reported an error]', $commandTester->getDisplay());

        self::assertSame(Command::FAILURE, $commandTester->execute(['--call' => 'searchDocuments', '--agentSpaceId' => '7']));
        self::assertStringContainsString('[tool reported an error]', $commandTester->getDisplay());

        self::assertSame(Command::FAILURE, $commandTester->execute(['--call' => 'findAgentConversations', '--asAdminAccountId' => '12']));
        self::assertStringContainsString('No account 12.', $commandTester->getDisplay());
    }

    public function testTheImagePartsOfAResultAreReportedAndWrittenOnRequest(): void
    {
        $pixelPng = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $toolResult = new ToolResult('one picture');
        $toolResultImage = new ToolResultImage();
        $toolResultImage->base64Data = $pixelPng;
        $toolResultImage->mimeType = 'image/png';
        $toolResultImage->width = 1600;
        $toolResultImage->height = 700;
        $toolResultImage->byteSize = 68;
        $toolResult->images = [$toolResultImage];
        $this->toolExecutorDouble->scriptedToolResult = $toolResult;
        $dumpDirectory = sys_get_temp_dir() . '/mcp-documentation-images-' . bin2hex(random_bytes(4));

        try {
            $commandTester = new CommandTester($this->buildProbeCommand());
            self::assertSame(Command::SUCCESS, $commandTester->execute(['--call' => 'renderPreview', '--agentSpaceId' => '7', '--dump-images' => $dumpDirectory]));
            self::assertStringContainsString('image 1: image/png 1600x700 px, 68 bytes, ≈774 vision tokens (768 px tiles) → ' . $dumpDirectory . '/renderPreview-1.png', $commandTester->getDisplay());
            self::assertSame(base64_decode($pixelPng), file_get_contents($dumpDirectory . '/renderPreview-1.png'));
        } finally {
            @unlink($dumpDirectory . '/renderPreview-1.png');
            @rmdir($dumpDirectory);
        }
    }

    public function testTheMeasurementPointsEncloseTheToolCallForASpaceAndTheReportPrintsBeforeTheResult(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $commandTester = new CommandTester($probeCommand);

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--call' => 'searchDocuments', '--agentSpaceId' => '7']));

        self::assertSame(['start:searchDocuments@0', 'report:searchDocuments@1'], $probeCommand->measurementEvents);
        $display = $commandTester->getDisplay();
        self::assertLessThan(strpos($display, '{"documents":[]}'), strpos($display, MCPDocumentationProbeCommand::MEASUREMENT_REPORT_LINE));
    }

    public function testAToolCallThatThrowsStartsTheMeasurementAndReportsNothing(): void
    {
        $this->toolExecutorDouble->errorToThrow = new RuntimeException('the tool lost its connection');
        $probeCommand = $this->buildProbeCommand();
        $commandTester = new CommandTester($probeCommand);

        self::assertSame(Command::FAILURE, $commandTester->execute(['--call' => 'searchDocuments', '--agentSpaceId' => '7']));

        self::assertSame(['start:searchDocuments@0'], $probeCommand->measurementEvents);
        self::assertStringContainsString('the tool lost its connection', $commandTester->getDisplay());
        self::assertStringNotContainsString(MCPDocumentationProbeCommand::MEASUREMENT_REPORT_LINE, $commandTester->getDisplay());
    }

    public function testAnAdminCallIsNotMeasured(): void
    {
        $adminAccount = Account::newInstance();
        $adminAccount->id = self::ADMIN_ACCOUNT_ID;
        $probeCommand = $this->buildProbeCommand([self::ADMIN_ACCOUNT_ID => $adminAccount]);

        (new CommandTester($probeCommand))->execute(['--call' => 'findAgentConversations', '--asAdminAccountId' => (string)self::ADMIN_ACCOUNT_ID]);

        self::assertSame(1, $this->toolExecutorDouble->callCount);
        self::assertSame([], $probeCommand->measurementEvents);
    }

    public function testAScopeThatCannotBeResolvedEndsTheRunWithFailureBeforeTheTool(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->scopeErrorToThrow = new RuntimeException('the space store is unreachable');
        $commandTester = new CommandTester($probeCommand);

        self::assertSame(Command::FAILURE, $commandTester->execute(['--call' => 'searchDocuments', '--agentSpaceId' => '7']));

        self::assertStringContainsString('the space store is unreachable', $commandTester->getDisplay());
        self::assertSame(0, $this->toolExecutorDouble->callCount);
        self::assertSame([], $probeCommand->measurementEvents);
    }

    public function testADocumentationThatIsNoJsonIsPrintedAsGivenWithoutATokenReport(): void
    {
        $mCPDocumentationCommand = $this->buildProbeCommand();
        $bufferedOutput = new BufferedOutput();

        self::assertSame('not a json document', self::invokeMethod($mCPDocumentationCommand, 'buildPrettyJson', ['not a json document']));
        self::invokeMethod($mCPDocumentationCommand, 'renderDocumentationTokenReport', ['not a json document', $bufferedOutput]);
        self::assertSame('', $bufferedOutput->fetch());
    }

    public function testTheCommandRestoresTheAccountOfBeforeTheRun(): void
    {
        $accountBeforeRun = AuthService::instance()->getAccount();
        $commandTester = new CommandTester($this->buildProbeCommand());
        $commandTester->execute(['--call' => 'searchDocuments', '--agentSpaceId' => '7']);

        self::assertSame($accountBeforeRun, AuthService::instance()->getAccount());
    }

    /**
     * @param Account[] $storedAccountsById
     * @return MCPDocumentationProbeCommand
     */
    protected function buildProbeCommand(array $storedAccountsById = []): MCPDocumentationProbeCommand
    {
        return new MCPDocumentationProbeCommand($this->agentSpacesServiceDouble, $this->toolExecutorDouble, $storedAccountsById);
    }
}
