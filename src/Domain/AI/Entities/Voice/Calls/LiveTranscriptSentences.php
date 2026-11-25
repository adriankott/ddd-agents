<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Sentences the speakers of a live voice call finished ({@see LiveTranscriptSentence}), in the order
 * {@see LiveTranscriptAssembler} completed them. Each sentence is held under the key of its own object, so a speaker
 * who says the same words twice gives two sentences.
 *
 * @property LiveTranscriptSentence[] $elements
 * @method LiveTranscriptSentence[] getElements()
 * @method LiveTranscriptSentence|null first()
 * @method LiveTranscriptSentence|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class LiveTranscriptSentences extends ObjectSet
{
    /** A new set with the given sentences, in their order. */
    public static function fromSentences(LiveTranscriptSentence ...$liveTranscriptSentences): static
    {
        $liveTranscriptSentencesSet = new static();
        foreach ($liveTranscriptSentences as $liveTranscriptSentence) {
            $liveTranscriptSentencesSet->add($liveTranscriptSentence);
        }

        return $liveTranscriptSentencesSet;
    }
}
