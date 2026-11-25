<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The request of a client that ends a call. */
class AgentVoiceSessionHangUpRequestDto extends RequestDto
{
    /** @var int The id of the call ({@see \DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall::$id}) to hang up */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $callId;

    /**
     * Why the client ended the call, free-form for diagnostics (`USER_ENDED`, `MEDIA_FAILED`, `APP_BACKGROUNDED`).
     * `MEDIA_FAILED` ends the call as FAILED, everything else as COMPLETED.
     *
     * @var string|null
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $reason = null;
}
