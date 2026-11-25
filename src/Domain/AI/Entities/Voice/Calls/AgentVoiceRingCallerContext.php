<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the application tells the ringing client about who calls: the `callerContext` object of the ring payload
 * ({@see AgentVoiceRingPayload}). Empty in the package, so the ring carries `{}`.
 *
 * An application's subclass at the same path declares its fields (which of the account's agent spaces rings, a display
 * name) and names them in {@see toPayloadArray()}; its ring service fills them in
 * {@see \DDD\Domain\AI\Services\Voice\AgentVoiceRingService::buildCallerContext()}.
 */
class AgentVoiceRingCallerContext extends ValueObject
{
    /**
     * The fields as the JSON object a client reads, by the name the client reads them under. This is the one place
     * the object is rendered.
     *
     * @return array<string, mixed>
     */
    public function toPayloadArray(): array
    {
        return [];
    }
}
