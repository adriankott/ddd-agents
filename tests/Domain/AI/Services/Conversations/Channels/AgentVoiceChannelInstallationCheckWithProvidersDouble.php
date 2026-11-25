<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Channels;

use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Domain\AI\Services\Installation\AgentVoiceChannelInstallationCheckService;

/** The `voiceChannel` check over a voice channel service the test fills with providers. */
class AgentVoiceChannelInstallationCheckWithProvidersDouble extends AgentVoiceChannelInstallationCheckService
{
    public function __construct(public AgentVoiceChannelService $agentVoiceChannelService)
    {
    }

    protected function getRegisteredApplicationVoiceChannelProviderClassNames(): array
    {
        return $this->agentVoiceChannelService->getRegisteredApplicationVoiceChannelProviderClassNames();
    }
}
