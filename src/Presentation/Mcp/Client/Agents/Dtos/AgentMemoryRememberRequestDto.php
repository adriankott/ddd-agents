<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Input of `remember`: one durable memory the owner just stated. A memory about the business belongs to the space, a
 * memory about the owner as a person (`subject` USER) to the account.
 */
class AgentMemoryRememberRequestDto extends RequestDto
{
    /** @var string The memory text: one standing preference for the work with the assistant, or one fact */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $content = '';

    /** @var string|null DIRECTIVE (a rule for how the assistant works for the owner), FACT or SOLUTION; default FACT */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $memoryType = null;

    /** @var string|null USER (about the owner as a person: holds in every space) or BUSINESS (about the business: this space); default BUSINESS */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $subject = null;

    /** @var int|null The space a memory about the business belongs to; omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;

    /** @var string|null USER_STATED (default: the owner said it) or AGENT_INFERRED */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $source = null;
}
