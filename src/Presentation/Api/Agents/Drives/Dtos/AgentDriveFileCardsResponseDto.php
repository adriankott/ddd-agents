<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Drives\Dtos;

use DDD\Domain\AI\Entities\Drives\AgentDriveFiles;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The cards of a `DriveFiles` widget: the files the viewer may read, facts only */
class AgentDriveFileCardsResponseDto extends RestResponseDto
{
    /** @var AgentDriveFiles The files */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentDriveFiles $files;
}
