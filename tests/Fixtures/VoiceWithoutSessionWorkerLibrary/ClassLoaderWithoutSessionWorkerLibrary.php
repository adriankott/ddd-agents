<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\VoiceWithoutSessionWorkerLibrary;

use Composer\Autoload\ClassLoader;

/**
 * A class loader that behaves as if the WebSocket library of the voice session worker (`ratchet/pawl` with
 * `react/event-loop` and what they require) were not installed: it loads every class through the wrapped Composer
 * loader except those of the libraries' namespaces, which it refuses.
 */
class ClassLoaderWithoutSessionWorkerLibrary
{
    /** @var string[] The namespace prefixes of every class of the libraries */
    public const array REFUSED_NAMESPACE_PREFIXES = ['Ratchet\\', 'React\\'];

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
        foreach (self::REFUSED_NAMESPACE_PREFIXES as $refusedNamespacePrefix) {
            if (str_starts_with($className, $refusedNamespacePrefix)) {
                return;
            }
        }
        $this->classLoader->loadClass($className);
    }
}
