<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxWorkspaces;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Every agent workspace the host knows */
class AgentSandboxWorkspacesGetResponseDto extends RestResponseDto
{
    /** @var bool False when the application set up no sandbox administration (the list is then empty) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $isConfigured = false;

    /** @var AgentSandboxWorkspaces The workspaces, running and offloaded */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentSandboxWorkspaces $workspaces;
}
