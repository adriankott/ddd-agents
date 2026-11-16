<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

/** A queued message that carries a token minted at random in every run, beside a plain label. */
class ToolCallComparisonProbeMessage
{
    public function __construct(
        public string $lockToken,
        public string $label
    ) {
    }
}
