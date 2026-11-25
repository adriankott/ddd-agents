<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The request for the state of one call. */
class AgentVoiceSessionCallStateGetRequestDto extends RequestDto
{
    /** @var int The id of the call ({@see \DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall::$id}) whose state is read */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $callId;
}
