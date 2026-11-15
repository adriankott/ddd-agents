<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The MCP bundle and package classes found in a set of source trees
 * ({@see \DDD\Domain\AI\Services\Skills\MCPClassCatalogService::findMCPClassCatalog()}): every concrete subclass of
 * {@see MCPBundle} and of {@see MCPPackage}, each list sorted by class name. A class is looked up by its short name
 * (the class name without its namespace), case-insensitively.
 *
 * @phpstan-consistent-constructor
 */
class MCPClassCatalog extends ValueObject
{
    /** @var string[] The concrete MCP bundle classes, sorted */
    public array $bundleClassNames = [];

    /** @var string[] The concrete MCP package classes, sorted */
    public array $packageClassNames = [];

    /**
     * Adds a class to the list of its kind; a class that is neither a bundle nor a package, or is already listed, is
     * left out.
     *
     * @param string $className
     * @return void
     */
    public function addClassName(string $className): void
    {
        if (is_subclass_of($className, MCPBundle::class)) {
            if (!in_array($className, $this->bundleClassNames, true)) {
                $this->bundleClassNames[] = $className;
                sort($this->bundleClassNames);
            }
            return;
        }
        if (is_subclass_of($className, MCPPackage::class) && !in_array($className, $this->packageClassNames, true)) {
            $this->packageClassNames[] = $className;
            sort($this->packageClassNames);
        }
    }

    /**
     * The bundle class whose short name is `$shortClassName` (case-insensitive); null when none is listed.
     *
     * @param string $shortClassName
     * @return string|null
     */
    public function findBundleClassNameByShortName(string $shortClassName): ?string
    {
        return static::findClassNameByShortName($this->bundleClassNames, $shortClassName);
    }

    /**
     * The package class whose short name is `$shortClassName` (case-insensitive); null when none is listed.
     *
     * @param string $shortClassName
     * @return string|null
     */
    public function findPackageClassNameByShortName(string $shortClassName): ?string
    {
        return static::findClassNameByShortName($this->packageClassNames, $shortClassName);
    }

    /**
     * The class name without its namespace.
     *
     * @param string $className
     * @return string
     */
    public static function getShortClassName(string $className): string
    {
        return substr((string)strrchr('\\' . $className, '\\'), 1);
    }

    /**
     * The first of `$classNames` whose short name is `$shortClassName` (case-insensitive).
     *
     * @param string[] $classNames
     * @param string $shortClassName
     * @return string|null
     */
    protected static function findClassNameByShortName(array $classNames, string $shortClassName): ?string
    {
        foreach ($classNames as $className) {
            if (strcasecmp(static::getShortClassName($className), $shortClassName) === 0) {
                return $className;
            }
        }
        return null;
    }
}
