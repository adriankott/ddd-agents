<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\Installation;

use DDD\Domain\AI\Entities\Installation\AgentFeatureTableCondition;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Installation\AgentFeatureTableConditionProvider;
use DDD\Infrastructure\Services\Service;

/**
 * Tells `app:agent:check-installation` when the call table is required: only while the voice channel is switched on
 * AND calls are kept in the table (`AI.agentVoice.enabled`, `AI.agentVoice.callStore => 'entity'`). An application
 * that does not run voice, or keeps calls in a store of its own, needs no table. The switch alone is asked, not
 * whether voice can run in the installation: what reads and tidies the call table (the admin list of calls, the ring
 * expiry, the stale-call sweep of `app:agent:reap`) works without the session worker's library as well.
 */
class AgentVoiceFeatureTableConditionProviderService extends Service implements AgentFeatureTableConditionProvider
{
    public const string CALL_TABLE_FEATURE_DESCRIPTION = 'voice calls kept in the call table (AI.agentVoice.enabled with AI.agentVoice.callStore "entity")';

    public function getFeatureTableConditions(): AgentFeatureTableConditions
    {
        $agentFeatureTableConditions = new AgentFeatureTableConditions();
        $callTableCondition = AgentFeatureTableCondition::create(
            AgentVoiceCall::class,
            static::CALL_TABLE_FEATURE_DESCRIPTION,
            AgentVoiceConfiguration::current()->isCallTableInUse()
        );
        $agentFeatureTableConditions->add($callTableCondition);

        return $agentFeatureTableConditions;
    }
}
