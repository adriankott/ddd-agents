<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The background job to kill */
class AgentSandboxJobKillPostRequestDto extends RequestDto
{
    /** @var string The job's id (from the process list) */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $jobId = '';
}
