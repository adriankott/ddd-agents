<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The persisted sentences of one call as {@see AgentVoiceCallTraceSummary} reads them from the trace
 * ({@see AgentVoiceCallTraceSpokenSentence}), in the order of the trace until {@see sortBySpokenStart()} orders them
 * by the session clock. Each sentence is held under the key of its own object, so two sentences with the same speaker
 * and span both stay.
 *
 * @property AgentVoiceCallTraceSpokenSentence[] $elements
 * @method AgentVoiceCallTraceSpokenSentence[] getElements()
 * @method AgentVoiceCallTraceSpokenSentence|null first()
 * @method AgentVoiceCallTraceSpokenSentence|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentVoiceCallTraceSpokenSentences extends ObjectSet
{
    /** A new set with the given sentences, in their order. */
    public static function fromSentences(AgentVoiceCallTraceSpokenSentence ...$spokenSentences): static
    {
        $spokenSentencesSet = new static();
        foreach ($spokenSentences as $spokenSentence) {
            $spokenSentencesSet->add($spokenSentence);
        }

        return $spokenSentencesSet;
    }

    /** Orders the sentences by where they start on the session clock of the provider, the earliest first. */
    public function sortBySpokenStart(): void
    {
        $this->sort(
            static fn (AgentVoiceCallTraceSpokenSentence $left, AgentVoiceCallTraceSpokenSentence $right): int => $left->fromMs <=> $right->fromMs
        );
    }
}
