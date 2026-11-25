<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Conversations\TraceEvents\AIConversationTraceEvent;
use DDD\Domain\AI\Services\Conversations\TraceEvents\AIConversationTraceEventsService;

/**
 * The conversation trace as the session worker writes it: every batch of the buffer and every single event is kept
 * instead of written, and so is every link of a call's events to a conversation.
 */
class RecordingAIConversationTraceEventsService extends AIConversationTraceEventsService
{
    /** @var array<int, AIConversationTraceEvent[]> Every batch written by the buffer flush, in order */
    public array $recordedBatches = [];

    /** @var AIConversationTraceEvent[] Every event written on its own (the transcript provenance), in order */
    public array $recordedSingleEvents = [];

    /** @var array<int, array{callId: int, conversationId: int}> */
    public array $linkedCallEvents = [];

    public function recordEvents(array $traceEvents): int
    {
        $this->recordedBatches[] = $traceEvents;

        return count($traceEvents);
    }

    public function recordEvent(AIConversationTraceEvent $traceEvent): bool
    {
        $this->recordedSingleEvents[] = $traceEvent;

        return true;
    }

    public function attachCallEventsToConversation(int $agentVoiceCallId, int $aiConversationId): void
    {
        $this->linkedCallEvents[] = ['callId' => $agentVoiceCallId, 'conversationId' => $aiConversationId];
    }

    /** @return AIConversationTraceEvent[] Every recorded event (batched and single) of one type, in order of writing */
    public function findRecordedEventsOfType(string $eventType): array
    {
        $recordedEvents = array_merge($this->recordedSingleEvents, ...$this->recordedBatches);

        return array_values(array_filter(
            $recordedEvents,
            static fn (AIConversationTraceEvent $recordedEvent): bool => $recordedEvent->eventType === $eventType
        ));
    }
}
