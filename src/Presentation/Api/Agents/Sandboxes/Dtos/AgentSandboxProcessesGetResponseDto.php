<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxProcesses;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The processes and background jobs of a workspace session */
class AgentSandboxProcessesGetResponseDto extends RestResponseDto
{
    /** @var AgentSandboxProcesses The processes */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentSandboxProcesses $processes;
}
