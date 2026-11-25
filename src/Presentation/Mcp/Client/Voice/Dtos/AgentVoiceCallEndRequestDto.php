<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Input of `endVoiceCall`. The agent's end has no REST action whose request it could share: the `hangUp` route names
 * the call in its path and carries a client's end reason, while the agent's end is decided in
 * {@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallEndsService}.
 */
class AgentVoiceCallEndRequestDto extends RequestDto
{
    /** @var int|null The call to end (an id from getVoiceCalls). Leave it out to end the call of THIS conversation: the one you are on */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $callId = null;
}
