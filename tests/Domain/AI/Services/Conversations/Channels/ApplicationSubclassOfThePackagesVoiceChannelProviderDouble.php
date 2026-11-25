<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Services\Voice\AgentVoiceChannelProviderService;

/**
 * An application's provider written as a subclass of the package's own: it answers its availability itself and writes
 * each question into the shared log.
 */
class ApplicationSubclassOfThePackagesVoiceChannelProviderDouble extends AgentVoiceChannelProviderService
{
    public const string PROVIDER_NAME = 'subclass';

    public bool $available = true;

    public function __construct(public VoiceChannelAvailabilityQuestionLog $availabilityQuestionLog)
    {
    }

    public function isVoiceChannelAvailable(): bool
    {
        $this->availabilityQuestionLog->record(static::PROVIDER_NAME);
        return $this->available;
    }
}
