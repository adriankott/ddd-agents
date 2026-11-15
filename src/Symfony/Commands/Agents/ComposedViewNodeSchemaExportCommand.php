<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchemaFilePaths;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchemaParts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the application's part of the `ComposedView` node schema from the registered classes into its committed file
 * (the types the application's classes declare), and compares the module's `composed-node-schema.json` (the types the
 * module's classes declare) with the file the installed module ships ({@see ComposedViewNodeSchemaParts}). The
 * module's file is never written: it belongs to the installed release, and a difference means the release's classes
 * and its own schema file disagree — report it to the module's maintainer. `--check` writes nothing and fails when
 * either file differs. Run after a node, widget, data source or entity reference class of the application was added
 * or changed. Where the files lie: {@see getSchemaFilePaths()}.
 *
 *   php bin/console app:ai:composed-view:export-node-schema
 *   php bin/console app:ai:composed-view:export-node-schema --check
 */
#[AsCommand(name: 'app:ai:composed-view:export-node-schema', description: 'Writes the application\'s part of the ComposedView node schema from the registered classes and compares the installed package\'s composed-node-schema.json; --check only compares.')]
class ComposedViewNodeSchemaExportCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Compare only: fail when a committed file differs from the registered classes');
    }

    /**
     * Compares the module's file, then writes or compares the application's part.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $composedViewNodeSchemaFilePaths = $this->getSchemaFilePaths();
        $exitCode = Command::SUCCESS;
        if ($composedViewNodeSchemaFilePaths->moduleSchemaFilePath !== null
            && !$this->compareModuleSchemaFile($composedViewNodeSchemaFilePaths, $symfonyStyle)) {
            $exitCode = Command::FAILURE;
        }
        if ($composedViewNodeSchemaFilePaths->applicationSchemaPartFilePath === null) {
            return $exitCode;
        }
        $applicationPartExitCode = (bool)$input->getOption('check')
            ? $this->checkApplicationSchemaPartFile($composedViewNodeSchemaFilePaths, $symfonyStyle)
            : $this->writeApplicationSchemaPartFile($composedViewNodeSchemaFilePaths, $symfonyStyle);
        return $applicationPartExitCode === Command::SUCCESS ? $exitCode : $applicationPartExitCode;
    }

    /**
     * Where the two schema files lie. The default: the module's file in the installed module
     * ({@see ComposedViewNodeSchemaFilePaths::MODULE_SCHEMA_FILE_RELATIVE_PATH} below {@see getModuleRootDirectory()}),
     * no application part (the module cannot know where an application keeps its frontend), paths printed relative to
     * the module's root directory.
     *
     * @return ComposedViewNodeSchemaFilePaths
     */
    protected function getSchemaFilePaths(): ComposedViewNodeSchemaFilePaths
    {
        return ComposedViewNodeSchemaFilePaths::forPaths(
            $this->getModuleRootDirectory() . '/' . ComposedViewNodeSchemaFilePaths::MODULE_SCHEMA_FILE_RELATIVE_PATH,
            null,
            $this->getModuleRootDirectory()
        );
    }

    /**
     * The root directory of this module (the directory that holds its `src/` and `web/`).
     *
     * @return string
     */
    protected function getModuleRootDirectory(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Compares the module's file with the module's schema built from the registered classes.
     *
     * @param ComposedViewNodeSchemaFilePaths $composedViewNodeSchemaFilePaths
     * @param SymfonyStyle $symfonyStyle
     * @return bool whether they are equal
     */
    protected function compareModuleSchemaFile(ComposedViewNodeSchemaFilePaths $composedViewNodeSchemaFilePaths, SymfonyStyle $symfonyStyle): bool
    {
        $moduleSchemaFilePath = (string)$composedViewNodeSchemaFilePaths->moduleSchemaFilePath;
        $displayPath = $composedViewNodeSchemaFilePaths->getDisplayPath($moduleSchemaFilePath);
        if ($this->readCommittedSchemaJson($moduleSchemaFilePath) === ComposedViewNodeSchemaParts::buildModuleSchemaJson()) {
            $symfonyStyle->success($displayPath . ' of the installed package matches the registered classes.');
            return true;
        }
        $symfonyStyle->error($displayPath . ' of the installed package differs from the registered classes — the file belongs to the package and is not written here; report it to the package maintainer.');
        return false;
    }

    /**
     * `--check`: compares the application's part with the part built from the registered classes.
     *
     * @param ComposedViewNodeSchemaFilePaths $composedViewNodeSchemaFilePaths
     * @param SymfonyStyle $symfonyStyle
     * @return int
     */
    protected function checkApplicationSchemaPartFile(ComposedViewNodeSchemaFilePaths $composedViewNodeSchemaFilePaths, SymfonyStyle $symfonyStyle): int
    {
        $applicationSchemaPartFilePath = (string)$composedViewNodeSchemaFilePaths->applicationSchemaPartFilePath;
        $displayPath = $composedViewNodeSchemaFilePaths->getDisplayPath($applicationSchemaPartFilePath);
        if ($this->readCommittedSchemaJson($applicationSchemaPartFilePath) === ComposedViewNodeSchemaParts::buildApplicationSchemaPartJson()) {
            $symfonyStyle->success($displayPath . ' is current.');
            return Command::SUCCESS;
        }
        $symfonyStyle->error($displayPath . ' is stale — run ' . $this->getName() . '.');
        return Command::FAILURE;
    }

    /**
     * Writes the application's part built from the registered classes, creating its folder when needed.
     *
     * @param ComposedViewNodeSchemaFilePaths $composedViewNodeSchemaFilePaths
     * @param SymfonyStyle $symfonyStyle
     * @return int FAILURE when the folder cannot be created
     */
    protected function writeApplicationSchemaPartFile(ComposedViewNodeSchemaFilePaths $composedViewNodeSchemaFilePaths, SymfonyStyle $symfonyStyle): int
    {
        $applicationSchemaPartFilePath = (string)$composedViewNodeSchemaFilePaths->applicationSchemaPartFilePath;
        $applicationSchemaPartDirectory = dirname($applicationSchemaPartFilePath);
        if (!is_dir($applicationSchemaPartDirectory) && !mkdir($applicationSchemaPartDirectory, 0775, true) && !is_dir($applicationSchemaPartDirectory)) {
            $symfonyStyle->error('cannot create ' . $applicationSchemaPartDirectory);
            return Command::FAILURE;
        }
        $applicationSchemaPartJson = ComposedViewNodeSchemaParts::buildApplicationSchemaPartJson();
        $committedSchemaJson = $this->readCommittedSchemaJson($applicationSchemaPartFilePath);
        file_put_contents($applicationSchemaPartFilePath, $applicationSchemaPartJson);
        $symfonyStyle->success(sprintf(
            '%s written (%s).',
            $composedViewNodeSchemaFilePaths->getDisplayPath($applicationSchemaPartFilePath),
            $committedSchemaJson === $applicationSchemaPartJson ? 'unchanged' : 'changed'
        ));
        return Command::SUCCESS;
    }

    /**
     * The content of a committed schema file; null when there is none.
     *
     * @param string $schemaFilePath
     * @return string|null
     */
    protected function readCommittedSchemaJson(string $schemaFilePath): ?string
    {
        return is_file($schemaFilePath) ? (string)file_get_contents($schemaFilePath) : null;
    }
}
