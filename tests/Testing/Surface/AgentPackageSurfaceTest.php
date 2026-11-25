<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Testing\Surface;

use DDD\Testing\Agents\Surface\AgentSurfaceSnapshotTestCase;
use DDD\Tests\Agents\Support\PackageTestEnvironment;

/**
 * The agent package's own surface, pinned with the hook the package ships for applications: the skills, packages and
 * purposes of the package under its test kernel. The pinned file is shipped beside the hook
 * ({@see AgentSurfaceSnapshotTestCase::PACKAGE_SURFACE_FILE}): it is what an application's
 * `testThePackagesSurfaceDiffersOnlyWhereTheApplicationAcceptedIt()` compares its own surface with.
 *
 * A change of a tool, a skill or a purpose of the package changes this file in the same commit:
 * `REGENERATE_AGENT_SURFACE_SNAPSHOT=1 php tests/Support/bin/phpunit.php tests/Testing/Surface/AgentPackageSurfaceTest.php`.
 * The file is the package's surface without the fixture application: a run with
 * {@see PackageTestEnvironment::FIXTURE_APPLICATION_VARIABLE} skips this test.
 */
class AgentPackageSurfaceTest extends AgentSurfaceSnapshotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixtureApplicationRequest = getenv(PackageTestEnvironment::FIXTURE_APPLICATION_VARIABLE);
        if ($fixtureApplicationRequest !== false && $fixtureApplicationRequest !== '' && $fixtureApplicationRequest !== '0') {
            self::markTestSkipped('The package surface is pinned without the fixture application.');
        }
    }

    protected function getPinnedSurfaceFile(): string
    {
        return static::PACKAGE_SURFACE_FILE;
    }

    /** The package compares with nothing but its own pinned file. */
    protected function getPackageSurfaceFile(): ?string
    {
        return null;
    }

    protected function findPurposeNames(): array
    {
        return $this->findPurposeNamesInDirectory(dirname(__DIR__, 3) . '/config/app/AI/Purposes');
    }
}
