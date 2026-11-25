<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\MCPClearCacheCommand;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Archive\ProbeArchiveMCPPackage;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents\ProbeDocumentsMCPBundle;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents\ProbeDocumentsMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The clear-cache command clears the documentation cache of every bundle it names and of every package bound to a
 * skill, runs the container cache clear in a child process (the PHP binary and console script of this process, the
 * environment and debug mode of the running kernel) and ends with the restart notice. A child that ends non-zero ends
 * the command non-zero with lines naming layer 2, the exit code and the completed layer 1, and its leftover warm-up
 * directory is removed (or named when it cannot be); after a successful child nothing is removed. `--onlyPackageCaches`
 * clears layer 1 alone and starts no child. The module's default bundles are the concrete bundles of the source roots
 * (none in the module itself), its packages those of the skill binding. A skill entry that names no MCP package class
 * is skipped with a line.
 */
class MCPClearCacheCommandTest extends UnitTestCase
{
    /** @var string The environment variable the fixture console exits with */
    public const string FIXTURE_CHILD_EXIT_CODE_VARIABLE = 'AGENTS_TEST_CHILD_EXIT_CODE';

    /** @var string|null The temporary folder of a test that creates directories; removed after the test */
    protected ?string $temporaryDirectoryPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        ProbeDocumentsMCPBundle::$documentationCacheClearCount = 0;
        ProbeDocumentsMCPPackage::$documentationCacheClearCount = 0;
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::FIXTURE_CHILD_EXIT_CODE_VARIABLE]);
        if ($this->temporaryDirectoryPath !== null && is_dir($this->temporaryDirectoryPath)) {
            $directoryEntries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporaryDirectoryPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            /** @var SplFileInfo $directoryEntry */
            foreach ($directoryEntries as $directoryEntry) {
                $directoryEntry->isDir() ? rmdir($directoryEntry->getPathname()) : unlink($directoryEntry->getPathname());
            }
            rmdir($this->temporaryDirectoryPath);
        }
        parent::tearDown();
    }

    public function testTheCommandKeepsItsNameAndRunsOnTheBase(): void
    {
        $asCommandAttributes = (new ReflectionClass(MCPClearCacheCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:mcp:clear-cache', $asCommandAttributes[0]->newInstance()->name);
        self::assertInstanceOf(AgentConsoleCommand::class, new MCPClearCacheCommand());
    }

    public function testEveryBundleAndSkillPackageIsClearedThenTheContainerAndTheNoticeFollow(): void
    {
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([ProbeDocumentsMCPBundle::class], [ProbeDocumentsMCPPackage::class, ProbeArchiveMCPPackage::class]);
        $application = new Application();
        $application->addCommands([$mCPClearCacheProbeCommand]);

        $commandTester = new CommandTester($application->find(MCPClearCacheProbeCommand::PROBE_COMMAND_NAME));
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, ProbeDocumentsMCPBundle::$documentationCacheClearCount);
        self::assertSame([ProbeDocumentsMCPPackage::class, ProbeArchiveMCPPackage::class], $mCPClearCacheProbeCommand->clearedPackageClassNames);
        self::assertCount(1, $mCPClearCacheProbeCommand->startedChildArgumentLists);
        self::assertStringContainsString('cleared bundle doc cache: ' . ProbeDocumentsMCPBundle::class, $display);
        self::assertStringContainsString('cleared package doc cache: ' . ProbeArchiveMCPPackage::class, $display);
        self::assertStringContainsString('Layer 1 — MCP doc cache cleared: 1 bundles + 2 skill packages.', $display);
        self::assertStringContainsString('Layer 2 — rebuilding the compiled container (cache:clear) …', $display);
        self::assertStringContainsString('container cache cleared', $display);
        self::assertStringContainsString('Now RESTART the agent workers', $display);
        self::assertLessThan(strpos($display, 'Now RESTART'), strpos($display, 'container cache cleared'));
    }

    public function testASkillEntryThatNamesNoPackageClassIsSkippedAndTheRunGoesOn(): void
    {
        $missingPackageClassName = 'DDD\\Tests\\Agents\\Fixtures\\MCPClassCatalog\\Presentation\\Mcp\\Archive\\RemovedProbeMCPPackage';
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([], [$missingPackageClassName, ProbeDocumentsMCPBundle::class, ProbeArchiveMCPPackage::class]);
        $application = new Application();
        $application->addCommands([$mCPClearCacheProbeCommand]);

        $commandTester = new CommandTester($application->find(MCPClearCacheProbeCommand::PROBE_COMMAND_NAME));
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([ProbeArchiveMCPPackage::class], $mCPClearCacheProbeCommand->clearedPackageClassNames);
        self::assertStringContainsString("skipped skill package entry: $missingPackageClassName (no MCP package class of that name)", $display);
        self::assertStringContainsString('skipped skill package entry: ' . ProbeDocumentsMCPBundle::class . ' (no MCP package class of that name)', $display, 'a bundle is no package');
        self::assertStringContainsString('Layer 1 — MCP doc cache cleared: 0 bundles + 1 skill packages.', $display);
        self::assertCount(1, $mCPClearCacheProbeCommand->startedChildArgumentLists, 'the container is still rebuilt');
    }

    public function testWithoutAConsoleApplicationTheChildStillRebuildsTheContainer(): void
    {
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([], []);
        $commandTester = new CommandTester($mCPClearCacheProbeCommand);
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Layer 2 — rebuilding the compiled container (cache:clear) …', $display);
        self::assertCount(1, $mCPClearCacheProbeCommand->startedChildArgumentLists, 'the child needs no console application');
        self::assertStringContainsString('container cache cleared', $display);
        self::assertStringContainsString('Now RESTART the agent workers', $display);
    }

    public function testTheChildRunsTheConsoleScriptInTheKernelsEnvironmentWithNoDebugForANonDebugKernel(): void
    {
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([], []);
        $mCPClearCacheProbeCommand->consoleScriptPath = '/srv/project/bin/console';
        $mCPClearCacheProbeCommand->kernelEnvironment = 'prod';
        $mCPClearCacheProbeCommand->kernelInDebugMode = false;

        (new CommandTester($mCPClearCacheProbeCommand))->execute([]);

        self::assertSame(
            [[PHP_BINARY, '/srv/project/bin/console', 'cache:clear', '--env=prod', '--no-debug']],
            $mCPClearCacheProbeCommand->startedChildArgumentLists
        );
    }

    public function testTheChildOfADebugKernelGetsNoNoDebug(): void
    {
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([], []);
        $mCPClearCacheProbeCommand->consoleScriptPath = '/srv/project/bin/console';
        $mCPClearCacheProbeCommand->kernelEnvironment = 'dev';
        $mCPClearCacheProbeCommand->kernelInDebugMode = true;

        (new CommandTester($mCPClearCacheProbeCommand))->execute([]);

        self::assertSame(
            [[PHP_BINARY, '/srv/project/bin/console', 'cache:clear', '--env=dev']],
            $mCPClearCacheProbeCommand->startedChildArgumentLists
        );
    }

    public function testTheChildArgumentsDefaultToThisProcessAndTheRunningKernel(): void
    {
        $runningKernel = DDDService::instance()->getKernel();
        $expectedChildArguments = [PHP_BINARY, (string)realpath((string)$_SERVER['SCRIPT_FILENAME']), 'cache:clear', '--env=' . $runningKernel->getEnvironment()];
        if (!$runningKernel->isDebug()) {
            $expectedChildArguments[] = '--no-debug';
        }

        self::assertSame($expectedChildArguments, self::invokeMethod(new MCPClearCacheCommand(), 'buildContainerCacheClearChildArguments'));
        self::assertSame($runningKernel->getBuildDir(), self::invokeMethod(new MCPClearCacheCommand(), 'getRunningKernelBuildDirectory'));
    }

    public function testARealChildPassesItsExitCodeAndBothOutputsThrough(): void
    {
        // the process component hands on the variables this process started with and those of $_ENV
        $_ENV[self::FIXTURE_CHILD_EXIT_CODE_VARIABLE] = '3';
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([], []);
        $mCPClearCacheProbeCommand->startsRealChildProcess = true;
        $mCPClearCacheProbeCommand->consoleScriptPath = dirname(__DIR__, 3) . '/Fixtures/Console/container-cache-clear-console.php';

        $commandTester = new CommandTester($mCPClearCacheProbeCommand);
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('child standard output: cache:clear --env=test --no-debug', $display);
        self::assertStringContainsString('child error output', $display);
        self::assertStringContainsString('Layer 2 FAILED: cache:clear ended with exit code 3', $display);
    }

    public function testAFailedChildEndsTheCommandNonZeroNamesLayerTwoAndRemovesTheWarmupDirectory(): void
    {
        $buildDirectoryPath = $this->createTemporaryDirectory() . '/cache/test';
        $warmupDirectoryPath = dirname($buildDirectoryPath) . '/tes_';
        $this->createDirectoryWithFile($buildDirectoryPath);
        $this->createDirectoryWithFile($warmupDirectoryPath . '/Container');
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([ProbeDocumentsMCPBundle::class], []);
        $mCPClearCacheProbeCommand->kernelBuildDirectory = $buildDirectoryPath;
        $mCPClearCacheProbeCommand->scriptedChildOutputLines = ['PHP Fatal error:  Allowed memory size exhausted'];
        $mCPClearCacheProbeCommand->scriptedChildExitCode = 255;

        $commandTester = new CommandTester($mCPClearCacheProbeCommand);
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();
        $displayLines = array_values(array_filter(explode(PHP_EOL, trim($display)), 'strlen'));

        self::assertNotSame(Command::SUCCESS, $exitCode);
        self::assertStringStartsWith('Layer 2 FAILED: cache:clear ended with exit code 255', $displayLines[count($displayLines) - 2]);
        self::assertStringStartsWith('Layer 1 completed: the MCP doc caches are cleared.', $displayLines[count($displayLines) - 1]);
        self::assertContains("removed the temporary build directory the failed child left: $warmupDirectoryPath", $displayLines);
        self::assertStringContainsString('Allowed memory size exhausted', $display, 'the output of the child comes through');
        self::assertStringNotContainsString('Now RESTART', $display);
        self::assertDirectoryDoesNotExist($warmupDirectoryPath);
        self::assertFileExists($buildDirectoryPath . '/file.txt', 'the real cache directory is never touched');
    }

    public function testAWarmupDirectoryThatCannotBeRemovedIsNamedInTheFailureOutput(): void
    {
        $buildDirectoryPath = $this->createTemporaryDirectory() . '/cache/prod';
        $warmupDirectoryPath = dirname($buildDirectoryPath) . '/pro_';
        $this->createDirectoryWithFile($warmupDirectoryPath);
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([], []);
        $mCPClearCacheProbeCommand->kernelBuildDirectory = $buildDirectoryPath;
        $mCPClearCacheProbeCommand->scriptedChildExitCode = 255;
        $mCPClearCacheProbeCommand->warmupDirectoryRemovable = false;

        $commandTester = new CommandTester($mCPClearCacheProbeCommand);
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString("Could not remove the temporary build directory the failed child left: $warmupDirectoryPath", $display);
        self::assertStringContainsString('Layer 2 FAILED: cache:clear ended with exit code 255', $display);
        self::assertDirectoryExists($warmupDirectoryPath);
    }

    public function testASuccessfulChildRemovesNothingAndEndsWithTheRestartNotice(): void
    {
        $buildDirectoryPath = $this->createTemporaryDirectory() . '/cache/test';
        $warmupDirectoryPath = dirname($buildDirectoryPath) . '/tes_';
        $this->createDirectoryWithFile($buildDirectoryPath);
        $this->createDirectoryWithFile($warmupDirectoryPath);
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([], []);
        $mCPClearCacheProbeCommand->kernelBuildDirectory = $buildDirectoryPath;

        $commandTester = new CommandTester($mCPClearCacheProbeCommand);
        $exitCode = $commandTester->execute([]);
        $display = trim($commandTester->getDisplay());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Now RESTART the agent workers', substr($display, (int)strrpos($display, PHP_EOL)));
        self::assertStringNotContainsString('Layer 2 FAILED', $display);
        self::assertFileExists($warmupDirectoryPath . '/file.txt');
        self::assertFileExists($buildDirectoryPath . '/file.txt');
    }

    public function testOnlyPackageCachesClearsLayerOneAndStartsNoChild(): void
    {
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([ProbeDocumentsMCPBundle::class], [ProbeArchiveMCPPackage::class]);

        $commandTester = new CommandTester($mCPClearCacheProbeCommand);
        $exitCode = $commandTester->execute(['--' . MCPClearCacheCommand::OPTION_ONLY_PACKAGE_CACHES => true]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, ProbeDocumentsMCPBundle::$documentationCacheClearCount);
        self::assertSame([ProbeArchiveMCPPackage::class], $mCPClearCacheProbeCommand->clearedPackageClassNames);
        self::assertSame([], $mCPClearCacheProbeCommand->startedChildArgumentLists);
        self::assertStringContainsString('Layer 1 — MCP doc cache cleared: 1 bundles + 1 skill packages.', $display);
        self::assertStringContainsString('the compiled container was NOT rebuilt', $display);
        self::assertStringContainsString("the application's own way of resetting its caches, then restart the workers", $display);
        self::assertStringNotContainsString('Layer 2 — rebuilding', $display);
    }

    public function testTheWarmupDirectoryIsNamedLikeTheContainerCacheClearNamesIt(): void
    {
        $mCPClearCacheProbeCommand = new MCPClearCacheProbeCommand([], []);

        $mCPClearCacheProbeCommand->kernelBuildDirectory = '/srv/project/var/cache/prod';
        self::assertSame('/srv/project/var/cache/pro_', self::invokeMethod($mCPClearCacheProbeCommand, 'getContainerCacheWarmupDirectoryPath'));
        $mCPClearCacheProbeCommand->kernelBuildDirectory = '/srv/project/var/cache/prod_';
        self::assertSame('/srv/project/var/cache/prod-', self::invokeMethod($mCPClearCacheProbeCommand, 'getContainerCacheWarmupDirectoryPath'));
    }

    public function testThePackageCacheIsClearedThroughThePackageClass(): void
    {
        $mCPClearCacheCommand = new MCPClearCacheCommand();

        self::invokeMethod($mCPClearCacheCommand, 'clearPackageDocumentationCache', [ProbeDocumentsMCPPackage::class]);

        self::assertSame(1, ProbeDocumentsMCPPackage::$documentationCacheClearCount);
    }

    public function testTheModulesDefaultsAreTheSourceRootBundlesAndTheSkillBoundPackages(): void
    {
        $mCPClearCacheCommand = new MCPClearCacheCommand();

        self::assertSame([], self::invokeMethod($mCPClearCacheCommand, 'getBundleClasses'), 'the module ships no bundle');
        $expectedPackageClassNames = [];
        foreach (AISkill::getPackageClassesBySkillName() as $packageClassNamesOfSkill) {
            foreach ($packageClassNamesOfSkill as $packageClassName) {
                $expectedPackageClassNames[$packageClassName] = true;
            }
        }
        self::assertSame(array_keys($expectedPackageClassNames), self::invokeMethod($mCPClearCacheCommand, 'findPackageClassNamesBoundToSkills'));
    }

    /**
     * A new empty folder in the system temp folder, removed after the test.
     *
     * @return string
     */
    protected function createTemporaryDirectory(): string
    {
        $this->temporaryDirectoryPath = sys_get_temp_dir() . '/agents-test-mcp-clear-cache-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectoryPath, 0777, true);
        return $this->temporaryDirectoryPath;
    }

    /**
     * @param string $directoryPath
     * @return void
     */
    protected function createDirectoryWithFile(string $directoryPath): void
    {
        mkdir($directoryPath, 0777, true);
        file_put_contents($directoryPath . '/file.txt', 'cached');
    }
}
