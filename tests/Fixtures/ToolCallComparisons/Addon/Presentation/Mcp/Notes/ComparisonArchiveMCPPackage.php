<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\ToolCallComparisons\Addon\Presentation\Mcp\Notes;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeNoteRequestDto;

/** Fixture: a package only the module has; a script finds it in the module's root. */
class ComparisonArchiveMCPPackage extends MCPPackage
{
    public static ?string $name = 'comparison.archive';

    public static ?string $description = 'Comparison archive package of a module';

    /** Reads the archived notes. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    public function readComparisonArchive(ProbeNoteRequestDto $requestDto): string
    {
        return 'archive';
    }
}
