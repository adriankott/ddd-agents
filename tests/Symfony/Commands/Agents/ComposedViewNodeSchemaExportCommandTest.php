<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchemaFilePaths;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchemaParts;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\ComposedViewNodeSchemaExportCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The export command compares the module's schema file and never writes it, writes the application's part (or, with
 * `--check`, compares it), prints the paths relative to the display root and fails on every difference. Its default
 * compares the file the module ships and writes no application part. A folder of the application part that cannot be
 * created fails the run.
 */
class ComposedViewNodeSchemaExportCommandTest extends UnitTestCase
{
    protected string $temporaryDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . '/composed-view-schema-export-' . bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory . '/module', 0775, true);
    }

    protected function tearDown(): void
    {
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($fileIterator as $fileInfo) {
            $fileInfo->isDir() ? rmdir($fileInfo->getPathname()) : unlink($fileInfo->getPathname());
        }
        rmdir($this->temporaryDirectory);
        parent::tearDown();
    }

    public function testTheCommandKeepsItsNameOptionAndRunsOnTheBase(): void
    {
        $asCommandAttributes = (new ReflectionClass(ComposedViewNodeSchemaExportCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:agent:export-composed-view-node-schema', explode('|', $asCommandAttributes[0]->newInstance()->name)[0], 'the old name stays an alias');
        $composedViewNodeSchemaExportCommand = new ComposedViewNodeSchemaExportCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $composedViewNodeSchemaExportCommand);
        self::assertTrue($composedViewNodeSchemaExportCommand->getDefinition()->hasOption('check'));
    }

    public function testTheApplicationPartIsWrittenThenUnchangedAndTheModuleFileIsOnlyCompared(): void
    {
        $this->writeModuleSchemaFile(ComposedViewNodeSchemaParts::buildModuleSchemaJson());
        $commandTester = new CommandTester(new ComposedViewNodeSchemaExportProbeCommand($this->buildFilePaths()));

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertStringContainsString('module/composed-node-schema.json of the installed package matches the registered classes.', $this->findDisplayOnOneLine($commandTester));
        self::assertStringContainsString('application/part/composed-node-schema.json written (changed).', $this->findDisplayOnOneLine($commandTester));
        self::assertSame(ComposedViewNodeSchemaParts::buildApplicationSchemaPartJson(), file_get_contents($this->getApplicationPartFilePath()));

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertStringContainsString('application/part/composed-node-schema.json written (unchanged).', $this->findDisplayOnOneLine($commandTester));

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--check' => true]));
        self::assertStringContainsString('application/part/composed-node-schema.json is current.', $this->findDisplayOnOneLine($commandTester));
    }

    public function testAStaleApplicationPartFailsTheCheckAndIsNotWritten(): void
    {
        $this->writeModuleSchemaFile(ComposedViewNodeSchemaParts::buildModuleSchemaJson());
        mkdir(dirname($this->getApplicationPartFilePath()), 0775, true);
        file_put_contents($this->getApplicationPartFilePath(), "{}\n");
        $commandTester = new CommandTester(new ComposedViewNodeSchemaExportProbeCommand($this->buildFilePaths()));

        self::assertSame(Command::FAILURE, $commandTester->execute(['--check' => true]));
        self::assertStringContainsString('application/part/composed-node-schema.json is stale — run app:agent:export-composed-view-node-schema.', $this->findDisplayOnOneLine($commandTester));
        self::assertSame("{}\n", file_get_contents($this->getApplicationPartFilePath()));
    }

    public function testADifferingModuleFileFailsTheRunAndIsNeverWritten(): void
    {
        $this->writeModuleSchemaFile("{}\n");
        $commandTester = new CommandTester(new ComposedViewNodeSchemaExportProbeCommand($this->buildFilePaths()));

        self::assertSame(Command::FAILURE, $commandTester->execute([]));
        self::assertStringContainsString('of the installed package differs from the registered classes', $this->findDisplayOnOneLine($commandTester));
        self::assertSame("{}\n", file_get_contents($this->temporaryDirectory . '/module/composed-node-schema.json'));
        self::assertSame(ComposedViewNodeSchemaParts::buildApplicationSchemaPartJson(), file_get_contents($this->getApplicationPartFilePath()), 'the application part is still written');
    }

    public function testAFolderThatCannotBeCreatedFailsTheRunAndWritesNothing(): void
    {
        $this->writeModuleSchemaFile(ComposedViewNodeSchemaParts::buildModuleSchemaJson());
        // a file where the folder of the application part would go
        file_put_contents($this->temporaryDirectory . '/application', 'a file, not a folder');
        $commandTester = new CommandTester(new ComposedViewNodeSchemaExportProbeCommand($this->buildFilePaths()));

        // the warning of the refused mkdir is silenced: the command reports the failure itself
        self::assertSame(Command::FAILURE, @$commandTester->execute([]));
        // the console style breaks the long path at any character: compared without white space
        self::assertStringContainsString(
            'cannotcreate' . dirname($this->getApplicationPartFilePath()),
            (string)preg_replace('/\s+/', '', $commandTester->getDisplay())
        );
        self::assertFileDoesNotExist($this->getApplicationPartFilePath());
    }

    public function testTheDefaultComparesTheFileTheModuleShipsAndWritesNoApplicationPart(): void
    {
        $composedViewNodeSchemaFilePaths = self::invokeMethod(new ComposedViewNodeSchemaExportCommand(), 'getSchemaFilePaths');
        self::assertInstanceOf(ComposedViewNodeSchemaFilePaths::class, $composedViewNodeSchemaFilePaths);
        self::assertNull($composedViewNodeSchemaFilePaths->applicationSchemaPartFilePath);
        self::assertSame(dirname(__DIR__, 4) . '/' . ComposedViewNodeSchemaFilePaths::MODULE_SCHEMA_FILE_RELATIVE_PATH, $composedViewNodeSchemaFilePaths->moduleSchemaFilePath);

        $commandTester = new CommandTester(new ComposedViewNodeSchemaExportCommand());
        $exitCode = $commandTester->execute(['--check' => true]);

        self::assertStringContainsString(ComposedViewNodeSchemaFilePaths::MODULE_SCHEMA_FILE_RELATIVE_PATH . ' of the installed package matches the registered classes.', $this->findDisplayOnOneLine($commandTester));
        self::assertSame(Command::SUCCESS, $exitCode);
    }

    /** The display with the line breaks of the console style removed. */
    protected function findDisplayOnOneLine(CommandTester $commandTester): string
    {
        return (string)preg_replace('/\s+/', ' ', $commandTester->getDisplay());
    }

    protected function buildFilePaths(): ComposedViewNodeSchemaFilePaths
    {
        return ComposedViewNodeSchemaFilePaths::forPaths(
            $this->temporaryDirectory . '/module/composed-node-schema.json',
            $this->getApplicationPartFilePath(),
            $this->temporaryDirectory . '/'
        );
    }

    protected function getApplicationPartFilePath(): string
    {
        return $this->temporaryDirectory . '/application/part/composed-node-schema.json';
    }

    protected function writeModuleSchemaFile(string $schemaJson): void
    {
        file_put_contents($this->temporaryDirectory . '/module/composed-node-schema.json', $schemaJson);
    }
}
