<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer to a ring the owner asked for. */
class AgentVoiceSessionRingResponseDto extends RestResponseDto
{
    /** @var int|null The call that rings now: the id the answering client names with its answer token */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $callId = null;

    /** @var int|null The conversation the call runs on; its transcript fills while the call runs */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $conversationId = null;

    /** @var string|null When the ringing stops by itself, UTC */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $ringExpiresAt = null;

    /**
     * @var bool Whether the ring was delivered to a client at all. FALSE means the call is already over and nothing
     * rings, which looks the same as "nobody picked up" unless it is said.
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $anyDeviceReached = true;
}
