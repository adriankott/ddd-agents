<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of `searchMemory`: the stored memories nearest to a topic. The account is the one the call acts for. */
class AgentMemorySearchRequestDto extends RequestDto
{
    /** @var string What to recall: a topic, a preference, a fact about the owner or the business */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $query = '';

    /** @var int|null The space whose business memories are searched besides the account's own; omit it for the space this conversation works for */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;

    /** @var string|null Only one type: DIRECTIVE, FACT or SOLUTION */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $memoryType = null;

    /** @var int|null The most memories returned (default 10, at most 50) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
