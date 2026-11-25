<?php

declare(strict_types=1);

/**
 * The package kernel as an application has it that switched the voice channel on and did not install the WebSocket
 * library of the session worker: the boot of `tests/Support/bin/console.php`, with every class of the library refused
 * by the class loader ({@see \DDD\Tests\Agents\Fixtures\VoiceWithoutSessionWorkerLibrary\ClassLoaderWithoutSessionWorkerLibrary})
 * and `AI.agentVoice.enabled` declared true. The container of such an installation differs from the package kernel's
 * (the session worker and its attach handler are no services), so it is compiled into a cache directory of its own,
 * beside the one the process would use.
 *
 * Returns the kernel, not booted.
 */

use DDD\Tests\Agents\Fixtures\VoiceWithoutSessionWorkerLibrary\ClassLoaderWithoutSessionWorkerLibrary;
use DDD\Tests\Agents\Support\AgentsTestKernel;
use DDD\Tests\Agents\Support\PackageKernelDirectories;
use DDD\Tests\Agents\Support\PackageTestAutoloader;
use DDD\Tests\Agents\Support\PackageTestEnvironment;
use DDD\Tests\Agents\Voice\Support\AgentVoiceTestConfiguration;

ini_set('memory_limit', '1G');
$packageDirectory = dirname(__DIR__, 3);
require_once $packageDirectory . '/tests/Support/PackageTestAutoloader.php';
require_once __DIR__ . '/ClassLoaderWithoutSessionWorkerLibrary.php';
$composerClassLoader = PackageTestAutoloader::forPackageDirectory($packageDirectory)->register();
$composerClassLoader->unregister();
spl_autoload_register([new ClassLoaderWithoutSessionWorkerLibrary($composerClassLoader), 'loadClass'], true, true);
(new PackageTestEnvironment($packageDirectory))->apply();
(new PackageKernelDirectories($packageDirectory))->apply();
foreach (['APP_CACHE_DIR', 'APP_LOG_DIR'] as $directoryEnvironmentVariable) {
    $_SERVER[$directoryEnvironmentVariable] = $_ENV[$directoryEnvironmentVariable] = $_SERVER[$directoryEnvironmentVariable] . '-without-voice-session-worker-library';
}
if (!defined('APP_PREFIX')) {
    define('APP_PREFIX', 'app');
}
AgentVoiceTestConfiguration::declareAgentVoiceConfiguration(AgentVoiceTestConfiguration::buildSwitchedOnConfiguration());

return new AgentsTestKernel((string)($_SERVER['APP_ENV'] ?? 'test'), (bool)($_SERVER['APP_DEBUG'] ?? false));
