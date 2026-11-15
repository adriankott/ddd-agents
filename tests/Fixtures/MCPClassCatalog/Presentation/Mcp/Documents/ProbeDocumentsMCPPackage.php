<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Documents;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;

/** Fixture: a package below `Presentation/Mcp` that records the cache flag of its documentation build and its clears. */
class ProbeDocumentsMCPPackage extends MCPPackage
{
    public static ?string $name = 'probe.documents';

    public static ?string $description = 'Probe documents package';

    /** @var bool[] The `$noCache` of every documentation build, in order */
    public static array $noCacheFlagsOfBuilds = [];

    /** @var int How often the documentation cache was cleared */
    public static int $documentationCacheClearCount = 0;

    public static function buildDocumentation(bool $noCache = false): MCPPackageDocumentation
    {
        static::$noCacheFlagsOfBuilds[] = $noCache;
        return MCPPackageDocumentation::createFromClassName(static::class);
    }

    public static function clearDocumentationCache(): void
    {
        static::$documentationCacheClearCount++;
    }
}
