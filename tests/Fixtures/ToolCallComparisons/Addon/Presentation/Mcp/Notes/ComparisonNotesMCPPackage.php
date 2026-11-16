<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\ToolCallComparisons\Addon\Presentation\Mcp\Notes;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeNoteRequestDto;

/**
 * Fixture: a module's notes package of the comparison tests, of the same short name as the application's. Its class
 * name sorts BEFORE the application's, so a lookup that ignores the order of the roots would pick it.
 */
class ComparisonNotesMCPPackage extends MCPPackage
{
    public static ?string $name = 'comparison.notes.module';

    public static ?string $description = 'Comparison notes package of a module';

    /** Records a note of the module. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    public function recordModuleComparisonNote(ProbeNoteRequestDto $requestDto): string
    {
        return 'recorded by the module';
    }
}
