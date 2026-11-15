<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\MCPPackages\MCPBundle;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\MCPClassCatalogService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
 *     service or wiring and the skill → package configuration live there, so the next process boots with them.
 *
 * A running worker still needs a restart: no command reloads a live worker. A skill body is read from disk and kept
 * only in the process-static configuration tree, so a new process picks every change up at once; a long-running
 * consumer keeps its compiled container and its skill bodies until it restarts ({@see getWorkerRestartNotice()}).
 */
#[AsCommand(
    name: 'app:mcp:clear-cache',
    description: 'Clear every agent cache after an MCP tool or skill change: the MCP documentation cache (tools) and the compiled container (cache:clear — new tool classes and wiring, the skill → package configuration). Then restart the agent workers.',
)]
class MCPClearCacheCommand extends AgentConsoleCommand
{
    /**
     * Clears the documentation caches, rebuilds the compiled container and names the restart that is still needed.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
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
        $output->writeln('Layer 2 — rebuilding the compiled container (' . $this->getContainerCacheClearCommandName() . ') …');
        $this->rebuildCompiledContainer($output);

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
     * Runs the container cache clear in this process, on the console application the command belongs to (none
     * outside a console application: nothing runs).
     *
     * @param OutputInterface $output
     * @return void
     */
    protected function rebuildCompiledContainer(OutputInterface $output): void
    {
        $application = $this->getApplication();
        if ($application !== null) {
            $application->find($this->getContainerCacheClearCommandName())->run(new ArrayInput([]), $output);
        }
    }
}
