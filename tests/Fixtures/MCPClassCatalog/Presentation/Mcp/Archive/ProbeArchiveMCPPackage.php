<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\MCPClassCatalog\Presentation\Mcp\Archive;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;

/** Fixture: a second package in another folder, listed after the first by class name. */
class ProbeArchiveMCPPackage extends MCPPackage
{
    public static ?string $name = 'probe.archive';

    public static ?string $description = 'Probe archive package';
}
