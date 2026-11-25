<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Installation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The conditions under which tables are required ({@see AgentFeatureTableCondition}); several for one class are all
 * checked, the first that makes the table not required wins.
 *
 * @property AgentFeatureTableCondition[] $elements
 * @method AgentFeatureTableCondition[] getElements()
 * @method AgentFeatureTableCondition|null getByUniqueKey(string $uniqueKey)
 */
class AgentFeatureTableConditions extends ObjectSet
{
    /**
     * Why the table of the entity class is not required; null while it is (no condition, or every condition holds).
     *
     * @param string[] $boundSkillNames
     */
    public function findReasonTableIsNotRequired(string $entityClassName, array $boundSkillNames): ?string
    {
        foreach ($this->getElements() as $agentFeatureTableCondition) {
            if (!$agentFeatureTableCondition->isAboutEntityClass($entityClassName)) {
                continue;
            }
            $reason = $agentFeatureTableCondition->findReasonTableIsNotRequired($boundSkillNames);
            if ($reason !== null) {
                return $reason;
            }
        }
        return null;
    }
}
