<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One sentence of a live voice call that waits in the session worker's transcript backlog: it was spoken while a turn
 * owned the message index of the conversation, or its row could not be written because the index lock was busy. The
 * worker writes the backlog in spoken order once the turn has settled.
 */
class LiveTranscriptBacklogSentence extends ValueObject
{
    /**
     * @var string Who spoke: {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::ROLE_USER}
     * (the caller) or {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::ROLE_ASSISTANT}
     * (the agent)
     */
    public string $role = '';

    /** @var string The sentence */
    public string $text = '';

    /** @var int|null Where the sentence starts on the session clock, in milliseconds; null when the span is unknown */
    public ?int $startMs = null;

    /** @var int|null Where the sentence ends on the session clock, in milliseconds; null when the span is unknown */
    public ?int $endMs = null;

    /**
     * @var bool An agent sentence classified as a progress sentence when it was held back: it is written as a trace
     * event of the call, never as a conversation row
     */
    public bool $isProgress = false;

    public static function create(string $role, string $text, ?int $startMs, ?int $endMs, bool $isProgress = false): static
    {
        $liveTranscriptBacklogSentence = new static();
        $liveTranscriptBacklogSentence->role = $role;
        $liveTranscriptBacklogSentence->text = $text;
        $liveTranscriptBacklogSentence->startMs = $startMs;
        $liveTranscriptBacklogSentence->endMs = $endMs;
        $liveTranscriptBacklogSentence->isProgress = $isProgress;

        return $liveTranscriptBacklogSentence;
    }
}
