<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableCondition;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalsService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The package's own feature tables: the memory table is read only while memory is on (`AI.agentMemory.enabled`) and
 * the package's own store answers it (a host store keeps memories elsewhere); the goal table likewise while goals are on
 * (`AI.agentGoals.enabled`) and the package's own goal store answers them. The goal table may still be missing then
 * (the tools answer "not available"): the installation check reports it as a warning
 * ({@see AgentInstallationCheckService::getEntityClassesWhoseMissingTableTheCodeTolerates()}).
 */
class AgentFeatureTableConditionProviderService extends Service implements AgentFeatureTableConditionProvider
{
    public function getFeatureTableConditions(): AgentFeatureTableConditions
    {
        $agentFeatureTableConditions = new AgentFeatureTableConditions();
        $memoryTableCondition = AgentFeatureTableCondition::create(
            AgentMemory::class,
            'the memory store of the package (AI.agentMemory.enabled, no host store)',
            $this->isPackageMemoryStoreInUse()
        );
        $agentFeatureTableConditions->add($memoryTableCondition);
        $goalTableCondition = AgentFeatureTableCondition::create(
            AgentGoal::class,
            'the goal store of the package (AI.agentGoals.enabled, no host store)',
            $this->isPackageGoalStoreInUse()
        );
        $agentFeatureTableConditions->add($goalTableCondition);
        return $agentFeatureTableConditions;
    }

    /** Whether the package's memory table is read: memory is on and the package's own store answers it */
    protected function isPackageMemoryStoreInUse(): bool
    {
        if (!AgentMemoriesService::isMemoryStoreEnabled()) {
            return false;
        }
        $agentMemoryStoreService = DDDService::instance()->getService(AgentMemoryStoreService::class);
        return $agentMemoryStoreService::class === AgentMemoryStoreService::class;
    }

    /** Whether the package's goal table is read: goals are on and the package's own goal store answers them */
    protected function isPackageGoalStoreInUse(): bool
    {
        if (!AgentGoalsService::isGoalsEnabled()) {
            return false;
        }
        $agentGoalStoreService = DDDService::instance()->getService(AgentGoalStoreService::class);
        return $agentGoalStoreService::class === AgentGoalStoreService::class;
    }
}
