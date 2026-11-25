<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;

/**
 * The admission behind the package's own voice channel provider ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceChannelProviderService}): the provider's availability is
 * {@see canVoiceRunHere()}, so each question to it lands here and is written into the shared log under the package
 * provider's short name. Voice is never "switched on without the library", so the installation check has no second
 * finding.
 */
class AvailabilityRecordingAgentVoiceCallAdmissionServiceDouble extends AgentVoiceCallAdmissionService
{
    public const string PACKAGE_PROVIDER_NAME = 'AgentVoiceChannelProviderService';

    public bool $voiceCanRunHere = true;

    public function __construct(public VoiceChannelAvailabilityQuestionLog $availabilityQuestionLog)
    {
    }

    public function canVoiceRunHere(): bool
    {
        $this->availabilityQuestionLog->record(static::PACKAGE_PROVIDER_NAME);
        return $this->voiceCanRunHere;
    }

    public function isVoiceSwitchedOnWithoutSessionWorkerLibrary(): bool
    {
        return false;
    }
}
