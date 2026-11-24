<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\SystemHealth;

use DDD\Domain\AI\Entities\SystemHealth\SystemHealthSection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

/**
 * The port through which an application adds its own sections to the health report of `System.Health` (its database
 * mode and release, a failed-request log, a store of its own). Every service implementing it is collected by its tag;
 * the package's sections (workers, crons, issues) come first, then the providers' in container order.
 *
 * A section reads only, names no host, process id, environment value or connection string, and builds within a few
 * seconds: the report waits for it.
 */
#[AutoconfigureTag(SystemHealthSectionProvider::TAG)]
interface SystemHealthSectionProvider
{
    public const string TAG = 'ai.system_health_section_provider';

    /** @return string[] The names of the sections this provider builds ({@see SystemHealthSection::NAME_PATTERN}) */
    public function getSectionNames(): array;

    /**
     * The section `$sectionName` (one of {@see getSectionNames()}).
     *
     * @throws Throwable a failure is shown as the section's state `unknown`, the other sections are built
     */
    public function buildSection(string $sectionName): SystemHealthSection;
}
