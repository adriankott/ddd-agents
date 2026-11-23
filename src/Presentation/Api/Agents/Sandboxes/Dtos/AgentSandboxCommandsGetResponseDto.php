<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxCommands;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The last commands of a workspace session */
class AgentSandboxCommandsGetResponseDto extends RestResponseDto
{
    /** @var AgentSandboxCommands The commands, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentSandboxCommands $commands;
}
