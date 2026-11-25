<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableCondition;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The package's own feature tables: the memory table is read only while memory is on (`AI.agentMemory.enabled`) and
 * the package's own store answers it (a host store keeps memories elsewhere).
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
}
