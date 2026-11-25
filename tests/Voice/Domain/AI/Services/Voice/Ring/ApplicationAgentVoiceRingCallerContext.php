<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceRingCallerContext;

/**
 * An application's caller context, as it declares one at the path of the package's class: two fields, named in the
 * ring payload by {@see toPayloadArray()} with names of the application's own (not the field names).
 */
class ApplicationAgentVoiceRingCallerContext extends AgentVoiceRingCallerContext
{
    public ?int $agentSpaceId = null;

    public string $displayName = '';

    public function toPayloadArray(): array
    {
        return ['ringingSpaceId' => $this->agentSpaceId, 'ringingSpaceName' => $this->displayName];
    }
}
