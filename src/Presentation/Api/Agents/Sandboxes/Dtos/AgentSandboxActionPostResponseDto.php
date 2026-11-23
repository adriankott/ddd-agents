<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionResult;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What an action on a workspace did */
class AgentSandboxActionPostResponseDto extends RestResponseDto
{
    /** @var AgentSandboxActionResult The action, its outcome and its audit entry */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentSandboxActionResult $actionResult;
}
