<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\MCPPackages\MCPBundle;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\MCPClassCatalogService;
use DDD\Infrastructure\Services\DDDService;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/**
 * One command to clear every cache the agent reads after a change of an MCP tool or a skill — a tool, its request or
 * response DTO or a rendered value object, an `#[MCPToolAlwaysLoad]` flag, a skill's package set, a skill body. It
 * clears the two layers that go stale:
 *
 *  1. The MCP documentation cache: the reflection-built tool documentation and JSON schemas the documentation route
 *     and `app:mcp:documentation --cache` read (keys `mcp.doc.bundle.*` and `mcp.doc.package.*`, one day). The key of
 *     a bundle encodes only its package composition ({@see MCPBundle::buildDocumentationCacheKey()}), the key of a
 *     package only its class — neither a tool signature, a DTO shape, an always-load flag nor a skill's package set —
 *     so the cache stays stale on every such change until it is cleared. Cleared: every bundle of
 *     {@see getBundleClasses()} and every package bound to a skill ({@see AISkill::getPackageClassesBySkillName()}),
 *     which covers the packages a skill loads without a bundle; a skill entry that names no MCP package class (a class
 *     removed or renamed since) is skipped with a line saying so.
 *  2. The compiled container (the command {@see getContainerCacheClearCommandName()}): a new tool method, MCP package,
 *     service or wiring and the skill → package configuration live there, so the next process boots with them. It runs
 *     in a CHILD process ({@see buildContainerCacheClearChildArguments()}): the same PHP binary and console script as
 *     this command, the environment and debug mode of the running kernel, the environment variables this process
 *     started with and those of `$_ENV` (what a dotenv loader set). This process already holds the reflection of
 *     every bundle and package of layer 1; a container rebuild on top of that can exhaust the memory limit the
 *     console script sets. The child's output is passed through as it arrives.
 *
 * A child that ends non-zero ends this command non-zero: the last lines name layer 2, the child's exit code and that
 * layer 1 completed, so only the container rebuild has to run again. The temporary warm-up directory the failed child
 * left beside the cache directory ({@see getContainerCacheWarmupDirectoryPath()}) is removed; when it cannot be, its
 * path is printed. The real cache directory is never touched, and nothing is removed after a successful child.
 *
 * `--onlyPackageCaches` clears layer 1 alone and starts no child. An application that serves web requests from the
 * same cache directory rebuilds its container through its own cache reset and must not run `cache:clear` under a
 * serving web process; the output says the container was not rebuilt and what rebuilds it
 * ({@see getContainerRebuildSkippedNotice()}).
 *
 * A running worker still needs a restart: no command reloads a live worker. A skill body is read from disk and kept
 * only in the process-static configuration tree, so a new process picks every change up at once; a long-running
 * consumer keeps its compiled container and its skill bodies until it restarts ({@see getWorkerRestartNotice()}).
 */
#[AsCommand(
    name: 'app:mcp:clear-cache',
    description: 'Clear every agent cache after an MCP tool or skill change: the MCP documentation cache (tools) and the compiled container (cache:clear in a child process — new tool classes and wiring, the skill → package configuration; skipped with --onlyPackageCaches). Then restart the agent workers.',
)]
class MCPClearCacheCommand extends AgentConsoleCommand
{
    /** @var string The option that clears the MCP documentation caches alone and leaves the container to the application */
    public const string OPTION_ONLY_PACKAGE_CACHES = 'onlyPackageCaches';

    /**
     * @var int|null The exit code of the last container cache clear {@see rebuildCompiledContainer()} ran; null = it
     * ran none (an application's override that does not set it)
     */
    protected ?int $containerCacheClearExitCode = null;

