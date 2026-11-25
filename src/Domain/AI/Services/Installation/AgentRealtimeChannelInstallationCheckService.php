<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * `realtimeChannel`: the realtime channel of the agent needs a cache every web and worker process shares — its
 * mailbox, the presences of the clients and the answers of `Agent.UI` live there. A WARNING while `Agent.UI` is bound
 * and the channel has none (no cache, or one local to the process).
 */
class AgentRealtimeChannelInstallationCheckService extends Service implements AgentInstallationCheckInterface
{
    public const string CHECK_NAME = 'realtimeChannel';

    public function getInstallationCheckName(): string
    {
        return static::CHECK_NAME;
    }

    public function findInstallationFindings(): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        if (!$this->isAgentUIBound()) {
            return $agentInstallationFindings;
        }
        $sharedCacheProblem = $this->findSharedCacheProblem();
        if ($sharedCacheProblem !== null) {
            $agentInstallationFindings->addFinding(
                static::CHECK_NAME,
                AgentInstallationFinding::SEVERITY_WARNING,
                'AI.agentRealtimeChannel',
                $sharedCacheProblem,
                "Set sharedCacheGroup in config/app/AI/agentRealtimeChannel.php to a group every process shares (e.g. 'redis')."
            );
        }
        return $agentInstallationFindings;
    }

    /** Whether config/app/AI/skillPackages.php binds `Agent.UI` */
    protected function isAgentUIBound(): bool
    {
        $configuredBinding = Config::get(AISkillsService::CONFIG_SKILL_PACKAGES);
        return is_array($configuredBinding) && array_key_exists(AISkill::AGENT_UI, $configuredBinding);
    }

    protected function findSharedCacheProblem(): ?string
    {
        /** @var AgentRealtimeChannelService $agentRealtimeChannelService */
        $agentRealtimeChannelService = DDDService::instance()->getService(AgentRealtimeChannelService::class);
        return $agentRealtimeChannelService->findSharedCacheProblem();
    }
}
