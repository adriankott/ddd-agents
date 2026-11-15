<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Infrastructure\Cache\Cache;
use DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Archive\ProbeArchiveMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A package's documentation is cached under a key of its class: a build without `$noCache` reads a cached entry, a
 * build with it replaces the entry, and the clear drops it — as a bundle's documentation does.
 */
class MCPPackageDocumentationCacheTest extends UnitTestCase
{
    public const string CACHE_KEY = 'mcp.doc.package.DDD.Tests.Agents.Fixtures.MCPClassCatalog.Presentation.Mcp.Archive.ProbeArchiveMCPPackage';

    protected function tearDown(): void
    {
        Cache::instance(Cache::CACHE_GROUP_REDIS)->delete(self::CACHE_KEY);
        parent::tearDown();
    }

    public function testACachedEntryIsReadUnlessARebuildIsAskedForAndTheClearDropsIt(): void
    {
        $cache = Cache::instance(Cache::CACHE_GROUP_REDIS);
        $staleDocumentation = new MCPPackageDocumentation();
        $staleDocumentation->name = 'stale';
        $cache->set(self::CACHE_KEY, $staleDocumentation, Cache::CACHE_TTL_ONE_DAY);
        if (!$cache->get(self::CACHE_KEY) instanceof MCPPackageDocumentation) {
            self::markTestSkipped('the cache of this process keeps nothing');
        }

        self::assertSame('stale', ProbeArchiveMCPPackage::buildDocumentation()->name);
        self::assertSame('probe.archive', ProbeArchiveMCPPackage::buildDocumentation(noCache: true)->name);
        self::assertSame('probe.archive', $cache->get(self::CACHE_KEY)?->name, 'the rebuild replaces the cached entry');

        ProbeArchiveMCPPackage::clearDocumentationCache();
        self::assertNotInstanceOf(MCPPackageDocumentation::class, $cache->get(self::CACHE_KEY), 'the clear drops the entry');
    }
}
