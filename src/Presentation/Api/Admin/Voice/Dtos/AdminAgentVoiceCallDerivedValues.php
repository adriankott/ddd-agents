<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Money\MoneyAmount;

/**
 * What the server derives for one call of the admin list, beside the call's own columns: the list carries one of these
 * per call, in the order of the calls. A screen shows these values as they are and derives none of them on its own
 * clock or price list.
 *
 * {@see self::$callState} is the server's reading of the call when it answers. {@see self::$bookedCosts} is the seconds
 * the call booked at the catalog's PRESENT price per session second of the model the call's provider runs on: the same
 * pricing every booking of the session worker used, computed when the list is read. It is not a read of an
 * application's cost ledger; a price that changed since the call was booked shows the new price here.
 */
class AdminAgentVoiceCallDerivedValues extends ValueObject
{
    /** @var int The id of the call these values belong to ({@see AgentVoiceCall::$id}) */
    public int $callId;

    /**
     * @var string Where the call stands at the time of the answer ({@see AgentVoiceCall::getCallStateAt()}): `RINGING`,
     * `IN_PROGRESS` or `ENDED`. A call a session worker that died left open (answered, never ended, longer ago than the
     * maximum call length) reads `ENDED`, as does a ring past its deadline
     */
    public string $callState;

    /**
     * @var MoneyAmount|null The costs of the call's booked seconds; null when the catalog holds no price per session
     * second for the model, or when the call booked nothing
     */
    public ?MoneyAmount $bookedCosts = null;

    /** The derived values of `$agentVoiceCall` at `$nowUnixTime`; `$bookedCosts` are the costs of its booked seconds, null when none are known. */
    public static function forCall(AgentVoiceCall $agentVoiceCall, ?MoneyAmount $bookedCosts, int $nowUnixTime): static
    {
        $agentVoiceCallDerivedValues = new static();
        $agentVoiceCallDerivedValues->callId = (int)$agentVoiceCall->id;
        $agentVoiceCallDerivedValues->callState = $agentVoiceCall->getCallStateAt($nowUnixTime);
        $agentVoiceCallDerivedValues->bookedCosts = $bookedCosts;

        return $agentVoiceCallDerivedValues;
    }

    /**
     * The key of an entry in {@see AdminAgentVoiceCallsDerivedValues} is the id of its call: the identity of an entry
     * is the call it describes, so the list holds one entry per call. An entry without a call id is keyed by the
     * object itself.
     */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->callId ?? spl_object_id($this));
    }
}
