<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * What an agent space cannot meet now of what an evaluation case requires: one {@see AgentEvalNotApplicableReason}
 * per unmet requirement ({@see AgentEvalNotApplicableReason::getRequirementName()}), in the order of the case's
 * requirements — the first one decides ({@see AgentEvalApplicabilityFacts::findNotApplicableReason()}).
 *
 * A requirement name holds one reason: the set keys a reason by its requirement name, so a second reason for a
 * requirement the set already holds is not added and the first one stays.
 *
 * @property AgentEvalNotApplicableReason[] $elements
 * @method AgentEvalNotApplicableReason[] getElements()
 * @method AgentEvalNotApplicableReason|null first()
 * @method AgentEvalNotApplicableReason|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalNotApplicableReasons extends ObjectSet
{
    /**
     * @param string $requirementName
     * @return AgentEvalNotApplicableReason|null the reason of that unmet requirement
     */
    public function getByRequirementName(string $requirementName): ?AgentEvalNotApplicableReason
    {
        return $this->getByUniqueKey(AgentEvalNotApplicableReason::uniqueKeyForRequirementName($requirementName));
    }
}
