<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Domain\Base\Entities\ValueObject;

/** A domain object returned by a tool, with a platform note. */
class ProbeNoteValueObject extends ValueObject implements MCPToolResponsePlatformNoteProvider
{
    public string $label = 'probe';

    public function buildPlatformNoteForModel(): ?string
    {
        return 'Read the label as written.';
    }
}
