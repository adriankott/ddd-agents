<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The request for one call of the admin list. */
class AdminAgentVoiceCallGetRequestDto extends RequestDto
{
    /** @var int The id of the call ({@see \DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall::$id}) */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $callId;
}
