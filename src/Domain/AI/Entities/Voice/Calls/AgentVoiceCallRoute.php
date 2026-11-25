<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Which medium a call start of the agent goes to, or why none, decided purely from what the owner is entitled to and
 * what can be reached right now ({@see self::resolveForRequest()}). There is no fallback after the fact: an unanswered
 * ring never becomes a call on another medium.
 *
 * The base decides for one medium, the application's own window. An application with a further medium decides in a
 * subclass at the same path: its `resolveForRequest()` takes the requested channel and what its own media can reach
 * as further arguments after the base's two, and names its own channels and refusal reasons.
 */
class AgentVoiceCallRoute extends ValueObject
{
    /** @var string|null The medium the call starts on ({@see AgentVoiceCallStart::CHANNEL_APP}); null on a refusal */
    public ?string $channel = null;

    /** @var string|null One of the {@see AgentVoiceCallStart} REFUSED_* constants; null when a medium was chosen */
    public ?string $refusalReason = null;

    /**
     * The window when the voice channel is available to the owner and a client of the owner can be rung now;
     * otherwise the reason that names which of the two is missing.
     */
    public static function resolveForRequest(bool $appIsIncluded, bool $appIsRingable): static
    {
        if (!$appIsIncluded) {
            return static::refused(AgentVoiceCallStart::REFUSED_APP_NOT_INCLUDED);
        }
        return $appIsRingable
            ? static::toChannel(AgentVoiceCallStart::CHANNEL_APP)
            : static::refused(AgentVoiceCallStart::REFUSED_APP_NOT_RINGABLE);
    }

    public static function toChannel(string $channel): static
    {
        $agentVoiceCallRoute = static::newInstance();
        $agentVoiceCallRoute->channel = $channel;
        return $agentVoiceCallRoute;
    }

    public static function refused(string $refusalReason): static
    {
        $agentVoiceCallRoute = static::newInstance();
        $agentVoiceCallRoute->refusalReason = $refusalReason;
        return $agentVoiceCallRoute;
    }
}
