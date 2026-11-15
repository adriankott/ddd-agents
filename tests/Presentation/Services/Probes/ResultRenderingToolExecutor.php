<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;

/** The executor with the rendering of a tool's result for the model callable directly. */
class ResultRenderingToolExecutor extends MCPToolExecutorService
{
    public function callRenderToolResultForModel(mixed $result, string $toolName, bool $explicitStructured): MCPToolResponse
    {
        return $this->renderToolResultForModel($result, $toolName, $explicitStructured);
    }
}
