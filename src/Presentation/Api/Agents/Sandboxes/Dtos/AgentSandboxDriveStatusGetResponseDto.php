<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Sandboxes\Dtos;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxDriveStatus;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What the workspace holds that the drive does not have yet */
class AgentSandboxDriveStatusGetResponseDto extends RestResponseDto
{
    /** @var AgentSandboxDriveStatus The unsaved changes */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentSandboxDriveStatus $driveStatus;
}
