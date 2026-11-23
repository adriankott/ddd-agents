<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntries;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The audit of the actions on a workspace */
class AgentSandboxActionLogEntriesGetResponseDto extends RestResponseDto
{
    /** @var AgentSandboxActionLogEntries The entries, newest first */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentSandboxActionLogEntries $actionLogEntries;
}
