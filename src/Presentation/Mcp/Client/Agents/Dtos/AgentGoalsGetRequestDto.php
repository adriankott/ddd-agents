<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `getGoals`: the goal register of the account and the space. */
class AgentGoalsGetRequestDto extends RequestDto
{
    /** @var bool|null true: also the achieved, dropped and paused goals; default only the live ones (active, proposed, blocked) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?bool $includeFinished = null;

    /** @var int|null The space whose goals to read (with the account's own); omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;
}
