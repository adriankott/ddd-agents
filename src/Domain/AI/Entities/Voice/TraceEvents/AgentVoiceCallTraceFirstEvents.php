<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvents;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The first trace event of each event type of ONE voice call: what {@see AgentVoiceCallTraceSummary} reads the
 * start-up times of a call from. Built once from the call's trace events in time order; a later event of a type that
 * already has one is left out.
 *
 * @phpstan-consistent-constructor
 */
class AgentVoiceCallTraceFirstEvents extends ValueObject
{
    /**
     * @var array<string, AIConversationTraceEvent> Event type → the first event of that type. An implementation detail
     * of this object: the map is never serialised and never returned, it is read through {@see findEventOfType()}.
     */
    protected array $firstEventsByEventType = [];

    /** The first event of each type among `$callTraceEvents`, which are in time order. */
    public static function fromTraceEvents(AIConversationTraceEvents $callTraceEvents): static
    {
        $firstEvents = new static();
        foreach ($callTraceEvents->getElements() as $traceEvent) {
            $firstEvents->firstEventsByEventType[(string)$traceEvent->eventType] ??= $traceEvent;
        }

        return $firstEvents;
    }

    /** The first event of `$eventType`; null when the call has no such event. */
    public function findEventOfType(string $eventType): ?AIConversationTraceEvent
    {
        return $this->firstEventsByEventType[$eventType] ?? null;
    }

    /** Whether the call has an event of `$eventType`. */
    public function hasEventOfType(string $eventType): bool
    {
        return isset($this->firstEventsByEventType[$eventType]);
    }
}
