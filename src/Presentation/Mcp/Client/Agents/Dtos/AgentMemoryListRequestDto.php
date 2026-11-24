<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `listMemories`: every current memory, newest first, no query. The account is the one the call acts for. */
class AgentMemoryListRequestDto extends RequestDto
{
    /** @var int|null The space whose business memories are listed besides the account's own; omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;

    /** @var string|null Only one type: DIRECTIVE, FACT or SOLUTION */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $memoryType = null;

    /** @var int|null The most memories returned, newest first (default 50, at most 200) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
