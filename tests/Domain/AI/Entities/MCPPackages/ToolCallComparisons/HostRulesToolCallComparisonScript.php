<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonScript;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonToolCall;

/**
 * An application's script: it reads a key of its own (`spaceTemplate`) and adds two rules — the run's account must be
 * the comparison account, and a call may name no fixed `recordId`.
 */
class HostRulesToolCallComparisonScript extends ToolCallComparisonScript
{
    /** @var int The only account the application runs a comparison as */
    public const int COMPARISON_ACCOUNT_ID = 501;

    /** The template the application builds the run's space from. */
    public string $spaceTemplate = '';

    protected function readHostScriptFields(): void
    {
        $this->spaceTemplate = (string)($this->scriptFields['spaceTemplate'] ?? '');
    }

    protected function findHostValidationErrors(int $runningAccountId): array
    {
        return $runningAccountId === static::COMPARISON_ACCOUNT_ID ? [] : ["the run would run as account {$runningAccountId}"];
    }

    protected function findHostToolCallErrors(string $callLabel, ToolCallComparisonToolCall $toolCall): array
    {
        return array_key_exists('recordId', $toolCall->getArguments()) ? ["{$callLabel}: a fixed recordId"] : [];
    }
}
