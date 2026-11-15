<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents;

use DDD\Domain\AI\Entities\MCPPackages\MCPBundle;
use DDD\Domain\AI\Entities\MCPPackages\MCPBundleDocumentation;

/** Fixture: a bundle below `Presentation/Mcp` that records the cache flag of its documentation build and its clears. */
class ProbeDocumentsMCPBundle extends MCPBundle
{
    public const array INCLUDED_PACKAGES = [ProbeDocumentsMCPPackage::class];

    public static ?string $name = 'probe-documents';

    public static ?string $description = 'Probe documents bundle';

    /** @var bool[] The `$noCache` of every documentation build, in order */
    public static array $noCacheFlagsOfBuilds = [];

    /** @var int How often the documentation cache was cleared */
    public static int $documentationCacheClearCount = 0;

    public static function buildDocumentation(bool $includeOutputSchema = false, bool $addObjectsToDef = false, int $maxDepth = 6, bool $noCache = false): MCPBundleDocumentation
    {
        static::$noCacheFlagsOfBuilds[] = $noCache;
        return MCPBundleDocumentation::createFromClassName(static::class, $includeOutputSchema, $addObjectsToDef, $maxDepth);
    }

    public static function clearDocumentationCache(int $maxDepth = 6): void
    {
        static::$documentationCacheClearCount++;
    }
}
