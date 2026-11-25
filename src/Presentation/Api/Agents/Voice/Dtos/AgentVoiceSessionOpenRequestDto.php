<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The request that opens a voice session. An application whose `open` route names the agent space (in its path or its
 * body) declares that field in a subclass of this class; the route resolves the space and hands it to
 * `AgentVoiceSessionsTrait::buildVoiceSessionOpenedResponse()`.
 */
class AgentVoiceSessionOpenRequestDto extends RequestDto
{
    /**
     * The client's complete WebRTC SDP offer (`pc.localDescription.sdp`), sent only AFTER ICE gathering finished:
     * there is no trickle-ICE route, this one exchange is the whole negotiation.
     *
     * @var string
     */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $sdpOffer;

    /**
     * The conversation this call continues. A client sends it whenever the owner is in a chat: the spoken turns then
     * land in that thread. Null = a new conversation is created for the call. Ignored when `callId` is sent: an
     * answered call already carries its conversation.
     *
     * @var int|null
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $conversationId = null;

    /**
     * The ringing call this client WON with `answer`: the session then attaches to that call instead of creating one.
     * Null = the owner calls the agent.
     *
     * @var int|null
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $callId = null;
}
