<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** One client-side event of a call, reported by the client: the audio never passes the server, so only the client sees it. */
class AgentVoiceSessionDeviceStatusRequestDto extends RequestDto
{
    /** @var int The id of the call the client-side event belongs to */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $callId;

    /**
     * The client-side transition: `PERMISSION_DENIED`, `OFFER_CREATED`, `MEDIA_CONNECTED`, `MEDIA_FAILED`,
     * `NETWORK_CHANGED`, `INTERRUPTED`, `RESUMED`, `ENDED_BY_USER`, and the start-up timeline `CALL_TAPPED`,
     * `MIC_GRANTED`, `ICE_GATHERING_DONE`, `OPEN_SENT`, `ANSWER_APPLIED`, `AUDIO_SESSION_ACTIVATED`,
     * `FIRST_AUDIO_SENT`, `SESSION_STARTED_SEEN`, `FIRST_AUDIO_PLAYED`.
     *
     * @var string
     */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $status;

    /** @var string|null The negotiated ICE candidate type (`host`, `srflx`, `relay`) when known */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $iceCandidateType = null;

    /** @var int|null Media round-trip time in milliseconds when known */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $roundTripTimeMs = null;

    /** @var string|null Short free-text reason on the failure cases */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $detail = null;

    /**
     * @var int|null The client's wall-clock time of the transition in Unix milliseconds: the start-up timeline
     * (`CALL_TAPPED` … `FIRST_AUDIO_PLAYED`) is measured on the client, not when the request arrives
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $occurredAtUnixMs = null;
}
