<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Why a call may not start or may not go on, as the admission port answers it
 * ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService}). The code is what a route answers as the
 * message of its `403`; the end reason is what a running call is ended with after the model said its one goodbye.
 */
class AgentVoiceCallRefusal extends ValueObject
{
    /** @var string The voice channel is off, or the account does not hold the right to use it */
    public const string CODE_FEATURE_NOT_AVAILABLE = 'feature_not_available';

    /** @var string The account billed for the call has no AI budget left for it */
    public const string CODE_NO_AI_BUDGET = 'no_ai_budget';

    /** @var string The account's calls of today reached the daily limit of seconds */
    public const string CODE_DAILY_LIMIT_REACHED = 'daily_limit_reached';

    /** @var string A running call reached the longest a call may last (`AI.agentVoice.limits.maximumCallSeconds`) */
    public const string CODE_MAXIMUM_CALL_SECONDS_REACHED = 'maximum_call_seconds_reached';

    /** @var string The code of the refusal: one of the CODE_* constants, or a code of the application */
    public string $code;

    /** @var string The end reason a running call gets when it is refused */
    public string $endReason = AgentVoiceCall::END_REASON_LIMIT_REACHED;

    /** A refusal with `$code`; a running call it ends gets `$endReason`. */
    public static function forCode(string $code, string $endReason = AgentVoiceCall::END_REASON_LIMIT_REACHED): static
    {
        $agentVoiceCallRefusal = new static();
        $agentVoiceCallRefusal->code = $code;
        $agentVoiceCallRefusal->endReason = $endReason;

        return $agentVoiceCallRefusal;
    }

    /** The channel is off or not allowed for the account. */
    public static function forFeatureNotAvailable(): static
    {
        return static::forCode(static::CODE_FEATURE_NOT_AVAILABLE);
    }

    /** The billed account has no AI budget left. */
    public static function forNoAiBudget(): static
    {
        return static::forCode(static::CODE_NO_AI_BUDGET);
    }

    /** The account's daily limit of call seconds is reached. */
    public static function forDailyLimitReached(): static
    {
        return static::forCode(static::CODE_DAILY_LIMIT_REACHED);
    }

    /** A running call has lasted the longest a call may last. */
    public static function forMaximumCallSecondsReached(): static
    {
        return static::forCode(static::CODE_MAXIMUM_CALL_SECONDS_REACHED);
    }

    /**
     * Whether an allowance of the account is used up: its AI budget or its seconds of the day. The model then tells
     * the owner so before the call ends. A call that reached the longest a call may last used up no allowance: it ends
     * with the goodbye that names no reason, and the owner can call again at once.
     */
    public function isLimitRefusal(): bool
    {
        return $this->code === static::CODE_NO_AI_BUDGET || $this->code === static::CODE_DAILY_LIMIT_REACHED;
    }
}
