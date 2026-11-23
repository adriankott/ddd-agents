<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One directory of a workspace */
class AgentSandboxFilesGetRequestDto extends RequestDto
{
    /** @var string The workspace's key */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $workspaceKey = '';

    /** @var string|null The directory relative to the workspace root; empty = the root */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $path = null;
}
