<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPServerProvidedInput;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The executor with the tool call answered by a fixed response: what the executor makes of a response for the agent
 * loop is observable without a tool, and the refusal texts it builds can be asked for directly.
 */
class StubbedResponseToolExecutor extends MCPToolExecutorService
{
    /** The response every tool call is answered with */
    public ?MCPToolResponse $stubbedResponse = null;

    public function callTool(
        string $toolName,
        ?array $arguments,
        array $candidatePackageClasses,
        ?Request $requestContext = null,
        bool $errorsAsJson = false,
        ?MCPServerProvidedInput $serverProvidedInput = null
    ): MCPToolResponse {
        return $this->stubbedResponse ?? MCPToolResponse::success('done');
    }

    /**
     * The tool result the agent loop receives for one call ({@see executeTool()}).
     *
     * @param array<array-key, mixed> $arguments
     */
    public function callExecuteTool(string $toolName, array $arguments): ToolResult
    {
        return $this->executeTool($toolName, $arguments, []);
    }

    /** The refusal of a call that needs a mandate the platform cannot obtain here (a sub-agent, a scheduled run, no dialogue). */
    public function callBuildMandateRequiredRefusal(string $toolName, string $actionClass, string $actionClassDescription): MCPToolResponse
    {
        return $this->buildMandateRequiredRefusal($toolName, $actionClass, $actionClassDescription);
    }
}
