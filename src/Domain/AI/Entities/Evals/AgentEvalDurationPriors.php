<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The duration priors of a suite plan ({@see AgentEvalDurationPrior}), one per kind and flavor of task. A prior added
 * for a kind and flavor that already has one sets its seconds.
 *
 * @property AgentEvalDurationPrior[] $elements
 * @method AgentEvalDurationPrior[] getElements()
 * @method AgentEvalDurationPrior|null first()
 * @method AgentEvalDurationPrior|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalDurationPriors extends ObjectSet
{
    /** Adds the prior `$seconds` of the kind `$kind` in the flavor `$flavor`, or sets it on the prior already held. */
    public function addPrior(string $kind, string $flavor, float $seconds): void
    {
        $heldPrior = $this->getByUniqueKey(AgentEvalDurationPrior::uniqueKeyForKindAndFlavor($kind, $flavor));
        if ($heldPrior instanceof AgentEvalDurationPrior) {
            $heldPrior->seconds = $seconds;
            return;
        }
        $agentEvalDurationPrior = AgentEvalDurationPrior::forKindAndFlavor($kind, $flavor, $seconds);
        $this->add($agentEvalDurationPrior);
    }

    /** The seconds of the kind `$kind` in the flavor `$flavor`; null when the set has no prior for them. */
    public function findSecondsOfKindAndFlavor(string $kind, string $flavor): ?float
    {
        $agentEvalDurationPrior = $this->getByUniqueKey(AgentEvalDurationPrior::uniqueKeyForKindAndFlavor($kind, $flavor));
        return $agentEvalDurationPrior instanceof AgentEvalDurationPrior ? $agentEvalDurationPrior->seconds : null;
    }
}
