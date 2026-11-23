<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Services\Drives\AgentDriveStoresService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures\InMemoryAgentDriveStore;
use DDD\Tests\Agents\Support\UnitTestCase;

/** A file argument resolves to the own drive, a mounted space or a reference; anything else is refused with the mounts named */
class AgentDriveStoresServicePathTest extends UnitTestCase
{
    public function testPathsResolveAgainstTheMountsOfTheCall(): void
    {
        $agentDriveStoresService = $this->createAgentDriveStoresService();
        $agentDriveMounts = $agentDriveStoresService->findMountsOfCall(AgentToolCallProvenance::forEvalHarness(null));

        $ownDrivePath = $agentDriveStoresService->resolveDrivePath($agentDriveMounts, '/workspace/reports/Plan.md');
        self::assertTrue($ownDrivePath->agentDriveMount->isOwnDrive);
        self::assertSame('reports/Plan.md', $ownDrivePath->pathInMount);

        $mountedPath = $agentDriveStoresService->resolveDrivePath($agentDriveMounts, 'drivespaces/library/Brand/');
        self::assertSame('library', $mountedPath->agentDriveMount->mountName);
        self::assertSame('Brand', $mountedPath->pathInMount);
        self::assertSame('drivespaces/library/Brand', $mountedPath->getWorkspacePath());

        $referencePath = $agentDriveStoresService->resolveDrivePath($agentDriveMounts, 'drive/812');
        self::assertSame('drive/812', $referencePath->reference);
        self::assertTrue($referencePath->agentDriveMount->isOwnDrive);

        self::assertSame("drive World \"Sommer\" · internal · rw\nmount drivespaces/library · internal · read-only", $agentDriveMounts->buildStateLines());
    }

    public function testAnUnknownMountIsRefusedWithTheMountsNamed(): void
    {
        $agentDriveStoresService = $this->createAgentDriveStoresService();
        $agentDriveMounts = $agentDriveStoresService->findMountsOfCall(AgentToolCallProvenance::forEvalHarness(null));

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Mounted: the own drive (paths without prefix), drivespaces/library.');
        $agentDriveStoresService->resolveDrivePath($agentDriveMounts, 'drivespaces/world-31/Reports');
    }

    public function testAPathLeavingItsFolderIsRefused(): void
    {
        $agentDriveStoresService = $this->createAgentDriveStoresService();
        $agentDriveMounts = $agentDriveStoresService->findMountsOfCall(AgentToolCallProvenance::forEvalHarness(null));

        $this->expectException(BadRequestException::class);
        $agentDriveStoresService->resolveDrivePath($agentDriveMounts, 'reports/../../etc/passwd');
    }

    protected function createAgentDriveStoresService(): AgentDriveStoresService
    {
        $agentDriveStoresService = new AgentDriveStoresService();
        $agentDriveStoresService->setAgentDriveStores([new InMemoryAgentDriveStore()]);
        return $agentDriveStoresService;
    }
}
