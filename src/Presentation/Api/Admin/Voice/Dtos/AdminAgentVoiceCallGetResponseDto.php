<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One call as the admin detail shows it: the call itself and, spelled out, what a screen would otherwise compute or
 * look up (where it stands now, how long it lasted, what was booked and what that costs, why it ended, where its
 * transcript is).
 */
class AdminAgentVoiceCallGetResponseDto extends RestResponseDto
{
    /** @var AgentVoiceCall The call */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentVoiceCall $agentVoiceCall;

    /** @var int|null How long the call lasted, in seconds; null while it still runs or when nobody answered it */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $durationInSeconds = null;

    /** @var int The seconds of the call booked as usage so far */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $bookedSeconds = 0;

    /**
     * @var MoneyAmount|null The costs of the booked seconds: {@see self::$bookedSeconds} at the catalog's PRESENT price
     * per session second of the model the call's provider runs on, the same pricing every booking of the session worker
     * used. Computed when the detail is read, not read from an application's cost ledger. Null when the catalog holds
     * no price for the model, or when the call booked nothing
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?MoneyAmount $bookedCosts = null;

    /**
     * @var string Where the call stands at the time of the answer ({@see AgentVoiceCall::getCallStateAt()}): `RINGING`,
     * `IN_PROGRESS` or `ENDED`. The server's reading, which a screen shows as it is: a call a session worker that died
     * left open (answered, never ended, longer ago than the maximum call length) reads `ENDED`, as does a ring past its
     * deadline
     */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $callState;

    /** @var string|null Why the call ended; null while it rings or runs */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $endReason = null;

    /** @var int|null The conversation the call ran on: its messages are the transcript of the call. Null = the call has none */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $conversationId = null;

    /**
     * The detail of `$agentVoiceCall`. `$bookedCosts` are the costs of its booked seconds, null when none are known;
     * `$nowUnixTime` is the time the state of the call is read at, null = now.
     */
    public static function forCall(AgentVoiceCall $agentVoiceCall, ?MoneyAmount $bookedCosts = null, ?int $nowUnixTime = null): static
    {
        $responseDto = new static();
        $responseDto->agentVoiceCall = $agentVoiceCall;
        $responseDto->durationInSeconds = $agentVoiceCall->getDurationInSeconds();
        $responseDto->bookedSeconds = $agentVoiceCall->bookedSeconds;
        $responseDto->bookedCosts = $bookedCosts;
        $responseDto->callState = $agentVoiceCall->getCallStateAt($nowUnixTime ?? time());
        $responseDto->endReason = $agentVoiceCall->endReason;
        $responseDto->conversationId = $agentVoiceCall->aiConversationId !== null ? (int)$agentVoiceCall->aiConversationId : null;

        return $responseDto;
    }
}
