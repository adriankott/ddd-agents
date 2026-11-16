<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Eval\ToolCallComparisonsService;
use DDD\Domain\AI\Services\Skills\MCPClassCatalogService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\MCPCompareToolCallsCommand;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Addon\Presentation\Mcp\Notes\ComparisonArchiveMCPPackage;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Addon\Presentation\Mcp\Notes\ComparisonNotesMCPPackage as ModuleComparisonNotesMCPPackage;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Presentation\Mcp\Notes\ComparisonNotesMCPBundle;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Presentation\Mcp\Notes\ComparisonNotesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:mcp:compare-tool-calls` up to the point where it touches the database: its name and options, the option checks,
 * script and package resolution through the class catalogue (an application class wins over a module class of the same
 * short name), bundle discovery, script validation and the file writes.
 */
class MCPCompareToolCallsCommandTest extends UnitTestCase
{
    protected function getToolCallComparisonsService(): ToolCallComparisonsService
    {
        /** @var ToolCallComparisonsService $toolCallComparisonsService */
        $toolCallComparisonsService = DDDService::instance()->getService(ToolCallComparisonsService::class);
        return $toolCallComparisonsService;
    }

    public function testTheCommandKeepsItsNameAndOptionsAndRunsOnTheBase(): void
    {
        $asCommandAttributes = (new ReflectionClass(MCPCompareToolCallsCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:mcp:compare-tool-calls', $asCommandAttributes[0]->newInstance()->name);

        $mCPCompareToolCallsCommand = new MCPCompareToolCallsProbeCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $mCPCompareToolCallsCommand);
        foreach (['script', 'record', 'replay-and-compare', AgentConsoleCommand::OPTION_IGNORE_BUDGET] as $optionName) {
            self::assertTrue($mCPCompareToolCallsCommand->getDefinition()->hasOption($optionName), $optionName);
        }
    }

    /**
     * Both roots hold a package of the short name `ComparisonNotesMCPPackage`; the module's class name sorts first, so
     * a lookup by short name over the sorted catalogue finds the module's. The command's lookup reads the roots in their
     * order and finds the application's.
     */
    public function testAnApplicationClassWinsOverAModuleClassOfTheSameShortName(): void
    {
        $agentSourceRoots = MCPCompareToolCallsProbeCommand::buildFixtureSourceRoots();
        /** @var MCPClassCatalogService $mCPClassCatalogService */
        $mCPClassCatalogService = DDDService::instance()->getService(MCPClassCatalogService::class);
        self::assertSame(ModuleComparisonNotesMCPPackage::class, $mCPClassCatalogService->findMCPClassCatalog($agentSourceRoots)->findPackageClassNameByShortName('ComparisonNotesMCPPackage'), 'the sorted catalogue alone would pick the module');

        $packageClassesByShortName = $this->getToolCallComparisonsService()->findPackageClassesByShortName($agentSourceRoots);

        self::assertSame(ComparisonNotesMCPPackage::class, $packageClassesByShortName['ComparisonNotesMCPPackage']);
        self::assertSame(ComparisonArchiveMCPPackage::class, $packageClassesByShortName['ComparisonArchiveMCPPackage'], 'a package only the module has is found in its root');
        self::assertSame([ComparisonNotesMCPBundle::class], $this->getToolCallComparisonsService()->findBundleClasses($agentSourceRoots));
    }

    public function testAPackageResolvesByShortNameOrClassNameAndAnUnknownNameIsRefused(): void
    {
        $packageClassesByShortName = $this->getToolCallComparisonsService()->findPackageClassesByShortName(MCPCompareToolCallsProbeCommand::buildFixtureSourceRoots());

        self::assertSame(ComparisonNotesMCPPackage::class, $this->getToolCallComparisonsService()->resolvePackageClass('ComparisonNotesMCPPackage', $packageClassesByShortName));
        self::assertSame(ComparisonNotesMCPPackage::class, $this->getToolCallComparisonsService()->resolvePackageClass(ComparisonNotesMCPPackage::class, $packageClassesByShortName));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"NoSuchMCPPackage" is no MCP package under Presentation/Mcp of the application or a module');
        $this->getToolCallComparisonsService()->resolvePackageClass('NoSuchMCPPackage', $packageClassesByShortName);
    }

    public function testAPackageNameResolvesToItsCommittedScript(): void
    {
        $scriptPath = self::invokeMethod(new MCPCompareToolCallsProbeCommand(), 'resolveScriptPath', ['ComparisonNotesMCPPackage']);

        self::assertSame(MCPCompareToolCallsProbeCommand::getFixtureDirectory() . '/Scripts/ComparisonNotesMCPPackage.json', $scriptPath);
    }

    public function testTheCommandRefusesAMissingModeBothModesAnUnknownScriptAndAnInvalidScript(): void
    {
        $commandTester = new CommandTester(new MCPCompareToolCallsProbeCommand());

        self::assertSame(Command::INVALID, $commandTester->execute(['--script' => 'ComparisonNotesMCPPackage']));
        self::assertStringContainsString('Pass --script and exactly one of --record / --replay-and-compare.', $commandTester->getDisplay());
        self::assertSame(Command::INVALID, $commandTester->execute(['--script' => 'ComparisonNotesMCPPackage', '--record' => 'a.json', '--replay-and-compare' => 'b.json']));
        self::assertSame(Command::INVALID, $commandTester->execute(['--script' => 'NoSuchScript', '--record' => 'a.json']));
        self::assertStringContainsString('no comparison script NoSuchScript', $commandTester->getDisplay());
        self::assertSame(Command::INVALID, $commandTester->execute(['--script' => 'UnknownToolMCPPackage', '--record' => 'a.json']));
        self::assertStringContainsString('UnknownToolMCPPackage.json: unknown: tool "noSuchTool" is no tool of the script\'s packages', $commandTester->getDisplay());
    }

    public function testAReplayOfAnUnreadableRecordingIsRefusedBeforeTheRunStarts(): void
    {
        $commandTester = new CommandTester(new MCPCompareToolCallsProbeCommand());

        self::assertSame(Command::INVALID, $commandTester->execute(['--script' => 'ComparisonNotesMCPPackage', '--replay-and-compare' => '/nonexistent/recording.json']));
        self::assertStringContainsString('/nonexistent/recording.json cannot be read', $commandTester->getDisplay());
    }

    public function testAFailedWriteIsReportedNeverSilentlyRecorded(): void
    {
        $mCPCompareToolCallsCommand = new MCPCompareToolCallsProbeCommand();
        $scratchDirectory = sys_get_temp_dir() . '/tool-call-comparison-write-' . bin2hex(random_bytes(4));
        mkdir($scratchDirectory);
        try {
            $blockingFile = $scratchDirectory . '/a-file';
            file_put_contents($blockingFile, 'x');
            $directoryFailure = self::invokeMethod($mCPCompareToolCallsCommand, 'writeFile', [$blockingFile . '/sub/recording.json', '{}']);
            self::assertStringContainsString('cannot be created', (string)$directoryFailure);

            $readOnlyDirectory = $scratchDirectory . '/read-only';
            mkdir($readOnlyDirectory, 0555);
            $fileFailure = self::invokeMethod($mCPCompareToolCallsCommand, 'writeFile', [$readOnlyDirectory . '/recording.json', '{}']);
            self::assertStringContainsString('cannot be written', (string)$fileFailure);

            self::assertNull(self::invokeMethod($mCPCompareToolCallsCommand, 'writeFile', [$scratchDirectory . '/new/recording.json', '{"a":1}']));
            self::assertSame('{"a":1}', file_get_contents($scratchDirectory . '/new/recording.json'));
        } finally {
            @chmod($scratchDirectory . '/read-only', 0755);
            @unlink($scratchDirectory . '/a-file');
            @unlink($scratchDirectory . '/new/recording.json');
            @rmdir($scratchDirectory . '/new');
            @rmdir($scratchDirectory . '/read-only');
            @rmdir($scratchDirectory);
        }
    }

    public function testARelativePathResolvesAgainstTheBaseDirectoryAndAnAbsoluteOneStays(): void
    {
        $mCPCompareToolCallsCommand = new MCPCompareToolCallsProbeCommand();

        self::assertSame('/abs/recording.json', self::invokeMethod($mCPCompareToolCallsCommand, 'resolvePath', ['/abs/recording.json']));
        self::assertSame(DDDService::instance()->getRootDir() . '/var/recording.json', self::invokeMethod($mCPCompareToolCallsCommand, 'resolvePath', ['var/recording.json']));
    }
}
