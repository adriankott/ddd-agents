<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;

/**
 * A call of an application with a further medium, written the way such an application overrides the call: each
 * protected override point answers a value of its own, so a test sees which text and which bound reads it.
 */
class AnotherMediumAgentVoiceCall extends AgentVoiceCall
{
    public const string CHANNEL_TYPE_KIOSK = 'KIOSK';

    public function getChannelType(): string
    {
        return static::CHANNEL_TYPE_KIOSK;
    }

    /** @return string[] */
    protected function getAdditionalLlmMarkdownLineParts(): array
    {
        return ['signed in by code', 'kiosk 0100'];
    }

    protected function getOtherPartyLabel(): string
    {
        return 'kiosk 0100';
    }

    protected function getCallWord(): string
    {
        return 'Kiosk call';
    }

    protected function getAgentLabel(): string
    {
        return 'the voice desk';
    }

    protected function getMaximumCallSeconds(): int
    {
        return 120;
    }

    protected function getRingSecondsWithoutDeadline(): int
    {
        return 10;
    }
}
