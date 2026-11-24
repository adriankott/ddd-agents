<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which parts of the platform health to report. */
class GetSystemHealthRequestDto extends RequestDto
{
    /** @var string[]|null Parts to report, default all: WORKERS, CRONS, ISSUES and the sections the application adds */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?array $sections = null;
}
