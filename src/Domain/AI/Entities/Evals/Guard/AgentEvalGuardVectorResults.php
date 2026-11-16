<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The attack figures of one guard-prompt variant per vector ({@see AgentEvalGuardVectorResult}), one per vector, in
 * the order the vectors first appeared.
 *
 * @property AgentEvalGuardVectorResult[] $elements
 * @method AgentEvalGuardVectorResult[] getElements()
 * @phpstan-consistent-constructor
 */
class AgentEvalGuardVectorResults extends ObjectSet
{
    /**
     * The figures of `$vector`, added empty when the vector is new.
     *
     * @param string $vector
     * @return AgentEvalGuardVectorResult
     */
    public function findOrAddVector(string $vector): AgentEvalGuardVectorResult
    {
        foreach ($this->getElements() as $agentEvalGuardVectorResult) {
            if ($agentEvalGuardVectorResult->vector === $vector) {
                return $agentEvalGuardVectorResult;
            }
        }
        $agentEvalGuardVectorResult = AgentEvalGuardVectorResult::forVector($vector);
        $addedVectorResult = $agentEvalGuardVectorResult;
        $this->add($addedVectorResult);
        return $agentEvalGuardVectorResult;
    }

    /**
     * The figures per vector as the stored document of the run file carries them: vector => `{total, bypassed}`.
     *
     * @return array[]
     */
    public function toArray(): array
    {
        $figuresByVector = [];
        foreach ($this->getElements() as $agentEvalGuardVectorResult) {
            $figuresByVector[$agentEvalGuardVectorResult->vector] = $agentEvalGuardVectorResult->toArray();
        }
        return $figuresByVector;
    }
}
