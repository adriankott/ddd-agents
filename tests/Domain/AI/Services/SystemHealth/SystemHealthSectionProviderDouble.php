<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\SystemHealth;

use DDD\Domain\AI\Entities\SystemHealth\SystemHealthSection;
use DDD\Domain\AI\Services\SystemHealth\SystemHealthSectionProvider;
use RuntimeException;

/** An application's two sections: one that needs attention, one whose read throws with a secret in its message. */
class SystemHealthSectionProviderDouble implements SystemHealthSectionProvider
{
    public function getSectionNames(): array
    {
        return ['ENVIRONMENT', 'BROKEN', 'lower-case is no name'];
    }

    public function buildSection(string $sectionName): SystemHealthSection
    {
        if ($sectionName === 'BROKEN') {
            throw new RuntimeException("connect to postgresql://app:secret@db.internal/app failed\nstack line");
        }
        $systemHealthSection = new SystemHealthSection($sectionName);
        $systemHealthSection->summary = 'database mode dev';
        $systemHealthSection->addFinding('writes are off');
        return $systemHealthSection;
    }
}