    /**
     * @return void
     */
    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            self::OPTION_ONLY_PACKAGE_CACHES,
            null,
            InputOption::VALUE_NONE,
            'Clear the MCP documentation caches only; the compiled container is NOT rebuilt (the application resets its own caches, then restarts the workers)'
        );
    }

    /**
     * Clears the documentation caches, rebuilds the compiled container in a child process (unless
     * `--onlyPackageCaches`) and names the restart that is still needed — or, when the child failed, that layer 2
     * failed and layer 1 completed.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int `Command::SUCCESS`, or `Command::FAILURE` when the container cache clear ended non-zero
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $bundleClassNames = $this->getBundleClasses();
        foreach ($bundleClassNames as $bundleClassName) {
            $this->clearBundleDocumentationCache($bundleClassName);
            $output->writeln("cleared bundle doc cache: $bundleClassName");
        }
        $clearedPackageCount = 0;
        foreach ($this->findPackageClassNamesBoundToSkills() as $packageClassName) {
            // a skill entry may still name a class that was removed or renamed: it has no cache this command can
            // reach through the class, and loading it must not end the run before the container is rebuilt
            if (!$this->isMCPPackageClass($packageClassName)) {
                $output->writeln("<comment>skipped skill package entry: $packageClassName (no MCP package class of that name)</comment>");
                continue;
            }
            $this->clearPackageDocumentationCache($packageClassName);
            $clearedPackageCount++;
            $output->writeln("cleared package doc cache: $packageClassName");
        }
        $output->writeln(sprintf(
            '<info>Layer 1 — MCP doc cache cleared: %d bundles + %d skill packages.</info>',
            count($bundleClassNames),
            $clearedPackageCount,
        ));

        $output->writeln('');
        if ((bool)$input->getOption(self::OPTION_ONLY_PACKAGE_CACHES)) {
            $output->writeln('<comment>' . $this->getContainerRebuildSkippedNotice() . '</comment>');
            $output->writeln('');
            $output->writeln('<comment>' . $this->getWorkerRestartNotice() . '</comment>');
            return Command::SUCCESS;
        }
        $output->writeln('Layer 2 — rebuilding the compiled container (' . $this->getContainerCacheClearCommandName() . ') …');
        $containerCacheClearExitCode = $this->rebuildCompiledContainerAndFindExitCode($output);
        if ($containerCacheClearExitCode !== Command::SUCCESS) {
            $this->reportFailedContainerRebuild($containerCacheClearExitCode, $output);
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<comment>' . $this->getWorkerRestartNotice() . '</comment>');
        return Command::SUCCESS;
    }

    /**
     * The MCP bundles whose documentation caches are cleared. The default: every concrete bundle below
     * `Presentation/Mcp` of the application and of every installed DDD module ({@see MCPClassCatalogService}) — a
     * bundle is code, and a bundle only reaches a route through the `BUNDLE` constant of a controller of the
     * application, so the classes in the source trees are what the module knows of. The module itself ships no bundle.
     *
     * @return array<int, class-string<MCPBundle>>
     */
    protected function getBundleClasses(): array
    {
        /** @var MCPClassCatalogService $mCPClassCatalogService */
        $mCPClassCatalogService = DDDService::instance()->getService(MCPClassCatalogService::class);
        /** @var array<int, class-string<MCPBundle>> $bundleClassNames */
        $bundleClassNames = $mCPClassCatalogService->findMCPClassCatalogOfAllSourceRoots()->bundleClassNames;
        return $bundleClassNames;
    }

    /**
     * The notice printed last: what the command cannot do.
     *
     * @return string
     */
    protected function getWorkerRestartNotice(): string
    {
        return '⚠ Now RESTART the agent workers (the consumers of the agent transports): a running worker keeps its old '
            . 'container AND its process-static skill bodies until restart — no cache clear hot-reloads a live worker.';
    }

    /**
     * The notice printed instead of layer 2 under `--onlyPackageCaches`: the container was not rebuilt, and what
     * rebuilds it.
     *
     * @return string
     */
    protected function getContainerRebuildSkippedNotice(): string
    {
        return 'Layer 2 skipped (--' . self::OPTION_ONLY_PACKAGE_CACHES . '): the compiled container was NOT rebuilt. '
            . 'Rebuild it with the application\'s own way of resetting its caches, then restart the workers.';
    }

    /**
     * The command that rebuilds the compiled container.
     *
     * @return string
     */
    protected function getContainerCacheClearCommandName(): string
    {
        return 'cache:clear';
    }

    /**
     * Every package class bound to a skill, each once, in the order of the binding.
     *
     * @return array<int, class-string<MCPPackage>>
     */
    protected function findPackageClassNamesBoundToSkills(): array
    {
        $packageClassNames = [];
        foreach (AISkill::getPackageClassesBySkillName() as $packageClassNamesOfSkill) {
            foreach ($packageClassNamesOfSkill as $packageClassName) {
                $packageClassNames[$packageClassName] = true;
            }
        }
        /** @var array<int, class-string<MCPPackage>> $uniquePackageClassNames */
        $uniquePackageClassNames = array_keys($packageClassNames);
        return $uniquePackageClassNames;
    }

    /**
     * Whether a skill's package entry names an existing subclass of {@see MCPPackage}.
     *
     * @param string $packageClassName
     * @return bool
     */
    protected function isMCPPackageClass(string $packageClassName): bool
    {
        return class_exists($packageClassName) && is_subclass_of($packageClassName, MCPPackage::class);
    }

    /**
     * @param class-string<MCPBundle> $bundleClassName
     * @return void
     */
    protected function clearBundleDocumentationCache(string $bundleClassName): void
    {
        $bundleClassName::clearDocumentationCache();
    }

    /**
     * @param class-string<MCPPackage> $packageClassName
     * @return void
     */
    protected function clearPackageDocumentationCache(string $packageClassName): void
    {
        $packageClassName::clearDocumentationCache();
    }

    /**
     * Runs layer 2 through {@see rebuildCompiledContainer()} and answers the exit code it recorded. An application's
     * override of {@see rebuildCompiledContainer()} that records none (it rebuilds the container its own way) counts
     * as success, as before.
     *
     * @param OutputInterface $output
     * @return int the exit code of the container cache clear (`Command::SUCCESS` = rebuilt)
     */
    protected function rebuildCompiledContainerAndFindExitCode(OutputInterface $output): int
    {
        $this->containerCacheClearExitCode = null;
        $this->rebuildCompiledContainer($output);
        return $this->containerCacheClearExitCode ?? Command::SUCCESS;
    }

    /**
     * Runs the container cache clear in a child process ({@see startContainerCacheClearChildProcess()}) and records its
     * exit code in {@see $containerCacheClearExitCode}, which {@see rebuildCompiledContainerAndFindExitCode()} reads.
     * An override that rebuilds the container another way records the exit code there too, or leaves it null for
     * success.
     *
     * @param OutputInterface $output
     * @return void
     */
    protected function rebuildCompiledContainer(OutputInterface $output): void
    {
        $this->containerCacheClearExitCode = $this->startContainerCacheClearChildProcess($this->buildContainerCacheClearChildArguments(), $output);
    }

    /**
     * The argument list of the child process (no shell parses it): the PHP binary, the console script, the command
     * {@see getContainerCacheClearCommandName()}, `--env=` of the running kernel and `--no-debug` when the running
     * kernel is not in debug mode.
     *
     * @return string[]
     */
    protected function buildContainerCacheClearChildArguments(): array
    {
        $childArguments = [
            $this->getPhpBinaryPath(),
            $this->getConsoleScriptPath(),
            $this->getContainerCacheClearCommandName(),
            '--env=' . $this->getRunningKernelEnvironment(),
        ];
        if (!$this->isRunningKernelInDebugMode()) {
            $childArguments[] = '--no-debug';
        }
        return $childArguments;
    }

    /**
     * The PHP binary of the child: the binary running this process.
     *
     * @return string
     */
    protected function getPhpBinaryPath(): string
    {
        return PHP_BINARY;
    }

    /**
     * The console script of the child: the script this process was started with (`$_SERVER['SCRIPT_FILENAME']`, else
     * the first command-line argument), made absolute against the working directory when it is relative.
     *
     * @return string
     */
    protected function getConsoleScriptPath(): string
    {
        $consoleScriptPath = (string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['argv'][0] ?? '');
        $absoluteConsoleScriptPath = $consoleScriptPath !== '' ? realpath($consoleScriptPath) : false;
        return $absoluteConsoleScriptPath !== false ? $absoluteConsoleScriptPath : $consoleScriptPath;
    }

    /**
     * The environment of the running kernel, handed to the child as `--env=`.
     *
     * @return string
     */
    protected function getRunningKernelEnvironment(): string
    {
        return DDDService::instance()->getKernel()->getEnvironment();
    }

    /**
     * Whether the running kernel is in debug mode; the child gets `--no-debug` when it is not.
     *
     * @return bool
     */
    protected function isRunningKernelInDebugMode(): bool
    {
        return DDDService::instance()->getKernel()->isDebug();
    }

    /**
     * The build directory of the running kernel (the `kernel.build_dir` the container cache clear reads; the cache
     * directory unless the kernel separates them).
     *
     * @return string
     */
    protected function getRunningKernelBuildDirectory(): string
    {
        return DDDService::instance()->getKernel()->getBuildDir();
    }

    /**
     * Starts the child with `$childArguments` (an argument array, no shell) through the process component the
     * package's other child processes use, with the working directory of this process and the environment variables it
     * started with plus those of `$_ENV` (a variable set later with `putenv()` alone is not handed on), and passes
     * its standard output to `$output` and its error output to the error output of `$output` (to `$output` when it has
     * none) as they arrive. Waits for the child to end, however long the rebuild takes (no timeout).
     *
     * @param string[] $childArguments
     * @param OutputInterface $output
     * @return int the exit code of the child; `Command::FAILURE` when it could not be started or run
     */
    protected function startContainerCacheClearChildProcess(array $childArguments, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $childProcess = new Process($childArguments);
        // a container rebuild may take longer than the component's default of 60 seconds
        $childProcess->setTimeout(null);
        try {
            $childProcess->start();
            // the process yields each chunk of output as it arrives, until the child has ended
            foreach ($childProcess as $outputType => $outputChunk) {
                ($outputType === Process::ERR ? $errorOutput : $output)->write($outputChunk, false, OutputInterface::OUTPUT_RAW);
            }
            return $childProcess->wait();
        } catch (ProcessExceptionInterface $processException) {
            $errorOutput->writeln('<error>Could not run the child process ' . $childProcess->getCommandLine() . ': ' . $processException->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * Prints the end of a run whose container cache clear failed: the leftover warm-up directory removed (or its path
     * when it cannot be), then that layer 2 failed with the child's exit code, then that layer 1 completed.
     *
     * @param int $containerCacheClearExitCode
     * @param OutputInterface $output
     * @return void
     */
    protected function reportFailedContainerRebuild(int $containerCacheClearExitCode, OutputInterface $output): void
    {
        $output->writeln('');
        $warmupDirectoryPath = $this->getContainerCacheWarmupDirectoryPath();
        if (is_dir($warmupDirectoryPath) || is_link($warmupDirectoryPath)) {
            if ($this->removeContainerCacheWarmupDirectory($warmupDirectoryPath)) {
                $output->writeln("removed the temporary build directory the failed child left: $warmupDirectoryPath");
            } else {
                $output->writeln("<error>Could not remove the temporary build directory the failed child left: $warmupDirectoryPath — remove it before the next "
                    . $this->getContainerCacheClearCommandName() . '.</error>');
            }
        }
        $output->writeln(sprintf(
            '<error>Layer 2 FAILED: %s ended with exit code %d — the compiled container was NOT rebuilt.</error>',
            $this->getContainerCacheClearCommandName(),
            $containerCacheClearExitCode,
        ));
        $output->writeln('<comment>Layer 1 completed: the MCP doc caches are cleared. Run only '
            . $this->getContainerCacheClearCommandName() . ' again, not this whole command.</comment>');
    }

    /**
     * The temporary directory the container cache clear warms the new cache up in before it renames it to the build
     * directory; a child that dies before the rename leaves it behind. The name mirrors Symfony's
     * `CacheClearCommand::execute()` (FrameworkBundle):
     * `$warmupDir = substr($realBuildDir, 0, -1).(str_ends_with($realBuildDir, '_') ? '-' : '_');` — the build
     * directory with its last character replaced by `_` (by `-` when it already ends with `_`). It never names the
     * build directory itself.
     *
     * @return string
     */
    protected function getContainerCacheWarmupDirectoryPath(): string
    {
        $realBuildDirectory = $this->getRunningKernelBuildDirectory();
        return substr($realBuildDirectory, 0, -1) . (str_ends_with($realBuildDirectory, '_') ? '-' : '_');
    }

    /**
     * Removes the warm-up directory a failed child left, with everything in it (a symbolic link inside is removed, never
     * followed).
     *
     * @param string $warmupDirectoryPath
     * @return bool whether nothing is left at the path
     */
    protected function removeContainerCacheWarmupDirectory(string $warmupDirectoryPath): bool
    {
        if (is_link($warmupDirectoryPath)) {
            return @unlink($warmupDirectoryPath);
        }
        try {
            $directoryEntries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($warmupDirectoryPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            /** @var SplFileInfo $directoryEntry */
            foreach ($directoryEntries as $directoryEntry) {
                if ($directoryEntry->isDir() && !$directoryEntry->isLink()) {
                    @rmdir($directoryEntry->getPathname());
                } else {
                    @unlink($directoryEntry->getPathname());
                }
            }
        } catch (UnexpectedValueException) {
            // a directory inside that cannot be read stays; the check below reports the path
        }
        @rmdir($warmupDirectoryPath);
        return !file_exists($warmupDirectoryPath) && !is_link($warmupDirectoryPath);
    }
}
