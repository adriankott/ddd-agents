<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;

/** The trace of a conversation without a table: every write records its events in order and answers how many it took. */
class RecordingConversationTraceEventsService extends AIConversationTraceEventsService
{
    /** @var AIConversationTraceEvent[] The events written, in order */
    public array $recordedTraceEvents = [];

    /** @var int[] The number of events of each write, in order */
    public array $eventCountsPerWrite = [];

    public function __construct()
    {
    }

    /** @param AIConversationTraceEvent[] $traceEvents */
    public function recordEvents(array $traceEvents): int
    {
        $this->eventCountsPerWrite[] = count($traceEvents);
        foreach ($traceEvents as $traceEvent) {
            $this->recordedTraceEvents[] = $traceEvent;
        }

        return count($traceEvents);
    }

    /** @return string[] The event types written, in order */
    public function getRecordedEventTypes(): array
    {
        $eventTypes = [];
        foreach ($this->recordedTraceEvents as $traceEvent) {
            $eventTypes[] = $traceEvent->eventType;
        }

        return $eventTypes;
    }
}
