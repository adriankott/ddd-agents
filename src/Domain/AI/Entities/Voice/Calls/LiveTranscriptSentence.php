<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One sentence a speaker of a live voice call finished, as {@see LiveTranscriptAssembler} completes it from the
 * provider's caption fragments: who spoke, what was said and the spoken span on the session clock of the provider.
 */
class LiveTranscriptSentence extends ValueObject
{
    /**
     * @var string Who spoke: {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::ROLE_USER}
     * (the caller) or {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::ROLE_ASSISTANT}
     * (the agent)
     */
    public string $role = '';

    /** @var string The sentence, trimmed; never empty */
    public string $text = '';

    /** @var int Where the sentence starts on the session clock, in milliseconds */
    public int $startMs = 0;

    /** @var int Where the sentence ends on the session clock, in milliseconds */
    public int $endMs = 0;

    public static function create(string $role, string $text, int $startMs, int $endMs): static
    {
        $liveTranscriptSentence = new static();
        $liveTranscriptSentence->role = $role;
        $liveTranscriptSentence->text = $text;
        $liveTranscriptSentence->startMs = $startMs;
        $liveTranscriptSentence->endMs = $endMs;

        return $liveTranscriptSentence;
    }
}
