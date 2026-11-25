<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Channels;

/** An application's voice channel provider that writes each availability question into a shared log. */
class AvailabilityRecordingVoiceChannelProviderDouble extends ScriptedVoiceChannelProviderDouble
{
    public function __construct(public VoiceChannelAvailabilityQuestionLog $availabilityQuestionLog, public string $providerName)
    {
    }

    public function isVoiceChannelAvailable(): bool
    {
        $this->availabilityQuestionLog->record($this->providerName);
        return parent::isVoiceChannelAvailable();
    }
}
