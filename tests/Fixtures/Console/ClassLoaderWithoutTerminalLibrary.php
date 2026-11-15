<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\Console;

use Composer\Autoload\ClassLoader;

/**
 * A class loader that behaves as if `php-tui/php-tui` were not installed: it loads every class through the wrapped
 * Composer loader except those of the library's namespaces, which it refuses (and records).
 */
class ClassLoaderWithoutTerminalLibrary
{
    /** @var string The namespace prefix of every class of the library */
    public const string REFUSED_NAMESPACE_PREFIX = 'PhpTui\\';

    /**
     * @param ClassLoader $classLoader the loader every other class is loaded through
     */
    public function __construct(protected ClassLoader $classLoader)
    {
    }

    /**
     * @param string $className
     * @return void
     */
    public function loadClass(string $className): void
    {
        if (str_starts_with($className, self::REFUSED_NAMESPACE_PREFIX)) {
            return;
        }
        $this->classLoader->loadClass($className);
    }
}
