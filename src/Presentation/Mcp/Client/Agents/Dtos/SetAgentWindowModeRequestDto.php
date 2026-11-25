<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The view mode setAgentWindowMode asks for. */
class SetAgentWindowModeRequestDto extends RequestDto
{
    /** @var string|null A mode the application's agent window knows, e.g. `aside`, `docked`, `expanded`, `minimized`; `restore` returns to the user's own mode */
    #[Parameter(in: Parameter::BODY, required: true)]
    public ?string $mode = null;
}
