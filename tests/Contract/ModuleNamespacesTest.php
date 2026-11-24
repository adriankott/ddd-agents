<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Infrastructure\Modules\DDDModule;
use DDD\Modules\Agents\AgentsModule;
use DDD\Testing\Modules\ModuleNamespacesTestCase;
use DDD\Tests\Agents\Support\PackageTestAutoloader;

/**
 * Core's owned-namespace check over Core, the modules of the vendor directory the package suite runs on and this
 * package ({@see AgentsModule::getOwnedNamespaces()}): no class name or config file is shipped by two packages, no
 * class of this package lies outside its owned namespaces.
 */
class ModuleNamespacesTest extends ModuleNamespacesTestCase
{
    protected function getProjectDirectory(): string
    {
        return dirname(__DIR__, 2);
    }

    /** The modules of the suite's vendor directory (the package has none of its own) and this module */
    protected function findModuleClasses(): array
    {
        $moduleClasses = [];
        $installedPath = rtrim((string)getenv(PackageTestAutoloader::VENDOR_DIRECTORY_ENV), '/') . '/composer/installed.json';
        if (is_file($installedPath)) {
            $installed = json_decode((string)file_get_contents($installedPath), true);
            foreach (($installed['packages'] ?? $installed ?? []) as $package) {
                $moduleClass = is_array($package) ? ($package['extra']['ddd-module'] ?? null) : null;
                if (is_string($moduleClass) && class_exists($moduleClass) && is_subclass_of($moduleClass, DDDModule::class)) {
                    $moduleClasses[] = $moduleClass;
                }
            }
        }
        $moduleClasses[] = AgentsModule::class;
        return array_values(array_unique($moduleClasses));
    }
}
