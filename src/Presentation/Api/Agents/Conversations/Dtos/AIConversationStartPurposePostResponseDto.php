<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The conversation a purposed start opened or reopened; the client opens it by its id.
 */
class AIConversationStartPurposePostResponseDto extends RestResponseDto
{
    /** @var int The purposed conversation */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $aiConversationId;

    /** @var bool Whether the open conversation of the same space, purpose (and subject) was reopened instead of a new one */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $resumedExistingConversation = false;

    /** @var bool Whether the request's prefill reached the kickoff (false on a reopen and without a prefill) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $prefillDelivered = false;
}
