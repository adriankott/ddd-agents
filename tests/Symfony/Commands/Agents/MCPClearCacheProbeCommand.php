<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\MCPClearCacheCommand;

/**
 * The clear-cache command with the bundles and the skill-bound packages a test names, recording the package caches it
 * clears instead of reaching the cache.
 */
class MCPClearCacheProbeCommand extends MCPClearCacheCommand
{
    public const string PROBE_COMMAND_NAME = 'agents-test:mcp-clear-cache-probe';

    /** @var string[] The packages whose caches were cleared, in order */
    public array $clearedPackageClassNames = [];

    /**
     * @param string[] $bundleClassNames
     * @param string[] $packageClassNamesBoundToSkills
     */
    public function __construct(protected array $bundleClassNames, protected array $packageClassNamesBoundToSkills)
    {
        parent::__construct(self::PROBE_COMMAND_NAME);
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
}
