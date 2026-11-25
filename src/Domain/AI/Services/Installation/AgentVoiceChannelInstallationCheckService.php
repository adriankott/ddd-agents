<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelProvider;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * `voiceChannel`, two findings:
 * - the voice channel asks exactly one provider ({@see AgentVoiceChannelProvider}). A WARNING when an application
 *   registered two or more of its own: only the first available one in container order answers, the others are
 *   never asked. The package's own provider is the fallback behind them and is not counted, so an application with
 *   one provider of its own has no finding
 *   ({@see AgentVoiceChannelService::getRegisteredApplicationVoiceChannelProviderClassNames()});
 * - voice is switched on (`AI.agentVoice.enabled`) but the WebSocket library of the session worker is not installed
 *   ({@see AgentVoiceCallAdmissionService::isVoiceSwitchedOnWithoutSessionWorkerLibrary()}). A WARNING: nothing
 *   fails, the channel reads as unavailable (no tool, no skill, every route answers `403 feature_not_available`),
 *   so the switch has no effect until the library is installed.
 */
class AgentVoiceChannelInstallationCheckService extends Service implements AgentInstallationCheckInterface
{
    public const string CHECK_NAME = 'voiceChannel';

    /** @var string The subject of the finding "switched on without the library": the config key of the switch */
    public const string SUBJECT_VOICE_SWITCH = 'AI.agentVoice.enabled';

    /** @var string[] The Composer packages the session worker needs */
    public const array SESSION_WORKER_LIBRARY_PACKAGES = ['ratchet/pawl', 'react/event-loop'];

    public function getInstallationCheckName(): string
    {
        return static::CHECK_NAME;
    }

    public function findInstallationFindings(): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        $applicationProviderClassNames = $this->getRegisteredApplicationVoiceChannelProviderClassNames();
        if (count($applicationProviderClassNames) > 1) {
            $agentInstallationFindings->addFinding(
                static::CHECK_NAME,
                AgentInstallationFinding::SEVERITY_WARNING,
                AgentVoiceChannelProvider::TAG,
                'Several voice channel providers of the application are registered (' . implode(', ', $applicationProviderClassNames) . '); only the first available one in container order is asked.',
                'Keep one voice channel provider of the application: exclude the others from the container (#[Exclude] or the services configuration). The package\'s own provider stays registered; it is asked after the application\'s.'
            );
        }
        if ($this->isVoiceSwitchedOnWithoutSessionWorkerLibrary()) {
            $agentInstallationFindings->addFinding(
                static::CHECK_NAME,
                AgentInstallationFinding::SEVERITY_WARNING,
                static::SUBJECT_VOICE_SWITCH,
                'Voice is switched on but the WebSocket library is not installed: the session worker that carries a call is no service, so no call can run. The voice channel reads as unavailable (no tool, no skill, the routes answer 403 feature_not_available).',
                'Install the library (composer require ' . implode(' ', static::SESSION_WORKER_LIBRARY_PACKAGES) . ') or switch voice off (' . static::SUBJECT_VOICE_SWITCH . ' => false).'
            );
        }
        return $agentInstallationFindings;
    }

    /** Whether voice is switched on in an installation without the session worker's library */
    protected function isVoiceSwitchedOnWithoutSessionWorkerLibrary(): bool
    {
        /** @var AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService */
        $agentVoiceCallAdmissionService = DDDService::instance()->getService(AgentVoiceCallAdmissionService::class);
        return $agentVoiceCallAdmissionService->isVoiceSwitchedOnWithoutSessionWorkerLibrary();
    }

    /** @return string[] The registered providers of the application: every one but the package's own */
    protected function getRegisteredApplicationVoiceChannelProviderClassNames(): array
    {
        /** @var AgentVoiceChannelService $agentVoiceChannelService */
        $agentVoiceChannelService = DDDService::instance()->getService(AgentVoiceChannelService::class);
        return $agentVoiceChannelService->getRegisteredApplicationVoiceChannelProviderClassNames();
    }
}
