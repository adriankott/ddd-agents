<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The request of the owner who asks to be called ("call me"). An application whose `ring` route names the agent space
 * (in its path or its body) declares that field in a subclass of this class; the route resolves the space and hands it
 * to `AgentVoiceSessionsTrait::buildVoiceCallRingingResponse()`.
 */
class AgentVoiceSessionRingRequestDto extends RequestDto
{
    /** @var string|null What the agent works on in the call; null or empty = the agent opens by asking what it can help with */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 2000)]
    public ?string $task = null;

    /**
     * @var int|null An existing conversation the call continues: the spoken turns then land in that thread. Null
     * creates a new conversation for the call.
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $conversationId = null;
}
