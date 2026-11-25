<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Services\Installation\AgentDocumentToolsInstallationCheckService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The package's own added checks: the picture store's object store must be configured while it is on; the bound document
 * tools need poppler (and tesseract for OCR) on the PATH.
 */
class AgentFeatureInstallationChecksTest extends UnitTestCase
{
    public function testAnUnconfiguredPictureObjectStoreIsAWarning(): void
    {
        $toolImageStoreCheck = new AgentToolImageStoreInstallationCheckWithStoresDouble();
        $findings = $toolImageStoreCheck->findInstallationFindings();
        self::assertSame(['agentToolImages'], $findings->getSubjects());
        self::assertSame(0, $findings->countBlockingFindings());

        $toolImageStoreCheck->configuredObjectStorageNames = ['agentToolImages'];
        self::assertSame(0, $toolImageStoreCheck->findInstallationFindings()->count());
    }

    public function testEveryMissingProgramOfTheBoundDocumentToolsIsAWarning(): void
    {
        $documentToolsCheck = new AgentDocumentToolsInstallationCheckWithoutProgramsDouble();
        $documentToolsCheck->programsOnPath = ['pdftotext', 'pdfinfo'];

        self::assertSame(
            [AgentDocumentToolsInstallationCheckService::PDF_PAGE_RENDER_PROGRAM, AgentDocumentToolsInstallationCheckService::OCR_PROGRAM],
            $documentToolsCheck->findInstallationFindings()->getSubjects()
        );
    }
}
