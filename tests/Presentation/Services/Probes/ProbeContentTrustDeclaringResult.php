<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolResultDeclaresContentTrust;
use RuntimeException;

/** A tool result that declares its trust per call; null makes it throw (a result that cannot decide). */
class ProbeContentTrustDeclaringResult implements MCPToolResultDeclaresContentTrust
{
    public function __construct(public ?bool $holdsOnlyPlatformText)
    {
    }

    public function isTrustedContent(): bool
    {
        if ($this->holdsOnlyPlatformText === null) {
            throw new RuntimeException('cannot decide');
        }
        return $this->holdsOnlyPlatformText;
    }
}
