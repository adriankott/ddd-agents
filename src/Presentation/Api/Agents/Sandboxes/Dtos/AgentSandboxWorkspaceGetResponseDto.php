<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxWorkspace;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One agent workspace */
class AgentSandboxWorkspaceGetResponseDto extends RestResponseDto
{
    /** @var AgentSandboxWorkspace The workspace */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentSandboxWorkspace $workspace;
}
