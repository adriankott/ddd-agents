<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The request of a client that answers a ringing call. */
class AgentVoiceSessionAnswerRequestDto extends RequestDto
{
    /** @var int The id of the ringing call: `callId` of the ring message */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $callId;

    /**
     * The answer token of the ring message (`answerToken`): bound to this call and valid until the ring expires.
     * Opaque to the client.
     *
     * @var string
     */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $answerToken;
}
