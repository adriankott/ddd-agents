<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Skills;

use DDD\Domain\AI\Entities\Discovery\AgentSourceRoot;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Entities\MCPPackages\MCPBundle;
use DDD\Domain\AI\Entities\MCPPackages\MCPClassCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Finds the MCP bundle and package classes in source trees: every PHP file below `<source root>/Presentation/Mcp`
 * whose class (by PSR-4 over its root) is a concrete subclass of {@see MCPBundle} or {@see MCPPackage}. The result is
 * a {@see MCPClassCatalog}. Reads the file system and autoloads the classes it finds; nothing is memoized (a caller
 * that asks twice scans twice).
 */
class MCPClassCatalogService extends Service
{
    /** @var string The folder below a source root that holds the MCP bundles and packages */
    public const string MCP_CLASSES_FOLDER = 'Presentation/Mcp';

    /**
     * The catalogue of the MCP classes below `Presentation/Mcp` of every root in `$agentSourceRoots`.
     *
     * @param AgentSourceRoots $agentSourceRoots
     * @return MCPClassCatalog
     */
    public function findMCPClassCatalog(AgentSourceRoots $agentSourceRoots): MCPClassCatalog
    {
        $mCPClassCatalog = new MCPClassCatalog();
        foreach ($agentSourceRoots->getElements() as $agentSourceRoot) {
            foreach ($this->findMCPClassNamesInSourceRoot($agentSourceRoot) as $className) {
                $mCPClassCatalog->addClassName($className);
            }
        }
        return $mCPClassCatalog;
    }

    /**
     * The catalogue over the roots of the discovery contract ({@see AgentSourceRootsService}): the application's
     * `src/` and the source path of every installed DDD module.
     *
     * @return MCPClassCatalog
     */
    public function findMCPClassCatalogOfAllSourceRoots(): MCPClassCatalog
    {
        /** @var AgentSourceRootsService $agentSourceRootsService */
        $agentSourceRootsService = DDDService::instance()->getService(AgentSourceRootsService::class);
        return $this->findMCPClassCatalog($agentSourceRootsService->getSourceRoots());
    }

    /**
     * The concrete MCP bundle and package classes below `Presentation/Mcp` of one root.
     *
     * @param AgentSourceRoot $agentSourceRoot
     * @return string[]
     */
    public function findMCPClassNamesInSourceRoot(AgentSourceRoot $agentSourceRoot): array
    {
        $mCPClassesDirectory = $agentSourceRoot->directory . '/' . self::MCP_CLASSES_FOLDER;
        if (!is_dir($mCPClassesDirectory)) {
            return [];
        }
        $mCPClassNames = [];
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mCPClassesDirectory, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $classFile */
        foreach ($fileIterator as $classFile) {
            if (!$classFile->isFile() || $classFile->getExtension() !== 'php') {
                continue;
            }
            $className = $agentSourceRoot->findClassNameForFilePath($classFile->getPathname());
            if ($className !== null && $this->isConcreteMCPClass($className)) {
                $mCPClassNames[] = $className;
            }
        }
        sort($mCPClassNames);
        return $mCPClassNames;
    }

    /**
     * Whether `$className` exists and is a concrete subclass of {@see MCPBundle} or {@see MCPPackage}.
     *
     * @param string $className
     * @return bool
     */
    protected function isConcreteMCPClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }
        if (!is_subclass_of($className, MCPBundle::class) && !is_subclass_of($className, MCPPackage::class)) {
            return false;
        }
        return !(new ReflectionClass($className))->isAbstract();
    }
}
