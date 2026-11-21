<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\NotNull;

/** Input of `updateMemory`: one stored memory corrected in place; only the given fields change. */
class AgentMemoryUpdateRequestDto extends RequestDto
{
    /** @var int|null The id of the memory (the `#<id>` of a searchMemory or listMemories line) */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotNull]
    public ?int $memoryId = null;

    /** @var string|null The new text; omit it to keep the text */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $content = null;

    /** @var string|null The new type: DIRECTIVE, FACT or SOLUTION; omit it to keep the type */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $memoryType = null;
}
