<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One sentence of the agent that the session worker held back in its transcript backlog, as
 * {@see AgentVoiceCallTraceSummary} reads it from the trace: when it was pushed and its spoken span on the session
 * clock of the provider.
 */
class AgentVoiceCallTraceBacklogPush extends ValueObject
{
    /** @var int When the sentence was pushed to the backlog, in Unix milliseconds */
    public int $pushedAtUnixMs = 0;

    /** @var int|null Where the sentence starts on the session clock, in milliseconds; null when the event carries no span */
    public ?int $startMs = null;

    /** @var int|null Where the sentence ends on the session clock, in milliseconds; null when the event carries no span */
    public ?int $endMs = null;

    public static function create(int $pushedAtUnixMs, ?int $startMs, ?int $endMs): static
    {
        $agentVoiceCallTraceBacklogPush = new static();
        $agentVoiceCallTraceBacklogPush->pushedAtUnixMs = $pushedAtUnixMs;
        $agentVoiceCallTraceBacklogPush->startMs = $startMs;
        $agentVoiceCallTraceBacklogPush->endMs = $endMs;

        return $agentVoiceCallTraceBacklogPush;
    }

    /**
     * The key of a push in {@see AgentVoiceCallTraceBacklogPushes} is the object itself, never its content: two
     * sentences pushed in the same millisecond with the same span are two pushes.
     */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(spl_object_id($this));
    }

    /** How long the sentence was spoken, in milliseconds; null when its span is not known. */
    public function getSpokenLengthMs(): ?int
    {
        return $this->startMs !== null && $this->endMs !== null ? max(0, $this->endMs - $this->startMs) : null;
    }
}
