<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The figures of a run per guard-prompt variant ({@see AgentEvalGuardVariantResult}), one per variant, in the order
 * the variants first appeared among the probes.
 *
 * @property AgentEvalGuardVariantResult[] $elements
 * @method AgentEvalGuardVariantResult[] getElements()
 * @phpstan-consistent-constructor
 */
class AgentEvalGuardVariantResults extends ObjectSet
{
    /**
     * The figures of `$variantId`, added empty when the variant is new.
     *
     * @param string $variantId
     * @return AgentEvalGuardVariantResult
     */
    public function findOrAddVariant(string $variantId): AgentEvalGuardVariantResult
    {
        foreach ($this->getElements() as $agentEvalGuardVariantResult) {
            if ($agentEvalGuardVariantResult->variantId === $variantId) {
                return $agentEvalGuardVariantResult;
            }
        }
        $agentEvalGuardVariantResult = AgentEvalGuardVariantResult::forVariant($variantId);
        $addedVariantResult = $agentEvalGuardVariantResult;
        $this->add($addedVariantResult);
        return $agentEvalGuardVariantResult;
    }

    /**
     * The figures per variant as the stored document of the run file carries them: variant id => the variant's figures.
     *
     * @return array[]
     */
    public function toArray(): array
    {
        $figuresByVariantId = [];
        foreach ($this->getElements() as $agentEvalGuardVariantResult) {
            $figuresByVariantId[$agentEvalGuardVariantResult->variantId] = $agentEvalGuardVariantResult->toArray();
        }
        return $figuresByVariantId;
    }
}
