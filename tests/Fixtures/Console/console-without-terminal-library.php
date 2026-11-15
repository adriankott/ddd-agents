<?php

declare(strict_types=1);

/**
 * The package kernel's console as an application without `php-tui/php-tui` has it: the same boot as
 * `tests/Support/bin/console.php`, with every class of the library refused by the class loader
 * ({@see \DDD\Tests\Agents\Fixtures\Console\ClassLoaderWithoutTerminalLibrary}).
 */

use DDD\Tests\Agents\Fixtures\Console\ClassLoaderWithoutTerminalLibrary;
use DDD\Tests\Agents\Support\AgentsTestKernel;
use DDD\Tests\Agents\Support\PackageKernelDirectories;
use DDD\Tests\Agents\Support\PackageTestAutoloader;
use DDD\Tests\Agents\Support\PackageTestEnvironment;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

ini_set('memory_limit', '1G');
$packageDirectory = dirname(__DIR__, 3);
require_once $packageDirectory . '/tests/Support/PackageTestAutoloader.php';
require_once __DIR__ . '/ClassLoaderWithoutTerminalLibrary.php';
$composerClassLoader = PackageTestAutoloader::forPackageDirectory($packageDirectory)->register();
$composerClassLoader->unregister();
spl_autoload_register([new ClassLoaderWithoutTerminalLibrary($composerClassLoader), 'loadClass'], true, true);
(new PackageTestEnvironment($packageDirectory))->apply();
(new PackageKernelDirectories($packageDirectory))->apply();
if (!defined('APP_PREFIX')) {
    define('APP_PREFIX', 'app');
}
$kernel = new AgentsTestKernel((string)($_SERVER['APP_ENV'] ?? 'test'), (bool)($_SERVER['APP_DEBUG'] ?? false));
exit((new Application($kernel))->run(new ArgvInput()));
