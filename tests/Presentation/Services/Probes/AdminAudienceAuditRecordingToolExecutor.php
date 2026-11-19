<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPServerProvidedInput;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The executor with the call itself answered by a fixed response and both audit points observable: an application's
 * override of the earlier point ({@see recordAdminAudienceToolCall()}) and the conversation id the new point reads.
 */
class AdminAudienceAuditRecordingToolExecutor extends MCPToolExecutorService
{
    /** @var string[] the tool names the earlier audit point recorded */
    public array $toolNamesRecordedByTheEarlierPoint = [];

    /** @var array<int, int|null> the conversation id of each call the new audit point saw */
    public array $conversationIdsOfAuditedCalls = [];

    protected function callToolWithoutAudit(
        string $toolName,
        ?array $arguments,
        array $candidatePackageClasses,
        ?Request $requestContext = null,
        bool $errorsAsJson = false,
        ?MCPServerProvidedInput $serverProvidedInput = null
    ): MCPToolResponse {
        return MCPToolResponse::success('done');
    }

    protected function recordAdminAudienceToolCallWithCallContext(
        string $toolName,
        array $arguments,
        array $candidatePackageClasses,
        int $durationMs,
        ?MCPToolResponse $mcpToolResponse,
        ?string $exceptionClass,
        ?MCPServerProvidedInput $serverProvidedInput
    ): void {
        $this->conversationIdsOfAuditedCalls[] = $this->findConversationIdOfCallContext($serverProvidedInput);
        parent::recordAdminAudienceToolCallWithCallContext($toolName, $arguments, $candidatePackageClasses, $durationMs, $mcpToolResponse, $exceptionClass, $serverProvidedInput);
    }

    protected function recordAdminAudienceToolCall(
        string $toolName,
        array $arguments,
        array $candidatePackageClasses,
        int $durationMs,
        ?MCPToolResponse $mcpToolResponse,
        ?string $exceptionClass
    ): void {
        $this->toolNamesRecordedByTheEarlierPoint[] = $toolName;
    }
}
