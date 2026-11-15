<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\MCPClearCacheCommand;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Archive\ProbeArchiveMCPPackage;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents\ProbeDocumentsMCPBundle;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents\ProbeDocumentsMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The clear-cache command clears the documentation cache of every bundle it names and of every package bound to a
 * skill, runs the container cache clear of its console application, and ends with the restart notice. The module's
 * default bundles are the concrete bundles of the source roots (none in the module itself), its packages those of the
 * skill binding. A skill entry that names no MCP package class is skipped with a line, and without a console
 * application no container cache clear runs.
 */
class MCPClearCacheCommandTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProbeDocumentsMCPBundle::$documentationCacheClearCount = 0;
        ProbeDocumentsMCPPackage::$documentationCacheClearCount = 0;
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
        $containerCacheClearRecordingCommand = new ContainerCacheClearRecordingCommand();
        $application = new Application();
        $application->addCommands([$mCPClearCacheProbeCommand, $containerCacheClearRecordingCommand]);

        $commandTester = new CommandTester($application->find(MCPClearCacheProbeCommand::PROBE_COMMAND_NAME));
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, ProbeDocumentsMCPBundle::$documentationCacheClearCount);
        self::assertSame([ProbeDocumentsMCPPackage::class, ProbeArchiveMCPPackage::class], $mCPClearCacheProbeCommand->clearedPackageClassNames);
        self::assertSame(1, $containerCacheClearRecordingCommand->runCount);
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
        $containerCacheClearRecordingCommand = new ContainerCacheClearRecordingCommand();
        $application = new Application();
        $application->addCommands([$mCPClearCacheProbeCommand, $containerCacheClearRecordingCommand]);

        $commandTester = new CommandTester($application->find(MCPClearCacheProbeCommand::PROBE_COMMAND_NAME));
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([ProbeArchiveMCPPackage::class], $mCPClearCacheProbeCommand->clearedPackageClassNames);
        self::assertStringContainsString("skipped skill package entry: $missingPackageClassName (no MCP package class of that name)", $display);
        self::assertStringContainsString('skipped skill package entry: ' . ProbeDocumentsMCPBundle::class . ' (no MCP package class of that name)', $display, 'a bundle is no package');
        self::assertStringContainsString('Layer 1 — MCP doc cache cleared: 0 bundles + 1 skill packages.', $display);
        self::assertSame(1, $containerCacheClearRecordingCommand->runCount, 'the container is still rebuilt');
    }

    public function testWithoutAConsoleApplicationNoContainerCacheClearRuns(): void
    {
        $commandTester = new CommandTester(new MCPClearCacheProbeCommand([], []));
        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Layer 2 — rebuilding the compiled container (cache:clear) …', $display);
        self::assertStringNotContainsString('container cache cleared', $display);
        self::assertStringContainsString('Now RESTART the agent workers', $display);
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
}
