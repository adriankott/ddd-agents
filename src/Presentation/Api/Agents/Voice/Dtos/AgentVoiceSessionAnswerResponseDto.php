<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer to the client that won a ringing call. */
class AgentVoiceSessionAnswerResponseDto extends RestResponseDto
{
    /**
     * True: this client won the answer of the call. A client that lost never reads this answer: it gets
     * `409 already_answered` or `410 ring_expired` instead. The winner goes on to `open` with the call id.
     *
     * @var bool
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $won = true;

    /**
     * The conversation the call runs on: the thread the spoken turns land in.
     *
     * @var int|null
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?int $conversationId = null;
}
