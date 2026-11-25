<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Services\Voice\AgentVoiceCallEndsService;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of {@see AgentVoiceCallEndsService::endVoiceCall()}, the agent ending a call (the `endVoiceCall` tool): the
 * call was ended now (a ringing call withdrawn, a running one hung up), the call the agent is ON ends right after its
 * goodbye, or the reason nothing was ended. The tool words each case.
 */
class AgentVoiceCallEnd extends ValueObject
{
    /** The call is over now: a running call was hung up, or a ringing one withdrawn before anyone answered. */
    public const string OUTCOME_ENDED_NOW = 'ENDED_NOW';

    /** The call the agent is on ends right after the answer of this turn, the goodbye, was spoken. */
    public const string OUTCOME_ENDS_AFTER_GOODBYE = 'ENDS_AFTER_GOODBYE';

    /** No call id was given and no call rings or runs in this conversation. */
    public const string REFUSED_NO_CALL_IN_THIS_CONVERSATION = 'NO_CALL_IN_THIS_CONVERSATION';

    /** The given call id is not a call of this account. */
    public const string REFUSED_CALL_NOT_FOUND = 'CALL_NOT_FOUND';

    /** The given call is over already. */
    public const string REFUSED_CALL_ALREADY_ENDED = 'CALL_ALREADY_ENDED';

    /** @var string|null One of the OUTCOME_* values; null when refused. */
    public ?string $outcome = null;

    /** @var string|null One of the REFUSED_* values; null when a call was ended. */
    public ?string $refusalReason = null;

    /** @var AgentVoiceCall|null The call that was ended or asked about. */
    public ?AgentVoiceCall $agentVoiceCall = null;

    /** @var string|null The call's state right before it was ended (RINGING / IN_PROGRESS). */
    public ?string $callStateBeforeTheEnd = null;

    /** @var int[] The account's other calls ringing or running right now, named in a refusal so the model can pick one. */
    public array $otherOpenCallIds = [];

    public static function forEnded(string $outcome, AgentVoiceCall $agentVoiceCall, string $callStateBeforeTheEnd): static
    {
        $agentVoiceCallEnd = new static();
        $agentVoiceCallEnd->outcome = $outcome;
        $agentVoiceCallEnd->agentVoiceCall = $agentVoiceCall;
        $agentVoiceCallEnd->callStateBeforeTheEnd = $callStateBeforeTheEnd;

        return $agentVoiceCallEnd;
    }

    /**
     * @param int[] $otherOpenCallIds
     */
    public static function forRefused(string $refusalReason, ?AgentVoiceCall $agentVoiceCall = null, array $otherOpenCallIds = []): static
    {
        $agentVoiceCallEnd = new static();
        $agentVoiceCallEnd->refusalReason = $refusalReason;
        $agentVoiceCallEnd->agentVoiceCall = $agentVoiceCall;
        $agentVoiceCallEnd->otherOpenCallIds = array_values(array_map('intval', $otherOpenCallIds));

        return $agentVoiceCallEnd;
    }

    public function isRefused(): bool
    {
        return $this->refusalReason !== null;
    }

    public function endsAfterGoodbye(): bool
    {
        return $this->outcome === static::OUTCOME_ENDS_AFTER_GOODBYE;
    }
}
