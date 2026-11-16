<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Presentation\Mcp\Notes;

use DDD\Domain\AI\Entities\MCPPackages\MCPBundle;

/** Fixture: the application's bundle that lists its notes package of the comparison tests. */
class ComparisonNotesMCPBundle extends MCPBundle
{
    public const array INCLUDED_PACKAGES = [ComparisonNotesMCPPackage::class];

    public static ?string $name = 'comparison-notes';

    public static ?string $description = 'Comparison notes bundle';
}
