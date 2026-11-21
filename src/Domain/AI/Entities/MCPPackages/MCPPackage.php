<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Cache\Cache;

/**
 * Static registry describing a single MCP package — a group of #[MCPTool] methods.
 *
 * Definitions are code (static $name/$description). The generated documentation is
 * deploy-invariant but expensive to reflect, so {@see self::buildDocumentation()}
 * memoizes its result in Redis (see {@see MCPBundle} for the invalidation caveat).
 */
class MCPPackage extends ValueObject
{
    /** @var string|null A unique name of this MCP Package */
    public static ?string $name = null;

    /** @var string|null Description of the MCP Package */
    public static ?string $description = null;

    /**
     * Whether the package is available in this installation. An unavailable package is left out of the skill binding
     * ({@see \DDD\Domain\AI\Entities\Skills\AISkill::getPackageClassesBySkillName()}): its tools are neither offered
     * nor listed, and a skill bound to unavailable packages only is no skill. Every package is available unless it
     * depends on a component an application switches on (the package's memory tools: the memory store).
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Builds — and caches in Redis — the MCP documentation for this package. `$noCache` bypasses the cached entry,
     * rebuilds from source and overwrites the cache (invalidation + refresh), as {@see MCPBundle::buildDocumentation()}
     * does.
     */
    public static function buildDocumentation(bool $noCache = false): MCPPackageDocumentation
    {
        $cache = Cache::instance(Cache::CACHE_GROUP_REDIS);
        $cacheKey = static::buildDocumentationCacheKey();

        if (!$noCache) {
            $cached = $cache->get($cacheKey);
            if ($cached instanceof MCPPackageDocumentation) {
                return $cached;
            }
        }

        $documentation = MCPPackageDocumentation::createFromClassName(static::class);
        $cache->set($cacheKey, $documentation, Cache::CACHE_TTL_ONE_DAY);

        return $documentation;
    }

    /**
     * Drops the cached documentation of this package. Call after changing a tool signature or a referenced DTO of the
     * package: the cache key names the class only.
     */
    public static function clearDocumentationCache(): void
    {
        Cache::instance(Cache::CACHE_GROUP_REDIS)->delete(static::buildDocumentationCacheKey());
    }

    /** The cache key of this package's documentation: the class name only. */
    protected static function buildDocumentationCacheKey(): string
    {
        return 'mcp.doc.package.' . str_replace('\\', '.', static::class);
    }
}
