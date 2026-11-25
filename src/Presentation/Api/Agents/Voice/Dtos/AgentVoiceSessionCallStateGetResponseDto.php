<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Whether a call is over, and why. A client reads it when it has a reason to believe the server ended the call: the
 * change signal of the call's conversation fired, the provider's data channel closed, or the media connection dropped.
 * `endedAt` null = the call still rings or runs on the server.
 */
class AgentVoiceSessionCallStateGetResponseDto extends RestResponseDto
{
    /** @var int The id of the call */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $callId;

    /** @var string|null When the call ended (UTC, `Y-m-d\TH:i:s\Z`); null while it runs or still rings */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $endedAt = null;

    /**
     * @var string|null Why it ended: `ENDED_BY_AGENT` (the agent hung up on the owner's request), `LIMIT_REACHED`
     * (time, daily cap or AI budget), `FAILED`, `COMPLETED`, `DECLINED`, `EXPIRED`, `NOT_ANSWERED`, `CANCELED`, …
     * ({@see \DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall} `END_REASON_*`); null while the call runs
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $endReason = null;
}
