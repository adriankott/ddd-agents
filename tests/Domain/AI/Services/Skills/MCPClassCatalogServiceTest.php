<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Skills;

use DDD\Domain\AI\Entities\Discovery\AgentSourceRoot;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Domain\AI\Entities\MCPPackages\MCPClassCatalog;
use DDD\Domain\AI\Services\Skills\MCPClassCatalogService;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Archive\ProbeArchiveMCPPackage;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents\ProbeDocumentsMCPBundle;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents\ProbeDocumentsMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The catalogue holds the concrete bundles and packages below `Presentation/Mcp` of each source root, sorted, each
 * once, and finds a class by its short name whatever the case; an abstract package and a class of another kind stay
 * out, a root without the folder adds nothing.
 */
class MCPClassCatalogServiceTest extends UnitTestCase
{
    public const string FIXTURE_ROOT_NAMESPACE = 'DDD\\Tests\\Agents\\Fixtures\\MCPClassCatalog';

    public function testTheCatalogueHoldsTheConcreteBundlesAndPackagesOfTheRoots(): void
    {
        $mCPClassCatalog = (new MCPClassCatalogService())->findMCPClassCatalog($this->buildFixtureSourceRoots());

        self::assertSame([ProbeDocumentsMCPBundle::class], $mCPClassCatalog->bundleClassNames);
        self::assertSame([ProbeArchiveMCPPackage::class, ProbeDocumentsMCPPackage::class], $mCPClassCatalog->packageClassNames);
    }

    public function testAClassIsFoundByItsShortNameWhateverTheCase(): void
    {
        $mCPClassCatalog = (new MCPClassCatalogService())->findMCPClassCatalog($this->buildFixtureSourceRoots());

        self::assertSame(ProbeDocumentsMCPBundle::class, $mCPClassCatalog->findBundleClassNameByShortName('probedocumentsmcpbundle'));
        self::assertSame(ProbeArchiveMCPPackage::class, $mCPClassCatalog->findPackageClassNameByShortName('ProbeArchiveMCPPackage'));
        self::assertNull($mCPClassCatalog->findPackageClassNameByShortName('ProbeDocumentsMCPBundle'), 'a bundle is no package');
        self::assertNull($mCPClassCatalog->findBundleClassNameByShortName('AbstractProbeMCPPackage'));
        self::assertSame('ProbeArchiveMCPPackage', MCPClassCatalog::getShortClassName(ProbeArchiveMCPPackage::class));
    }

    public function testAClassAddedTwiceOrOfAnotherKindIsListedOnceOrNotAtAll(): void
    {
        $mCPClassCatalog = new MCPClassCatalog();
        $mCPClassCatalog->addClassName(ProbeDocumentsMCPPackage::class);
        $mCPClassCatalog->addClassName(ProbeArchiveMCPPackage::class);
        $mCPClassCatalog->addClassName(ProbeDocumentsMCPPackage::class);
        $mCPClassCatalog->addClassName(self::class);

        self::assertSame([ProbeArchiveMCPPackage::class, ProbeDocumentsMCPPackage::class], $mCPClassCatalog->packageClassNames);
        self::assertSame([], $mCPClassCatalog->bundleClassNames);
    }

    public function testARootWithoutTheFolderAddsNothing(): void
    {
        $agentSourceRoot = AgentSourceRoot::forDirectory(self::FIXTURE_ROOT_NAMESPACE . '\\Presentation', $this->getFixtureSourceDirectory() . '/Presentation', false);
        self::assertNotNull($agentSourceRoot);

        self::assertSame([], (new MCPClassCatalogService())->findMCPClassNamesInSourceRoot($agentSourceRoot));
    }

    public function testTheModulesOwnRootsHoldNoConcreteBundle(): void
    {
        $mCPClassCatalog = (new MCPClassCatalogService())->findMCPClassCatalogOfAllSourceRoots();

        self::assertSame([], $mCPClassCatalog->bundleClassNames, 'the module ships no bundle');
        self::assertNotSame([], $mCPClassCatalog->packageClassNames, 'the module ships packages');
    }

    protected function buildFixtureSourceRoots(): AgentSourceRoots
    {
        $agentSourceRoots = AgentSourceRoots::newInstance();
        $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory(self::FIXTURE_ROOT_NAMESPACE, $this->getFixtureSourceDirectory(), true));
        return $agentSourceRoots;
    }

    protected function getFixtureSourceDirectory(): string
    {
        return dirname(__DIR__, 4) . '/Fixtures/MCPClassCatalog';
    }
}
