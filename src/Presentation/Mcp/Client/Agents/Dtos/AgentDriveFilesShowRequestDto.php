<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `showDriveFiles`: files shown to the owner as cards in the chat. */
class AgentDriveFilesShowRequestDto extends RequestDto
{
    /** @var string[] The files: workspace paths or references `drive/<id>`; at most 20, committed files only */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $paths = [];

    /** @var string|null A headline above the cards */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $title = null;
}
