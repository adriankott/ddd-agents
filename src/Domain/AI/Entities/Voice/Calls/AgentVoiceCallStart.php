<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of {@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService::startVoiceCall()}, the agent's ONE way
 * to call the owner (the `startVoiceCall` tool): the call rings on the owner's open clients for the configured ring
 * length (`AI.agentVoice.limits.ringSeconds`), or the reason nothing started. The medium is chosen by
 * {@see AgentVoiceCallRoute}; the tool words each reason.
 *
 * The base knows one medium, the application's own window ({@see self::CHANNEL_APP}). An application with a further
 * medium declares its channels, its refusal reasons, its fields and its factory in a subclass at the same path. The
 * factories build through {@see newInstance()}, so the start a package service answers with is that subclass.
 */
class AgentVoiceCallStart extends ValueObject
{
    /** @var string The call rings in the application's own window: every open client of the owner */
    public const string CHANNEL_APP = 'APP';

    /** @var string The call continues the conversation the agent is in (its root conversation) */
    public const string CONVERSATION_CURRENT = 'CURRENT';

    /** @var string The call gets a new conversation of its own; the task is its first message */
    public const string CONVERSATION_NEW = 'NEW';

    /** @var string[] Which conversation a call may be asked to run on */
    public const array REQUESTABLE_CONVERSATIONS = [self::CONVERSATION_CURRENT, self::CONVERSATION_NEW];

    /** @var string The agent space or the account takes no automation any more: no call */
    public const string REFUSED_SCOPE_NOT_ACTIVE = 'SCOPE_NOT_ACTIVE';

    /** @var string The owner is on a call with the agent right now (this conversation's call, or a call of the account elsewhere) */
    public const string REFUSED_ALREADY_ON_A_CALL = 'ALREADY_ON_A_CALL';

    /** @var string The voice channel is not available to this owner (the admission answers `feature_not_available`) */
    public const string REFUSED_APP_NOT_INCLUDED = 'APP_NOT_INCLUDED';

    /** @var string No client of the owner listens right now: nothing can ring */
    public const string REFUSED_APP_NOT_RINGABLE = 'APP_NOT_RINGABLE';

    /** @var string The ring was started, but it reached no ring target: the call ended FAILED */
    public const string REFUSED_APP_RING_DELIVERED_NOWHERE = 'APP_RING_DELIVERED_NOWHERE';

    /** @var string The AI budget is used up (the admission answers `no_ai_budget`) */
    public const string REFUSED_NO_AI_BUDGET = 'NO_AI_BUDGET';

    /** @var string The account has used its seconds of calls for today (the admission answers `daily_limit_reached`) */
    public const string REFUSED_DAILY_LIMIT_REACHED = 'DAILY_LIMIT_REACHED';

    /** @var string The named purpose declares no spoken call task: a call conversation cannot carry it */
    public const string REFUSED_PURPOSE_NOT_CALLABLE = 'PURPOSE_NOT_CALLABLE';

    /** @var string A purpose was named for a call that continues the CURRENT conversation */
    public const string REFUSED_PURPOSE_NEEDS_NEW_CONVERSATION = 'PURPOSE_NEEDS_NEW_CONVERSATION';

    /** @var string No conversation could be created for the call */
    public const string REFUSED_NO_CALL_CONVERSATION = 'NO_CALL_CONVERSATION';

    /** @var string|null The medium the call started on ({@see self::CHANNEL_APP}); null on a refusal */
    public ?string $startedChannel = null;

    /** @var AgentVoiceCall|null The call that rings; null on a refusal */
    public ?AgentVoiceCall $agentVoiceCall = null;

    /** @var int|null The conversation the call runs on */
    public ?int $callConversationId = null;

    /** @var string|null One of the REFUSED_* constants; null when a call started */
    public ?string $refusalReason = null;

    /** @var bool Whether the voice channel is available to this owner; the next step a refusal names depends on it */
    public bool $appIsIncluded = false;

    /** @var string[] The purposes a new call conversation may carry (named in the PURPOSE_NOT_CALLABLE refusal) */
    public array $callablePurposeNames = [];

    /** The start of a call that rings on the owner's open clients. */
    public static function forAppRinging(AgentVoiceCall $agentVoiceCall): static
    {
        $agentVoiceCallStart = static::newInstance();
        $agentVoiceCallStart->startedChannel = static::CHANNEL_APP;
        $agentVoiceCallStart->agentVoiceCall = $agentVoiceCall;
        $agentVoiceCallStart->callConversationId = $agentVoiceCall->aiConversationId !== null
            ? (int)$agentVoiceCall->aiConversationId
            : null;
        $agentVoiceCallStart->appIsIncluded = true;
        return $agentVoiceCallStart;
    }

    /** A start that was refused, with its reason and whether the voice channel is available to the owner at all. */
    public static function forRefused(string $refusalReason, bool $appIsIncluded = false): static
    {
        $agentVoiceCallStart = static::newInstance();
        $agentVoiceCallStart->refusalReason = $refusalReason;
        $agentVoiceCallStart->appIsIncluded = $appIsIncluded;
        return $agentVoiceCallStart;
    }

    public function isRefused(): bool
    {
        return $this->refusalReason !== null;
    }
}
