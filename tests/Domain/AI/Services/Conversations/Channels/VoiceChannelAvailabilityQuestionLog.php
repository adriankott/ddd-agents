<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Channels;

/** The availability questions of a voice channel test, in the order they were asked: one name per question. */
class VoiceChannelAvailabilityQuestionLog
{
    /** @var string[] */
    public array $askedProviderNames = [];

    public function record(string $providerName): void
    {
        $this->askedProviderNames[] = $providerName;
    }
}
