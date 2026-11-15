<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\MCPPackages\MCPServerProvidedInput;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use Throwable;

/**
 * A tool executor that runs no tool: it records each call (tool, arguments, candidate packages, what the server
 * provided, the account authenticated during the call) and answers the result a test scripted, or throws the error a
 * test hands it.
 */
class RecordingToolExecutorDouble extends MCPToolExecutorService
{
    /** @var ToolResult The result every call answers */
    public ToolResult $scriptedToolResult;

    public ?string $calledToolName = null;

    /** @var array<array-key, mixed>|null */
    public ?array $calledToolArguments = null;

    /** @var string[] */
    public array $calledPackageClassNames = [];

    public ?MCPServerProvidedInput $calledServerProvidedInput = null;

    public ?int $accountIdDuringCall = null;

    public int $callCount = 0;

    /** @var Throwable|null What every call throws; null = the scripted result */
    public ?Throwable $errorToThrow = null;

    public function __construct()
    {
        $this->scriptedToolResult = new ToolResult('{"documents":[]}');
    }

    public function execute(string $toolName, ?array $arguments, array $packageClasses, ?array $logContext = null, ?MCPServerProvidedInput $serverProvidedInput = null): ToolResult
    {
        $this->callCount++;
        $this->calledToolName = $toolName;
        $this->calledToolArguments = $arguments;
        $this->calledPackageClassNames = $packageClasses;
        $this->calledServerProvidedInput = $serverProvidedInput;
        $authenticatedAccount = AuthService::instance()->getAccount();
        $this->accountIdDuringCall = $authenticatedAccount !== null && isset($authenticatedAccount->id) ? (int)$authenticatedAccount->id : null;
        if ($this->errorToThrow !== null) {
            throw $this->errorToThrow;
        }
        return $this->scriptedToolResult;
    }
}
