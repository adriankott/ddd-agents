<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;
use RuntimeException;

/** The trace event writes of the package, kept instead of written; each write call is one entry. */
class RecordingTraceEventsServiceDouble extends AIConversationTraceEventsService
{
    /** @var array<int, AIConversationTraceEvent[]> The events of every write call, in order */
    public array $recordedWrites = [];

    public ?string $writeFailureMessage = null;

    public function recordEvent(AIConversationTraceEvent $traceEvent): bool
    {
        $this->failWhenAsked();
        $this->recordedWrites[] = [$traceEvent];
        return true;
    }

    /** @param AIConversationTraceEvent[] $traceEvents */
    public function recordEvents(array $traceEvents): int
    {
        $this->failWhenAsked();
        $this->recordedWrites[] = $traceEvents;
        return count($traceEvents);
    }

    /** @return string[] The event types of every write, in order */
    public function getRecordedEventTypes(): array
    {
        $eventTypes = [];
        foreach ($this->recordedWrites as $writtenEvents) {
            foreach ($writtenEvents as $traceEvent) {
                $eventTypes[] = $traceEvent->eventType;
            }
        }
        return $eventTypes;
    }

    protected function failWhenAsked(): void
    {
        if ($this->writeFailureMessage !== null) {
            throw new RuntimeException($this->writeFailureMessage);
        }
    }
}
