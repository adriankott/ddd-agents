<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\NotNull;

/** Input of `forget`: one stored memory that is no longer recalled (it stays as history). */
class AgentMemoryForgetRequestDto extends RequestDto
{
    /** @var int|null The id of the memory (the `#<id>` of a searchMemory or listMemories line) */
    #[Parameter(in: Parameter::BODY, required: true)]
    #[NotNull]
    public ?int $memoryId = null;
}
