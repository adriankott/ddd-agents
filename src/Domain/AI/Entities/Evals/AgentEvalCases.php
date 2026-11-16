<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The cases of an evaluation fixture ({@see AgentEvalCase}), in the order of the file. Each case is held under the key
 * of its own object: two cases with the same id both stay.
 *
 * @property AgentEvalCase[] $elements
 * @method AgentEvalCase[] getElements()
 * @method AgentEvalCase|null first()
 * @method AgentEvalCase|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalCases extends ObjectSet
{
}
