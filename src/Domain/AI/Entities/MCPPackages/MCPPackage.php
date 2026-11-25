<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Cache\Cache;
use ReflectionAttribute;
use ReflectionClass;

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
     * The account rights the package class requires (`#[MCPRequiresAccountRights]` on the class, never on a tool),
     * read through its parent packages: a subclass that replaces a package (an application's same-path class) keeps
     * the gates of the package it extends and may add its own. An application lifts a tool's right with a tool
     * overlay (`AI.toolOverlays`), never by leaving the attribute out.
     *
     * @param class-string<MCPPackage> $packageClass
     * @return string[]
     */
    public static function findRequiredAccountRightNamesOfClass(string $packageClass): array
    {
        $requiredRightNames = [];
        foreach (static::findClassAttributesOfPackageHierarchy(new ReflectionClass($packageClass), MCPRequiresAccountRights::class) as $accountRightsAttribute) {
            $requiredRightNames = array_merge($requiredRightNames, $accountRightsAttribute->newInstance()->accountRightNames);
        }
        return array_values(array_unique($requiredRightNames));
    }

    /**
     * The class-level attributes of one kind on a package class and on every parent class up to (not including)
     * {@see MCPPackage}: class attributes are not inherited by PHP, so an entitlement declared on a package is read
     * here for its subclasses too.
     *
     * @param class-string $attributeClassName
     * @return ReflectionAttribute[]
     */
    public static function findClassAttributesOfPackageHierarchy(ReflectionClass $packageReflectionClass, string $attributeClassName): array
    {
        $classAttributes = [];
        $reflectionClass = $packageReflectionClass;
        while ($reflectionClass !== false && $reflectionClass->getName() !== self::class) {
            array_push($classAttributes, ...$reflectionClass->getAttributes($attributeClassName));
            $reflectionClass = $reflectionClass->getParentClass();
        }
        return $classAttributes;
    }

    /**
     * Drops the cached documentation of this package. Call after changing a tool signature or a referenced DTO of the
     * package: the cache key names the class only.
     */
    public static function clearDocumentationCache(): void
    {
        Cache::instance(Cache::CACHE_GROUP_REDIS)->delete(static::buildDocumentationCacheKey());
    }

    /**
     * The cache key of this package's documentation: the class name, and the fingerprint of the application's tool
     * overlays and space argument ({@see MCPToolOverlays::getSchemaConfigurationFingerprint()}) when it has any.
     */
    protected static function buildDocumentationCacheKey(): string
    {
        $schemaConfigurationFingerprint = MCPToolOverlays::getSchemaConfigurationFingerprint();
        return 'mcp.doc.package.' . str_replace('\\', '.', static::class)
            . ($schemaConfigurationFingerprint !== '' ? '.' . $schemaConfigurationFingerprint : '');
    }
}
