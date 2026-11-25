<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The acknowledgement of the lifecycle writes a client does not wait on (`decline`, `hangUp`, `deviceStatus`): the
 * client reads no more than that the request arrived.
 */
class AgentVoiceSessionAcknowledgeResponseDto extends RestResponseDto
{
    /** @var bool Always true: the write was applied, or was applied already (the actions are idempotent) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $acknowledged = true;
}
