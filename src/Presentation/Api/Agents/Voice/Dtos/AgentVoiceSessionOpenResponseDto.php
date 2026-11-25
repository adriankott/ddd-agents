<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer to an opened voice session. */
class AgentVoiceSessionOpenResponseDto extends RestResponseDto
{
    /**
     * The call of the session. Its `id` is the call id every later lifecycle request (`hangUp`, `deviceStatus`, the
     * call state) names.
     *
     * @var AgentVoiceCall|null
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?AgentVoiceCall $agentVoiceCall = null;

    /**
     * The provider's SDP answer. The client applies it with `pc.setRemoteDescription({type:'answer', sdp})`; the audio
     * then flows between the client and the provider and never passes the server.
     *
     * @var string|null
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?string $sdpAnswer = null;

    /**
     * The conversation the call runs on: the one the request named, or the newly created one. The chat shows the
     * spoken turns in this thread as the transcript arrives.
     *
     * @var int|null
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?int $conversationId = null;
}
