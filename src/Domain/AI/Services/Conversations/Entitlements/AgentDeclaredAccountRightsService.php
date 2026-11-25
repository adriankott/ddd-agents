<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Entitlements;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentDeclaredAccountRights;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoot;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService;
use DDD\Domain\AI\Services\Skills\MCPPackageIdsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use Throwable;

/**
 * Every account right the INSTALLED PACKAGES declare — the read a host's guard test (and
 * `app:agent:check-installation`) holds its rights model against: "every package right is answered or mapped".
 *
 * Read from the package source roots ({@see AgentSourceRootsService}; the application's own root is not a package):
 * - each rights constant class: a file named `*AccountRights.php` below `Domain/` whose class has a `RIGHTS` list
 *   ({@see \DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights::RIGHTS}, a module's
 *   `CloudDriveAccountRights::RIGHTS`, …);
 * - each MCP package below `Presentation/Mcp`: the rights of `#[MCPRequiresAccountRights]` on the class and its tools
 *   (a package that names a right of another package's constant class, e.g. a text tool's `manageTexts`).
 *
 * Built once per instance.
 */
class AgentDeclaredAccountRightsService extends Service
{
    /** @var string The file name suffix of a rights constant class */
    public const string RIGHTS_CONSTANT_CLASS_FILE_SUFFIX = 'AccountRights.php';

    /** @var string The constant of a rights constant class that lists its rights */
    public const string RIGHTS_LIST_CONSTANT = 'RIGHTS';

    /** @var string The folder below a source root that holds the rights constant classes */
    public const string DOMAIN_FOLDER = 'Domain';

    protected ?AgentDeclaredAccountRights $agentDeclaredAccountRights = null;

    /**
     * The rights the installed packages declare, one entry per name with its declaring classes.
     *
     * @return AgentDeclaredAccountRights
     */
    public function findDeclaredAccountRights(): AgentDeclaredAccountRights
    {
        return $this->agentDeclaredAccountRights ??= $this->buildDeclaredAccountRights();
    }

    /**
     * Every right name the installed packages declare, sorted.
     *
     * @return string[]
     */
    public function getDeclaredAccountRightNames(): array
    {
        return $this->findDeclaredAccountRights()->getRightNames();
    }

    /** Forgets the built list (a test with other source roots) */
    public function resetDeclaredAccountRights(): void
    {
        $this->agentDeclaredAccountRights = null;
    }

    protected function buildDeclaredAccountRights(): AgentDeclaredAccountRights
    {
        $agentDeclaredAccountRights = AgentDeclaredAccountRights::newInstance();
        /** @var AgentSourceRootsService $agentSourceRootsService */
        $agentSourceRootsService = DDDService::instance()->getService(AgentSourceRootsService::class);
        foreach ($agentSourceRootsService->getSourceRoots()->getElements() as $agentSourceRoot) {
            if ($agentSourceRoot->isApplication) {
                continue;
            }
            $this->addRightsOfConstantClassesInSourceRoot($agentDeclaredAccountRights, $agentSourceRoot);
            $this->addRightsOfPackagesInSourceRoot($agentDeclaredAccountRights, $agentSourceRoot);
        }
        return $agentDeclaredAccountRights;
    }

    /** The `RIGHTS` lists of the rights constant classes below `<root>/Domain` */
    protected function addRightsOfConstantClassesInSourceRoot(AgentDeclaredAccountRights $agentDeclaredAccountRights, AgentSourceRoot $agentSourceRoot): void
    {
        $domainDirectory = $agentSourceRoot->directory . '/' . static::DOMAIN_FOLDER;
        if (!is_dir($domainDirectory)) {
            return;
        }
        $rightsClassFilePaths = [];
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainDirectory, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), static::RIGHTS_CONSTANT_CLASS_FILE_SUFFIX)) {
                $rightsClassFilePaths[] = $file->getPathname();
            }
        }
        sort($rightsClassFilePaths);
        foreach ($rightsClassFilePaths as $rightsClassFilePath) {
            $rightsClassName = $agentSourceRoot->findClassNameForFilePath($rightsClassFilePath);
            if ($rightsClassName === null) {
                continue;
            }
            foreach ($this->findRightsListOfClass($rightsClassName) as $rightName) {
                $agentDeclaredAccountRights->addDeclaration($rightName, $rightsClassName);
            }
        }
    }

    /**
     * The `RIGHTS` list of `$rightsClassName`; [] for a class that does not load or has no such list.
     *
     * @return string[]
     */
    protected function findRightsListOfClass(string $rightsClassName): array
    {
        try {
            if (!class_exists($rightsClassName)) {
                return [];
            }
            $reflectionClass = new ReflectionClass($rightsClassName);
            if (!$reflectionClass->hasConstant(static::RIGHTS_LIST_CONSTANT)) {
                return [];
            }
            $reflectionClassConstant = new ReflectionClassConstant($rightsClassName, static::RIGHTS_LIST_CONSTANT);
            $rightsList = $reflectionClassConstant->getValue();
        } catch (Throwable) {
            return [];
        }
        return is_array($rightsList) ? array_values(array_filter($rightsList, 'is_string')) : [];
    }

    /** The rights of `#[MCPRequiresAccountRights]` on every MCP package of the root and on its tools */
    protected function addRightsOfPackagesInSourceRoot(AgentDeclaredAccountRights $agentDeclaredAccountRights, AgentSourceRoot $agentSourceRoot): void
    {
        /** @var MCPPackageIdsService $mcpPackageIdsService */
        $mcpPackageIdsService = DDDService::instance()->getService(MCPPackageIdsService::class);
        foreach ($mcpPackageIdsService->findPackageClassesInSourceRoot(rtrim($agentSourceRoot->rootNamespace, '\\'), $agentSourceRoot->directory) as $packageClass) {
            foreach ($this->findRequiredAccountRightNamesOfPackageAndTools($packageClass) as $rightName) {
                $agentDeclaredAccountRights->addDeclaration($rightName, $packageClass);
            }
        }
    }

    /**
     * The rights a package class and its tool methods require by attribute.
     *
     * @param class-string<MCPPackage> $packageClass
     * @return string[]
     */
    protected function findRequiredAccountRightNamesOfPackageAndTools(string $packageClass): array
    {
        $requiredRightNames = MCPPackage::findRequiredAccountRightNamesOfClass($packageClass);
        foreach ((new ReflectionClass($packageClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            foreach ($reflectionMethod->getAttributes(MCPRequiresAccountRights::class) as $accountRightsAttribute) {
                array_push($requiredRightNames, ...$accountRightsAttribute->newInstance()->accountRightNames);
            }
        }
        return array_values(array_unique($requiredRightNames));
    }
}
