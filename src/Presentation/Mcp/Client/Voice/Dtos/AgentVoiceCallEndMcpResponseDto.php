<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallEnd;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Result of `endVoiceCall`, small and flat on purpose (no LlmMarkdownRenderable: the JSON overhead is next to nothing):
 * which call, whether it is over now or ends after the goodbye, and what to say. A refusal is never a response: the
 * tool throws its sentence ({@see self::buildRefusal()}).
 */
class AgentVoiceCallEndMcpResponseDto extends RestResponseDto
{
    /** @var int|null The call that was ended */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $callId = null;

    /** @var string|null The medium of the call (APP = the application's own window) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $channel = null;

    /** @var string|null ENDED_NOW = the call is over (hung up, or a ringing call withdrawn); ENDS_AFTER_GOODBYE = the call you are on ends right after your answer is spoken */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $outcome = null;

    /** @var string|null What to say now */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    /** The answer of a call that was ended, or that ends after the goodbye. */
    public static function forEndedCall(AgentVoiceCallEnd $agentVoiceCallEnd): static
    {
        $responseDto = new static();
        $responseDto->callId = $agentVoiceCallEnd->agentVoiceCall?->id !== null ? (int)$agentVoiceCallEnd->agentVoiceCall->id : null;
        $responseDto->channel = $agentVoiceCallEnd->agentVoiceCall?->getChannelType();
        $responseDto->outcome = $agentVoiceCallEnd->outcome;
        if ($agentVoiceCallEnd->endsAfterGoodbye()) {
            $responseDto->note = 'The call you are on ends right after this answer is spoken. Answer with ONE short goodbye'
                . ' sentence in the language of the call: no question, no further offer.';
            return $responseDto;
        }
        $responseDto->note = $agentVoiceCallEnd->callStateBeforeTheEnd === AgentVoiceCall::CALL_STATE_RINGING
            ? 'Call #' . $responseDto->callId . ' was still ringing and is withdrawn: it no longer rings. Tell the owner in one sentence.'
            : 'Call #' . $responseDto->callId . ' is hung up. Tell the owner in one sentence.';

        return $responseDto;
    }

    /** The sentence a refused end answers the model with: what is the case and the next step. */
    public static function buildRefusal(AgentVoiceCallEnd $agentVoiceCallEnd): string
    {
        $otherOpenCallsPart = $agentVoiceCallEnd->otherOpenCallIds !== []
            ? ' Calls ringing or running right now: #' . implode(', #', $agentVoiceCallEnd->otherOpenCallIds)
                . '. Pass the callId of the one the owner means (getVoiceCalls with onlyRunning shows them).'
            : ' No call of this account is ringing or running right now.';

        return match ($agentVoiceCallEnd->refusalReason) {
            AgentVoiceCallEnd::REFUSED_CALL_NOT_FOUND => 'No call ended: there is no such call of this account.' . $otherOpenCallsPart,
            AgentVoiceCallEnd::REFUSED_CALL_ALREADY_ENDED => 'No call ended: call #' . $agentVoiceCallEnd->agentVoiceCall?->id
                . ' is already over (' . ($agentVoiceCallEnd->agentVoiceCall?->endReason ?? 'ended') . ').' . $otherOpenCallsPart,
            default => 'No call ended: no call is ringing or running in this conversation.' . $otherOpenCallsPart,
        };
    }
}
