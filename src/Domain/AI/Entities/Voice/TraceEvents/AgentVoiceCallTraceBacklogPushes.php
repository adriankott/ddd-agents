<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The agent's sentences of one call that the session worker held back in its transcript backlog, as
 * {@see AgentVoiceCallTraceSummary} reads them from the trace ({@see AgentVoiceCallTraceBacklogPush}), in the order
 * of the trace. Each push is held under the key of its own object, so two pushes with the same times both stay.
 *
 * @property AgentVoiceCallTraceBacklogPush[] $elements
 * @method AgentVoiceCallTraceBacklogPush[] getElements()
 * @method AgentVoiceCallTraceBacklogPush|null first()
 * @method AgentVoiceCallTraceBacklogPush|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentVoiceCallTraceBacklogPushes extends ObjectSet
{
    /** A new set with the given pushes, in their order. */
    public static function fromPushes(AgentVoiceCallTraceBacklogPush ...$backlogPushes): static
    {
        $backlogPushesSet = new static();
        foreach ($backlogPushes as $backlogPush) {
            $backlogPushesSet->add($backlogPush);
        }

        return $backlogPushesSet;
    }
}
