<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxSessionEvents;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The newest entries of a session log */
class AgentSandboxSessionEventsGetResponseDto extends RestResponseDto
{
    /** @var AgentSandboxSessionEvents The entries, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentSandboxSessionEvents $sessionEvents;
}
