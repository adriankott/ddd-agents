<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\MCPClearCacheCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The clear-cache command with the bundles and the skill-bound packages a test names, recording the package caches it
 * clears instead of reaching the cache. The container cache clear child is recorded instead of started (its argument
 * list kept, the scripted lines written, the scripted exit code answered) unless a test asks for a real child; the
 * kernel's environment, debug mode and build directory and the outcome of removing the warm-up directory are the ones
 * a test sets.
 */
class MCPClearCacheProbeCommand extends MCPClearCacheCommand
{
    public const string PROBE_COMMAND_NAME = 'agents-test:mcp-clear-cache-probe';

    /** @var string[] The packages whose caches were cleared, in order */
    public array $clearedPackageClassNames = [];

    /** @var array<int, string[]> The argument list of every child start, in order */
    public array $startedChildArgumentLists = [];

    /** @var string[] The lines a recorded child writes to the output */
    public array $scriptedChildOutputLines = ['container cache cleared'];

    /** @var int The exit code a recorded child answers */
    public int $scriptedChildExitCode = Command::SUCCESS;

    /** @var bool Whether the child is really started (with the console script below) instead of recorded */
    public bool $startsRealChildProcess = false;

    /** @var string|null The console script of the child; null = the command's own */
    public ?string $consoleScriptPath = null;

    /** @var string The environment of the running kernel */
    public string $kernelEnvironment = 'test';

    /** @var bool Whether the running kernel is in debug mode */
    public bool $kernelInDebugMode = false;

    /** @var string The build directory of the running kernel; by default a path nothing is created at */
    public string $kernelBuildDirectory;

    /** @var bool Whether removing the warm-up directory works; false = it is left and reported as not removable */
    public bool $warmupDirectoryRemovable = true;

    /**
     * @param string[] $bundleClassNames
     * @param string[] $packageClassNamesBoundToSkills
     */
    public function __construct(protected array $bundleClassNames, protected array $packageClassNamesBoundToSkills)
    {
        parent::__construct(self::PROBE_COMMAND_NAME);
        $this->kernelBuildDirectory = sys_get_temp_dir() . '/agents-test-mcp-clear-cache-without-build-directory/cache/test';
    }

    protected function getBundleClasses(): array
    {
        return $this->bundleClassNames;
    }

    protected function findPackageClassNamesBoundToSkills(): array
    {
        return $this->packageClassNamesBoundToSkills;
    }

    protected function clearPackageDocumentationCache(string $packageClassName): void
    {
        $this->clearedPackageClassNames[] = $packageClassName;
    }

    protected function getConsoleScriptPath(): string
    {
        return $this->consoleScriptPath ?? parent::getConsoleScriptPath();
    }

    protected function getRunningKernelEnvironment(): string
    {
        return $this->kernelEnvironment;
    }

    protected function isRunningKernelInDebugMode(): bool
    {
        return $this->kernelInDebugMode;
    }

    protected function getRunningKernelBuildDirectory(): string
    {
        return $this->kernelBuildDirectory;
    }

    protected function startContainerCacheClearChildProcess(array $childArguments, OutputInterface $output): int
    {
        $this->startedChildArgumentLists[] = $childArguments;
        if ($this->startsRealChildProcess) {
            return parent::startContainerCacheClearChildProcess($childArguments, $output);
        }
        foreach ($this->scriptedChildOutputLines as $scriptedChildOutputLine) {
            $output->writeln($scriptedChildOutputLine);
        }
        return $this->scriptedChildExitCode;
    }

    protected function removeContainerCacheWarmupDirectory(string $warmupDirectoryPath): bool
    {
        return $this->warmupDirectoryRemovable && parent::removeContainerCacheWarmupDirectory($warmupDirectoryPath);
    }
}
