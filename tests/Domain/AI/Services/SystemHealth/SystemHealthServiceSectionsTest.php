<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\SystemHealth;

use DDD\Domain\AI\Entities\SystemHealth\SystemHealthSection;
use DDD\Domain\AI\Services\SystemHealth\SystemHealthService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The health report takes the application's sections through the port, after the package's; a section whose read
 * throws reads `unknown` with the masked first line of its reason, and the other sections are built.
 */
class SystemHealthServiceSectionsTest extends UnitTestCase
{
    public function testTheApplicationsSectionsFollowThePackagesAndAFailedSectionReadsUnknown(): void
    {
        $systemHealthService = new SystemHealthService();
        $systemHealthService->setSystemHealthSectionProviders([new SystemHealthSectionProviderDouble()]);

        self::assertSame([...SystemHealthSection::PACKAGE_SECTION_NAMES, 'ENVIRONMENT', 'BROKEN'], $systemHealthService->getSectionNames());
        $systemHealthReport = $systemHealthService->getHealth(['ENVIRONMENT', 'BROKEN']);

        self::assertSame(['ENVIRONMENT'], $systemHealthReport->getNamesOfSectionsThatNeedAttention());
        self::assertSame(['ENVIRONMENT: ATTENTION · database mode dev', '- writes are off'], $systemHealthReport->getSection('ENVIRONMENT')?->toLlmLines());
        $brokenSection = $systemHealthReport->getSection('BROKEN');
        self::assertSame(SystemHealthSection::STATE_UNKNOWN, $brokenSection?->state);
        self::assertStringContainsString('could not be read: connect to postgresql://[redacted] failed', (string)$brokenSection?->summary);
    }
}
