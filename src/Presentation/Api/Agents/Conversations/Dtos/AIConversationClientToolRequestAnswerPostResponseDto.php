<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The answer was stored; the waiting tool reads it.
 */
class AIConversationClientToolRequestAnswerPostResponseDto extends RestResponseDto
{
    /** @var int The id of the answered request */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $requestId;

    /** @var bool Always true: a request that no longer waits answers 409 */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $accepted = true;
}
