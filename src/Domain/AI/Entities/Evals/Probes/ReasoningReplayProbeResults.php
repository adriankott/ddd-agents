<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Probes;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The verdicts of every scenario one probe run made.
 *
 * @property ReasoningReplayProbeResult[] $elements
 * @method ReasoningReplayProbeResult|null first()
 * @method ReasoningReplayProbeResult|null getByUniqueKey(string $uniqueKey)
 * @method ReasoningReplayProbeResult[] getElements()
 */
class ReasoningReplayProbeResults extends ObjectSet
{
    public function hasFailure(): bool
    {
        foreach ($this->getElements() as $reasoningReplayProbeResult) {
            if ($reasoningReplayProbeResult->isFailure()) {
                return true;
            }
        }
        return false;
    }
}
