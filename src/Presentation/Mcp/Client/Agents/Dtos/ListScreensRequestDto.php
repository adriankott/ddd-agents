<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Which screens listScreens lists. */
class ListScreensRequestDto extends RequestDto
{
    /** @var string|null Words of the screen's name, group or key; each must match. Empty lists every screen */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $searchText = null;
}
