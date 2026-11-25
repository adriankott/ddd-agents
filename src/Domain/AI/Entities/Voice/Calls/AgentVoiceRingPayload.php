<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The payload of the ring message of ONE call: what a client needs to show the ring and to answer it (`answer` with
 * the token, then the session open with the call id), and nothing of the conversation's content.
 * {@see \DDD\Domain\AI\Services\Voice\AgentVoiceRingService::buildRingPayload()} fills it; {@see toPayloadJson()} is
 * the one place the document a client reads is rendered.
 */
class AgentVoiceRingPayload extends ValueObject
{
    /** @var int The call that rings */
    public int $callId = 0;

    /** @var int|null The conversation of the call; null for a call without one */
    public ?int $conversationId = null;

    /** @var string The token the client answers the ring with; opaque to the client */
    public string $answerToken = '';

    /** @var int The Unix time the ring ends at */
    public int $ringDeadlineTimestamp = 0;

    /** @var string The text KEY the incoming call shows as its caller line, never a sentence */
    public string $callerLabelKey = '';

    /** @var AgentVoiceRingCallerContext|null What the application tells the ringing client about who calls */
    public ?AgentVoiceRingCallerContext $callerContext = null;

    public static function create(
        int $callId,
        ?int $conversationId,
        string $answerToken,
        int $ringDeadlineTimestamp,
        string $callerLabelKey,
        AgentVoiceRingCallerContext $callerContext
    ): static {
        $agentVoiceRingPayload = new static();
        $agentVoiceRingPayload->callId = $callId;
        $agentVoiceRingPayload->conversationId = $conversationId;
        $agentVoiceRingPayload->answerToken = $answerToken;
        $agentVoiceRingPayload->ringDeadlineTimestamp = $ringDeadlineTimestamp;
        $agentVoiceRingPayload->callerLabelKey = $callerLabelKey;
        $agentVoiceRingPayload->callerContext = $callerContext;

        return $agentVoiceRingPayload;
    }

    /**
     * The payload as the JSON document of the ring message: `callId`, `conversationId`, `answerToken`,
     * `ringExpiresAt` (ISO 8601, UTC), `ringExpiresAtUnixMs`, `callerLabelKey` and `callerContext`, which is always a
     * JSON object, `{}` when the application says nothing.
     */
    public function toPayloadJson(): string
    {
        return json_encode(
            [
                'callId' => $this->callId,
                'conversationId' => $this->conversationId,
                'answerToken' => $this->answerToken,
                'ringExpiresAt' => gmdate('Y-m-d\TH:i:s.000\Z', $this->ringDeadlineTimestamp),
                'ringExpiresAtUnixMs' => $this->ringDeadlineTimestamp * 1000,
                'callerLabelKey' => $this->callerLabelKey,
                'callerContext' => (object)($this->callerContext?->toPayloadArray() ?? []),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
