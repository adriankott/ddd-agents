<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;

/** A package's declared feature tables, as given */
class AgentFeatureTableConditionProviderDouble implements AgentFeatureTableConditionProvider
{
    public function __construct(protected AgentFeatureTableConditions $agentFeatureTableConditions)
    {
    }

    public function getFeatureTableConditions(): AgentFeatureTableConditions
    {
        return $this->agentFeatureTableConditions;
    }
}
