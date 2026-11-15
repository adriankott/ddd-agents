<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\MCPPackages\MCPServerProvidedInput;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;

/**
 * The tool executor of a runner test: every call is recorded with its arguments, the tool block the process held while
 * it ran and its provenance; the answer is the next scripted result of the tool (a COMPLETED empty JSON object when
 * none is scripted). No tool runs.
 */
class MCPToolExecutorServiceScriptedDouble extends MCPToolExecutorService
{
    /** @var array<string, ToolResult[]> The scripted answers per tool name, in the order they are given */
    public array $scriptedResultsByToolName = [];

    /** @var array<int, array{toolName: string, arguments: array<string, mixed>, blockedToolNames: string[], blockedToolSimulatedErrors: array<string, string>, everyWriteToolBlocked: bool, provenance: MCPServerProvidedInput|null}> */
    public array $executedCalls = [];

    /** Scripts the next answer of `$toolName`. */
    public function scriptResult(string $toolName, string $content, bool $isError = false): void
    {
        $this->scriptedResultsByToolName[$toolName][] = new ToolResult($content, $isError);
    }

    public function execute(string $toolName, ?array $arguments, array $packageClasses, ?array $logContext = null, ?MCPServerProvidedInput $serverProvidedInput = null): ToolResult
    {
        $this->executedCalls[] = [
            'toolName' => $toolName,
            'arguments' => $arguments ?? [],
            'blockedToolNames' => static::$blockedToolNames,
            'blockedToolSimulatedErrors' => static::$blockedToolSimulatedErrors,
            'everyWriteToolBlocked' => static::$everyWriteToolBlocked,
            'provenance' => $serverProvidedInput,
        ];
        if (($this->scriptedResultsByToolName[$toolName] ?? []) === []) {
            return new ToolResult('{}');
        }
        return array_shift($this->scriptedResultsByToolName[$toolName]);
    }

    /** @return string[] The executed tool names, in order */
    public function getExecutedToolNames(): array
    {
        return array_map(static fn (array $executedCall): string => $executedCall['toolName'], $this->executedCalls);
    }
}
