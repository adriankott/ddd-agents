<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Testing\Surface;

use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOwnerFacingSubject;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolShowsNoActivityLine;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;

/** Tool methods with declarations of every shape the surface document holds; never called. */
class AgentSurfaceDeclarationsToolsFixture
{
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE, isOutwardAction: true)]
    #[MCPToolOwnerFacingSubject('The weekly report')]
    #[MCPToolShowsNoActivityLine]
    #[MCPToolSummary('Sends the weekly report to the owner of the account. The text is longer than a short value of the document may be, so that it is held as one token and not as its words.')]
    public function sendWeeklyReport(): void
    {
    }

    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('one read')]
    public function getWeeklyReport(): void
    {
    }

    #[MCPTool]
    public function toolWithoutDeclarations(): void
    {
    }
}
