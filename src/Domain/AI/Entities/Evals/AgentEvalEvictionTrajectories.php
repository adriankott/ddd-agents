<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The trajectories of an eviction fixture ({@see AgentEvalEvictionTrajectory}), in the order of the file. Two
 * trajectories with the same name both stay.
 *
 * @property AgentEvalEvictionTrajectory[] $elements
 * @method AgentEvalEvictionTrajectory[] getElements()
 * @method AgentEvalEvictionTrajectory|null first()
 * @phpstan-consistent-constructor
 */
class AgentEvalEvictionTrajectories extends ObjectSet
{
}
