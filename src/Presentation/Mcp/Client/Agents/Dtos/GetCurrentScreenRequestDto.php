<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** What getCurrentScreen reads. */
class GetCurrentScreenRequestDto extends RequestDto
{
    /** @var string|null One reference of the last read to read in full (a long text, all columns of a table), e.g. f.description */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $focus = null;
}
