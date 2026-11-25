<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelProvider;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * `voiceChannel`: the voice channel asks exactly one provider ({@see AgentVoiceChannelProvider}). A WARNING when more
 * than one is registered: only the first available one in container order answers, the others are never asked.
 */
class AgentVoiceChannelInstallationCheckService extends Service implements AgentInstallationCheckInterface
{
    public const string CHECK_NAME = 'voiceChannel';

    public function getInstallationCheckName(): string
    {
        return static::CHECK_NAME;
    }

    public function findInstallationFindings(): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        $registeredProviderClassNames = $this->getRegisteredVoiceChannelProviderClassNames();
        if (count($registeredProviderClassNames) > 1) {
            $agentInstallationFindings->addFinding(
                static::CHECK_NAME,
                AgentInstallationFinding::SEVERITY_WARNING,
                AgentVoiceChannelProvider::TAG,
                'Several voice channel providers are registered (' . implode(', ', $registeredProviderClassNames) . '); only the first available one in container order is asked.',
                'Keep one voice channel provider: exclude the others from the container (#[Exclude] or the services configuration).'
            );
        }
        return $agentInstallationFindings;
    }

    /** @return string[] */
    protected function getRegisteredVoiceChannelProviderClassNames(): array
    {
        /** @var AgentVoiceChannelService $agentVoiceChannelService */
        $agentVoiceChannelService = DDDService::instance()->getService(AgentVoiceChannelService::class);
        return $agentVoiceChannelService->getRegisteredVoiceChannelProviderClassNames();
    }
}
