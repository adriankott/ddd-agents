<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Entities\MCPPackages\MCPBundle;
use DDD\Domain\AI\Entities\MCPPackages\MCPClassCatalog;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonRecording;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonScript;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonSession;
use DDD\Domain\AI\Services\Skills\MCPClassCatalogService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use RuntimeException;

/**
 * The tool-call comparison of one MCP package: it builds the session of one run ({@see ToolCallComparisonSession},
 * created only here — the application's class of the same path when it has one) and finds the package and bundle
 * classes a script names in the source roots, the application's root first.
 */
class ToolCallComparisonsService extends Service
{
    /** A new, unprepared session of one run: the application's session class when it declares one at the same path. */
    public function newSession(): ToolCallComparisonSession
    {
        $sessionClassName = DDDService::instance()->getContainerServiceClassNameForClass(ToolCallComparisonSession::class) ?? ToolCallComparisonSession::class;
        /** @var ToolCallComparisonSession $toolCallComparisonSession */
        $toolCallComparisonSession = new $sessionClassName();
        return $toolCallComparisonSession;
    }

    /**
     * The session of one run of the script, not yet started: a recording run when `$toolCallComparisonRecording` is
     * null, a replay against it otherwise.
     *
     * @param string $packageClass the class name of the package under test
     * @param string[] $additionalPackageClasses the class names of the packages offered beside it
     * @throws RuntimeException when the recording refuses the script
     */
    public function buildSession(ToolCallComparisonScript $toolCallComparisonScript, string $packageClass, array $additionalPackageClasses, ?ToolCallComparisonRecording $toolCallComparisonRecording = null): ToolCallComparisonSession
    {
        $toolCallComparisonSession = $this->newSession();
        if ($toolCallComparisonRecording === null) {
            $toolCallComparisonSession->prepareRecording($toolCallComparisonScript, $packageClass, $additionalPackageClasses);
            return $toolCallComparisonSession;
        }
        $toolCallComparisonSession->prepareReplay($toolCallComparisonScript, $packageClass, $additionalPackageClasses, $toolCallComparisonRecording);
        return $toolCallComparisonSession;
    }

    /**
     * The concrete MCP package classes of the roots by short name, sorted by short name. A short name keeps the class of
     * the FIRST root that has one ({@see MCPClassCatalogService::findMCPClassNamesInSourceRoot()} per root, in the
     * order of the roots): the application's root comes first, so its class wins over a module's class of the same
     * short name.
     *
     * @return string[] short name → class name
     */
    public function findPackageClassesByShortName(AgentSourceRoots $agentSourceRoots): array
    {
        return $this->findClassesByShortName($agentSourceRoots, MCPPackage::class);
    }

    /**
     * The concrete MCP bundle classes of the roots, one per short name with the precedence of
     * {@see findPackageClassesByShortName()}, sorted by short name.
     *
     * @return string[]
     */
    public function findBundleClasses(AgentSourceRoots $agentSourceRoots): array
    {
        return array_values($this->findClassesByShortName($agentSourceRoots, MCPBundle::class));
    }

    /**
     * The package class a script names: a short name as found in `$packageClassesByShortName`, a class name of a listed
     * package as given.
     *
     * @param string[] $packageClassesByShortName short name → class name
     * @throws RuntimeException when the name is no MCP package of the roots
     */
    public function resolvePackageClass(string $packageName, array $packageClassesByShortName): string
    {
        $packageClass = $packageClassesByShortName[$packageName] ?? (in_array(ltrim($packageName, '\\'), $packageClassesByShortName, true) ? ltrim($packageName, '\\') : null);
        if ($packageClass === null || !is_a($packageClass, MCPPackage::class, true)) {
            throw new RuntimeException("\"{$packageName}\" is no MCP package under " . MCPClassCatalogService::MCP_CLASSES_FOLDER . ' of the application or a module');
        }
        return $packageClass;
    }

    /**
     * The concrete classes of one kind below `Presentation/Mcp` of the roots, by short name, the first root's class
     * kept for a short name.
     *
     * @param string $parentClassName {@see MCPPackage} or {@see MCPBundle}
     * @return string[] short name → class name, sorted by short name
     */
    protected function findClassesByShortName(AgentSourceRoots $agentSourceRoots, string $parentClassName): array
    {
        /** @var MCPClassCatalogService $mCPClassCatalogService */
        $mCPClassCatalogService = DDDService::instance()->getService(MCPClassCatalogService::class);
        $classesByShortName = [];
        foreach ($agentSourceRoots->getElements() as $agentSourceRoot) {
            foreach ($mCPClassCatalogService->findMCPClassNamesInSourceRoot($agentSourceRoot) as $className) {
                $shortClassName = MCPClassCatalog::getShortClassName($className);
                if (!isset($classesByShortName[$shortClassName]) && is_subclass_of($className, $parentClassName)) {
                    $classesByShortName[$shortClassName] = $className;
                }
            }
        }
        ksort($classesByShortName);
        return $classesByShortName;
    }
}
