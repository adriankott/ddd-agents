<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A delegation of the voice model that arrived WHILE a task of the call ran: one task runs at a time, so the session
 * worker keeps the delegation and starts it the moment the pending result is spoken. The request of the delegation
 * was spoken before it was queued, so the worker reads the caller's sentences back to {@see $queuedAtUnixMs}.
 */
class AgentVoiceQueuedDelegation extends ValueObject
{
    /** @var string The provider's id of the delegation: the result of its task is appended under this id */
    public string $delegationId = '';

    /** @var int When the first delegation behind the running task was queued, in Unix milliseconds */
    public int $queuedAtUnixMs = 0;

    public static function create(string $delegationId, int $queuedAtUnixMs): static
    {
        $agentVoiceQueuedDelegation = new static();
        $agentVoiceQueuedDelegation->delegationId = $delegationId;
        $agentVoiceQueuedDelegation->queuedAtUnixMs = $queuedAtUnixMs;

        return $agentVoiceQueuedDelegation;
    }
}
