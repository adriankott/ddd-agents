<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One persisted sentence of a call as {@see AgentVoiceCallTraceSummary} reads it from the trace: who spoke, the spoken
 * span on the session clock of the provider and when the row was written. Only sentences whose trace event carries a
 * spoken span become one.
 */
class AgentVoiceCallTraceSpokenSentence extends ValueObject
{
    /**
     * @var string Who spoke: {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::ROLE_USER}
     * (the caller) or {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::ROLE_ASSISTANT}
     * (the agent); empty when the event names nobody
     */
    public string $role = '';

    /** @var int Where the sentence starts on the session clock, in milliseconds */
    public int $fromMs = 0;

    /** @var int Where the sentence ends on the session clock, in milliseconds */
    public int $untilMs = 0;

    /** @var int When the sentence was persisted, in Unix milliseconds */
    public int $occurredAtUnixMs = 0;

    public static function create(string $role, int $fromMs, int $untilMs, int $occurredAtUnixMs): static
    {
        $agentVoiceCallTraceSpokenSentence = new static();
        $agentVoiceCallTraceSpokenSentence->role = $role;
        $agentVoiceCallTraceSpokenSentence->fromMs = $fromMs;
        $agentVoiceCallTraceSpokenSentence->untilMs = $untilMs;
        $agentVoiceCallTraceSpokenSentence->occurredAtUnixMs = $occurredAtUnixMs;

        return $agentVoiceCallTraceSpokenSentence;
    }

    /**
     * The key of a sentence in {@see AgentVoiceCallTraceSpokenSentences} is the object itself, never its content: two
     * trace events with the same speaker and span are two sentences of the call.
     */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }
}
