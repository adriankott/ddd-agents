<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The package's rename map (`renames.json`, read by Core's `vendor/bin/ddd-renames` in the adopting applications):
 * format 1, this package's name, every entry a known kind with a regular version not newer than the package, old !=
 * new. Core's `ddd-renames validate` checks the rest (chains, swaps, patterns); it runs where Core ships the tool.
 */
class RenamesMapTest extends UnitTestCase
{
    public const array KINDS = ['skillName', 'constant', 'class', 'namespace', 'configKey', 'consoleCommand', 'packageId', 'envVar'];

    public function testTheMapIsWellFormed(): void
    {
        $packageDirectory = dirname(__DIR__, 2);
        $renamesMap = json_decode((string)file_get_contents($packageDirectory . '/renames.json'), true, 512, JSON_THROW_ON_ERROR);
        $composerManifest = json_decode((string)file_get_contents($packageDirectory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $renamesMap['format']);
        self::assertSame($composerManifest['name'], $renamesMap['package']);
        self::assertNotEmpty($renamesMap['renames']);
        foreach ($renamesMap['renames'] as $renameIndex => $renameEntry) {
            self::assertContains($renameEntry['kind'], self::KINDS, (string)$renameIndex);
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $renameEntry['version'], (string)$renameIndex);
            self::assertTrue(version_compare($renameEntry['version'], $composerManifest['version'], '<='), (string)$renameIndex);
            self::assertNotSame($renameEntry['old'], $renameEntry['new'], (string)$renameIndex);
        }
    }

    public function testTheCoreValidatorAcceptsTheMapWhereItIsInstalled(): void
    {
        $renamesAutoloadFile = $this->findCoreRenamesToolDirectory();
        if ($renamesAutoloadFile === null) {
            self::markTestSkipped('mgamadeus/ddd without tools/Renames (before 3.45.0)');
        }
        $validatorOutput = [];
        $validatorExitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname($renamesAutoloadFile, 3) . '/bin/ddd-renames') . ' validate --package-dir ' . escapeshellarg(dirname(__DIR__, 2)) . ' 2>&1', $validatorOutput, $validatorExitCode);

        self::assertSame(0, $validatorExitCode, implode("\n", $validatorOutput));
    }

    protected function findCoreRenamesToolDirectory(): ?string
    {
        $coreRenamesAutoloadFile = dirname((new \ReflectionClass(\DDD\DDDBundle::class))->getFileName(), 2) . '/tools/Renames/autoload.php';
        return is_file($coreRenamesAutoloadFile) ? $coreRenamesAutoloadFile : null;
    }
}
