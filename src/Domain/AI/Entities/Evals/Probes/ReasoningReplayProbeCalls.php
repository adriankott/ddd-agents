<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Probes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The model calls of one probe scenario, in the order they were made.
 *
 * @property ReasoningReplayProbeCall[] $elements
 * @method ReasoningReplayProbeCall|null first()
 * @method ReasoningReplayProbeCall|null getByUniqueKey(string $uniqueKey)
 * @method ReasoningReplayProbeCall[] getElements()
 */
class ReasoningReplayProbeCalls extends ObjectSet
{
}
